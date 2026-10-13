<?php
/**
 * Easy Digital Downloads sales reader
 *
 * Answers "where did my sales come from?" for an EDD 3.x store from the
 * attribution Specflux records on each order (`_specflux_mac_attribution`).
 * Aggregates only: customer names, emails, IPs, addresses and order IDs are
 * never selected or returned.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Commerce;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Groups paid Easy Digital Downloads orders by traffic source.
 */
class Edd_Sales_Reader {

	const GROUPS = array( 'channel', 'source', 'source_medium', 'campaign', 'source_campaign', 'landing_page' );

	/**
	 * Order meta key written by the attribution recorder.
	 */
	const META_KEY = '_specflux_mac_attribution';

	const PAGE_SIZE   = 200;
	const MAX_ORDERS  = 20000;
	const CACHE_TTL   = 900;
	const TOP_ITEMS   = 10;
	const ID_CHUNK    = 100;
	const ITEM_NUMBER = 5000;

	/**
	 * Channel mapper.
	 *
	 * @var Woo_Sales_Reader
	 */
	private $channels;

	/**
	 * Constructor.
	 *
	 * @param Woo_Sales_Reader|null $channels Optional channel mapper.
	 */
	public function __construct( $channels = null ) {
		$this->channels = $channels ? $channels : new Woo_Sales_Reader();
	}

	/**
	 * Whether EDD 3.x order functions are available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'edd_get_orders' ) && function_exists( 'edd_get_order_items' ) && function_exists( 'edd_get_order_meta' )
			&& ( ! defined( 'EDD_VERSION' ) || version_compare( (string) EDD_VERSION, '3.0', '>=' ) );
	}

	/**
	 * Instance wrapper around is_available() so callers and tests can override it.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return self::is_available();
	}

	/**
	 * Paid orders grouped by traffic source, per currency, with the previous period.
	 *
	 * @param string $start_date Y-m-d, inclusive.
	 * @param string $end_date   Y-m-d, inclusive.
	 * @param string $group_by   One of GROUPS.
	 * @param int    $limit      Most rows returned per currency.
	 * @param bool   $compare    Also read the previous period of the same length.
	 * @return array
	 */
	public function get_sales_by_source( string $start_date, string $end_date, string $group_by = 'channel', int $limit = 20, bool $compare = true ): array {
		$group_by = in_array( $group_by, self::GROUPS, true ) ? $group_by : 'channel';
		$limit    = max( 1, min( 100, $limit ) );

		$cache_key = 'specflux_mac_edd_sales_' . md5( wp_json_encode( array( $start_date, $end_date, $group_by, $limit, $compare ) ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$current  = $this->aggregate( $start_date, $end_date, $group_by );
		$previous = null;
		if ( $compare ) {
			list( $prev_start, $prev_end ) = $this->previous_period( $start_date, $end_date );
			$previous                      = $this->aggregate( $prev_start, $prev_end, $group_by );
		}

		$currencies = array();
		foreach ( $current['currencies'] as $code => $bucket ) {
			$prev_bucket  = $previous['currencies'][ $code ] ?? null;
			$currencies[] = $this->build_currency( $code, $bucket, $prev_bucket, $limit, $group_by );
		}
		usort(
			$currencies,
			static function ( $a, $b ) {
				return $b['total_revenue'] <=> $a['total_revenue'];
			}
		);

		$truncated = $current['truncated'] || ( null !== $previous && $previous['truncated'] );
		$primary   = $currencies[0] ?? array(
			'currency'       => $this->default_currency(),
			'total_orders'   => 0,
			'total_revenue'  => 0.0,
			'tracked_orders' => 0,
			'rows'           => array(),
			'top_downloads'  => array(),
		);

		$result = array_merge(
			array(
				'store'      => 'easy-digital-downloads',
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'group_by'   => $group_by,
			),
			$primary,
			array(
				'currencies' => $currencies,
				'truncated'  => $truncated,
				'note'       => $this->build_note( $currencies, $truncated ),
			)
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Finished rows for one currency.
	 *
	 * @param string     $code        Currency code.
	 * @param array      $bucket      Current aggregate.
	 * @param array|null $prev_bucket Previous aggregate.
	 * @param int        $limit       Row limit.
	 * @param string     $group_by    Grouping.
	 * @return array
	 */
	private function build_currency( string $code, array $bucket, ?array $prev_bucket, int $limit, string $group_by ): array {
		$total = $bucket['revenue'];
		$rows  = array();

		foreach ( $bucket['groups'] as $key => $group ) {
			$prev_orders  = 0;
			$prev_revenue = 0.0;
			if ( null !== $prev_bucket && isset( $prev_bucket['groups'][ $key ] ) ) {
				$prev_orders  = $prev_bucket['groups'][ $key ]['orders'];
				$prev_revenue = round( $prev_bucket['groups'][ $key ]['revenue'], 2 );
			}

			$row = array(
				'key'              => (string) $key,
				'label'            => $group['label'],
				'channel'          => 'channel' === $group_by ? $group['label'] : $this->dominant( $group['channels'] ),
				'orders'           => $group['orders'],
				'revenue'          => round( $group['revenue'], 2 ),
				'share'            => $total > 0 ? round( $group['revenue'] / $total * 100, 1 ) : 0.0,
				'previous_orders'  => $prev_orders,
				'previous_revenue' => $prev_revenue,
				'revenue_change'   => $prev_revenue > 0 ? round( ( $group['revenue'] - $prev_revenue ) / $prev_revenue * 100, 1 ) : null,
			);
			if ( 'campaign' === $group_by || 'source_campaign' === $group_by ) {
				$row['source'] = $this->dominant( $group['sources'] );
				$row['medium'] = $this->dominant( $group['mediums'] );
			}
			$rows[] = $row;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);

		$downloads = array_values( $bucket['downloads'] );
		usort(
			$downloads,
			static function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);
		$downloads = array_map(
			static function ( $d ) {
				return array(
					'name'    => $d['name'],
					'units'   => $d['units'],
					'revenue' => round( $d['revenue'], 2 ),
				);
			},
			array_slice( $downloads, 0, self::TOP_ITEMS )
		);

		return array(
			'currency'       => $code,
			'total_orders'   => $bucket['orders'],
			'total_revenue'  => round( $total, 2 ),
			'tracked_orders' => $bucket['tracked'],
			'rows'           => array_slice( $rows, 0, $limit ),
			'top_downloads'  => $downloads,
		);
	}

	/**
	 * Read and group every paid live-mode order in a period.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @param string $group_by   Grouping.
	 * @return array{currencies:array,truncated:bool}
	 */
	private function aggregate( string $start_date, string $end_date, string $group_by ): array {
		$out    = array(
			'currencies' => array(),
			'truncated'  => false,
		);
		$seen   = 0;
		$offset = 0;

		do {
			$orders = $this->query_orders(
				array(
					'type'               => 'sale',
					'mode'               => 'live',
					'status__in'         => $this->paid_statuses(),
					'date_created_query' => $this->date_query( $start_date, $end_date ),
					'fields'             => array( 'id', 'total', 'currency' ),
					'number'             => self::PAGE_SIZE,
					'offset'             => $offset,
					'orderby'            => 'id',
					'order'              => 'ASC',
				)
			);
			$orders = array_values( $orders );
			if ( empty( $orders ) ) {
				break;
			}

			$ids     = array_map(
				static function ( $o ) {
					return (int) $o->id;
				},
				$orders
			);
			$refunds = $this->refunds_for( $ids );
			$items   = $this->items_for( array_merge( $ids, $refunds['ids'] ) );

			foreach ( $orders as $order ) {
				++$seen;
				$id = (int) $order->id;
				$this->add_order( $out, $order, $refunds['sum'][ $id ] ?? 0.0, $items, $refunds['by_parent'][ $id ] ?? array(), $group_by );
			}

			$offset += self::PAGE_SIZE;
			$more    = count( $orders ) >= self::PAGE_SIZE;
			if ( $more && $seen >= self::MAX_ORDERS ) {
				$out['truncated'] = true;
				break;
			}
		} while ( $more );

		return $out;
	}

	/**
	 * Fold one order into the per-currency aggregate.
	 *
	 * @param array  $out         Aggregate (by reference).
	 * @param object $order       Order with id, total, currency.
	 * @param float  $refunded    Refund total (negative or zero).
	 * @param array  $items       Order items keyed by order id.
	 * @param int[]  $refund_ids  Refund order ids for this order.
	 * @param string $group_by    Grouping.
	 * @return void
	 */
	private function add_order( array &$out, $order, float $refunded, array $items, array $refund_ids, string $group_by ): void {
		$code    = strtoupper( trim( (string) $order->currency ) );
		$code    = '' === $code ? $this->default_currency() : $code;
		$revenue = max( 0.0, (float) $order->total + $refunded );

		if ( ! isset( $out['currencies'][ $code ] ) ) {
			$out['currencies'][ $code ] = array(
				'groups'    => array(),
				'downloads' => array(),
				'orders'    => 0,
				'revenue'   => 0.0,
				'tracked'   => 0,
			);
		}
		$bucket = &$out['currencies'][ $code ];

		$attr    = $this->order_attribution( (int) $order->id );
		$attr    = is_array( $attr ) ? $attr : array();
		$type    = (string) ( $attr['source_type'] ?? '' );
		$source  = (string) ( $attr['source'] ?? '' );
		$medium  = (string) ( $attr['medium'] ?? '' );
		$channel = $this->channels->channel_for( $type, $source, $medium );
		$tracked = Woo_Sales_Reader::CHANNEL_NOT_TRACKED !== $channel;

		list( $key, $label ) = $this->group_key( $group_by, $attr, $tracked, $channel, $type, $source, $medium );

		if ( ! isset( $bucket['groups'][ $key ] ) ) {
			$bucket['groups'][ $key ] = array(
				'label'    => $label,
				'orders'   => 0,
				'revenue'  => 0.0,
				'channels' => array(),
				'sources'  => array(),
				'mediums'  => array(),
			);
		}
		$group             = &$bucket['groups'][ $key ];
		$group['orders']  += 1;
		$group['revenue'] += $revenue;
		$group['channels'][ $channel ] = ( $group['channels'][ $channel ] ?? 0.0 ) + $revenue;
		$src                           = '' === trim( $source ) ? '(none)' : strtolower( trim( $source ) );
		$mdm                           = '' === trim( $medium ) ? '(none)' : strtolower( trim( $medium ) );
		$group['sources'][ $src ]      = ( $group['sources'][ $src ] ?? 0.0 ) + $revenue;
		$group['mediums'][ $mdm ]      = ( $group['mediums'][ $mdm ] ?? 0.0 ) + $revenue;
		unset( $group );

		$bucket['orders']  += 1;
		$bucket['revenue'] += $revenue;
		if ( $tracked ) {
			$bucket['tracked'] += 1;
		}

		foreach ( array_merge( array( (int) $order->id ), $refund_ids ) as $oid ) {
			foreach ( $items[ $oid ] ?? array() as $item ) {
				$pid = (int) $item->product_id;
				if ( ! isset( $bucket['downloads'][ $pid ] ) ) {
					$bucket['downloads'][ $pid ] = array(
						'name'    => (string) $item->product_name,
						'units'   => 0,
						'revenue' => 0.0,
					);
				}
				$bucket['downloads'][ $pid ]['units']   += (int) $item->quantity;
				$bucket['downloads'][ $pid ]['revenue'] += (float) $item->total;
			}
		}
		unset( $bucket );
	}

	/**
	 * Group key and label for one order.
	 *
	 * @param string $group_by Grouping.
	 * @param array  $attr     Attribution meta.
	 * @param bool   $tracked  Whether the order has attribution.
	 * @param string $channel  Channel.
	 * @param string $type     Source type.
	 * @param string $source   Source.
	 * @param string $medium   Medium.
	 * @return array{0:string,1:string}
	 */
	private function group_key( string $group_by, array $attr, bool $tracked, string $channel, string $type, string $source, string $medium ): array {
		if ( 'channel' === $group_by ) {
			return array( $channel, $channel );
		}

		if ( 'landing_page' === $group_by ) {
			$entry = trim( (string) ( $attr['landing_page'] ?? '' ) );
			if ( '' === $entry ) {
				return array( '(unknown)', 'Unknown' );
			}
			$path = wp_parse_url( $entry, PHP_URL_PATH );
			$path = is_string( $path ) && '' !== $path ? $path : '/';
			return array( $path, $path );
		}

		if ( ! $tracked ) {
			return array( '(not tracked)', 'Not tracked' );
		}

		$src = strtolower( trim( $source ) );
		$mdm = strtolower( trim( $medium ) );
		if ( '' === $src ) {
			$src = 'typein' === strtolower( trim( $type ) ) ? 'direct' : '(none)';
		}
		$campaign = trim( (string) ( $attr['campaign'] ?? '' ) );
		$campaign = '(none)' === $campaign ? '' : $campaign;

		if ( 'campaign' === $group_by ) {
			return '' === $campaign ? array( '(no campaign)', 'No campaign' ) : array( $campaign, $campaign );
		}
		if ( 'source_campaign' === $group_by ) {
			$pair = $src . ' / ' . ( '' === $campaign ? '(no campaign)' : $campaign );
			return array( $pair, $pair );
		}
		if ( 'source' === $group_by ) {
			return array( $src, ucfirst( $src ) );
		}

		$pair = $src . ' / ' . ( '' === $mdm ? '(none)' : $mdm );

		return array( $pair, $pair );
	}

	/**
	 * Refund rows (negative totals) for a set of parent orders.
	 *
	 * @param int[] $ids Parent order ids.
	 * @return array{sum:array,ids:int[],by_parent:array}
	 */
	private function refunds_for( array $ids ): array {
		$out = array(
			'sum'       => array(),
			'ids'       => array(),
			'by_parent' => array(),
		);

		foreach ( array_chunk( $ids, self::ID_CHUNK ) as $chunk ) {
			$rows = $this->query_orders(
				array(
					'type'       => 'refund',
					'parent__in' => $chunk,
					'fields'     => array( 'id', 'parent', 'total' ),
					'number'     => self::ITEM_NUMBER,
				)
			);
			foreach ( $rows as $row ) {
				$parent                         = (int) $row->parent;
				$out['sum'][ $parent ]          = ( $out['sum'][ $parent ] ?? 0.0 ) + (float) $row->total;
				$out['ids'][]                   = (int) $row->id;
				$out['by_parent'][ $parent ][]  = (int) $row->id;
			}
		}

		return $out;
	}

	/**
	 * Order items (product, quantity, total only) grouped by order id.
	 *
	 * @param int[] $ids Order ids, refunds included.
	 * @return array
	 */
	private function items_for( array $ids ): array {
		$out = array();

		foreach ( array_chunk( $ids, self::ID_CHUNK ) as $chunk ) {
			$rows = $this->query_items(
				array(
					'order_id__in' => $chunk,
					'fields'       => array( 'order_id', 'product_id', 'product_name', 'quantity', 'total' ),
					'number'       => self::ITEM_NUMBER,
				)
			);
			foreach ( $rows as $row ) {
				$out[ (int) $row->order_id ][] = $row;
			}
		}

		return $out;
	}

	/**
	 * Key with the highest summed revenue.
	 *
	 * @param array $totals Key => revenue.
	 * @return string
	 */
	private function dominant( array $totals ): string {
		if ( empty( $totals ) ) {
			return '';
		}
		arsort( $totals );

		return (string) array_key_first( $totals );
	}

	/**
	 * Plain-language caveats.
	 *
	 * @param array $currencies Finished per-currency results.
	 * @param bool  $truncated  Whether a period was cut at the cap.
	 * @return string
	 */
	private function build_note( array $currencies, bool $truncated ): string {
		$notes = array( 'Source: Easy Digital Downloads. Attribution is last-click as recorded by this plugin on each order, not Google Analytics attribution. Paid live-mode orders only (test orders skipped); refunds are subtracted.' );

		$orders  = 0;
		$tracked = 0;
		foreach ( $currencies as $c ) {
			$orders  += $c['total_orders'];
			$tracked += $c['tracked_orders'];
		}
		if ( $orders > $tracked ) {
			$notes[] = sprintf(
				'%1$d%% of orders (%2$d of %3$d) have no attribution data (placed before tracking, or tracking off) and show as Not tracked.',
				(int) round( ( $orders - $tracked ) / $orders * 100 ),
				$orders - $tracked,
				$orders
			);
		}
		if ( count( $currencies ) > 1 ) {
			$notes[] = 'Several currencies were sold in; each is reported separately in "currencies" and is never added together. Top-level figures are for the currency with the most revenue.';
		}
		if ( $truncated ) {
			$notes[] = sprintf( 'Only the first %d orders of a period were read, so totals are incomplete. Use a shorter period.', self::MAX_ORDERS );
		}

		return implode( ' ', $notes );
	}

	/**
	 * The period of the same length immediately before.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @return array{0:string,1:string}
	 */
	private function previous_period( string $start_date, string $end_date ): array {
		$days     = (int) round( ( strtotime( $end_date ) - strtotime( $start_date ) ) / DAY_IN_SECONDS ) + 1;
		$prev_end = gmdate( 'Y-m-d', strtotime( $start_date . ' -1 day' ) );
		$prev_beg = gmdate( 'Y-m-d', strtotime( $prev_end . ' -' . ( max( 1, $days ) - 1 ) . ' days' ) );

		return array( $prev_beg, $prev_end );
	}

	/**
	 * Inclusive UTC date query for the site-time-zone days (EDD stores UTC).
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @return array
	 */
	private function date_query( string $start_date, string $end_date ): array {
		$zone  = wp_timezone();
		$utc   = new \DateTimeZone( 'UTC' );
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, $zone );
		$end   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date, $zone );

		return array(
			'after'     => $start ? $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) : $start_date . ' 00:00:00',
			'before'    => $end ? $end->setTime( 23, 59, 59 )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) : $end_date . ' 23:59:59',
			'inclusive' => true,
		);
	}

	/**
	 * Run one edd_get_orders() query.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	protected function query_orders( array $args ): array {
		return (array) edd_get_orders( $args );
	}

	/**
	 * Run one edd_get_order_items() query.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	protected function query_items( array $args ): array {
		return (array) edd_get_order_items( $args );
	}

	/**
	 * Attribution meta of one order.
	 *
	 * @param int $order_id Order id.
	 * @return mixed
	 */
	protected function order_attribution( int $order_id ) {
		return edd_get_order_meta( $order_id, self::META_KEY, true );
	}

	/**
	 * Statuses that count as net sales.
	 *
	 * @return string[]
	 */
	protected function paid_statuses(): array {
		return function_exists( 'edd_get_net_order_statuses' ) ? array_values( (array) edd_get_net_order_statuses() ) : array( 'complete', 'partially_refunded', 'revoked', 'on_hold' );
	}

	/**
	 * Store currency code.
	 *
	 * @return string
	 */
	protected function default_currency(): string {
		return function_exists( 'edd_get_currency' ) ? (string) edd_get_currency() : 'USD';
	}
}
