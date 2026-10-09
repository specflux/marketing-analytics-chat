<?php
/**
 * WooCommerce sales reader
 *
 * Answers "where did my sales come from?" from WooCommerce's own order
 * attribution meta (last-click, recorded at checkout). Aggregates only: no
 * customer names, emails, addresses, IPs or order IDs are ever returned.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Commerce;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Groups paid WooCommerce orders by traffic source.
 */
class Woo_Sales_Reader {

	const CHANNEL_ORGANIC_SEARCH = 'Organic Search';
	const CHANNEL_PAID_SEARCH    = 'Paid Search';
	const CHANNEL_PAID_SOCIAL    = 'Paid Social';
	const CHANNEL_ORGANIC_SOCIAL = 'Organic Social';
	const CHANNEL_EMAIL          = 'Email';
	const CHANNEL_REFERRAL       = 'Referral';
	const CHANNEL_DIRECT         = 'Direct';
	const CHANNEL_MANUAL         = 'Manual / admin';
	const CHANNEL_OTHER          = 'Other';
	const CHANNEL_NOT_TRACKED    = 'Not tracked';

	const GROUPS = array( 'channel', 'source', 'source_medium', 'campaign', 'source_campaign', 'device', 'landing_page' );

	/**
	 * Orders requested per query page.
	 */
	const PAGE_SIZE = 200;

	/**
	 * Most orders read per period before the result is marked truncated.
	 */
	const MAX_ORDERS = 20000;

	/**
	 * Cache lifetime in seconds.
	 */
	const CACHE_TTL = 900;

	const SEARCH_ENGINES = array( 'google', 'googleads', 'google_ads', 'adwords', 'bing', 'msn', 'yahoo', 'duckduckgo', 'baidu', 'yandex', 'ecosia', 'ask', 'aol', 'brave', 'startpage', 'qwant', 'naver', 'seznam', 'rambler' );

	const SOCIAL_NETWORKS = array( 'facebook', 'fb', 'instagram', 'ig', 'twitter', 't', 'x', 'linkedin', 'lnkd', 'pinterest', 'tiktok', 'youtube', 'reddit', 'snapchat', 'whatsapp', 'threads', 'mastodon', 'tumblr', 'meta' );

	const PAID_MEDIUMS = array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'paid-social', 'paid_social', 'paidsocial', 'display', 'cpm' );

	const PAID_SEARCH_MEDIUMS = array( 'paidsearch', 'paid_search', 'paid-search' );

	const PAID_SOCIAL_MEDIUMS = array( 'paid-social', 'paid_social', 'paidsocial' );

	const EMAIL_MEDIUMS = array( 'email', 'e-mail', 'newsletter', 'e_mail' );

	const SOCIAL_MEDIUMS = array( 'social', 'social-network', 'social-media', 'social_media', 'social network', 'sm', 'smm' );

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'wc_get_orders' );
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
	 * Paid orders grouped by traffic source, with the previous period for comparison.
	 *
	 * @param string $start_date Y-m-d, inclusive.
	 * @param string $end_date   Y-m-d, inclusive.
	 * @param string $group_by   channel|source|source_medium|campaign|device|landing_page.
	 * @param int    $limit      Most rows returned (totals still cover everything).
	 * @param bool   $compare    Also read the previous period of the same length.
	 * @return array
	 */
	public function get_sales_by_source( string $start_date, string $end_date, string $group_by = 'channel', int $limit = 20, bool $compare = true ): array {
		$group_by = in_array( $group_by, self::GROUPS, true ) ? $group_by : 'channel';
		$limit    = max( 1, min( 100, $limit ) );

		$cache_key = 'specflux_mac_woo_sales_' . md5( wp_json_encode( array( $start_date, $end_date, $group_by, $limit, $compare ) ) );
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

		$total_revenue = $current['revenue'];
		$rows          = array();

		foreach ( $current['groups'] as $key => $group ) {
			$prev_orders  = 0;
			$prev_revenue = 0.0;
			if ( null !== $previous && isset( $previous['groups'][ $key ] ) ) {
				$prev_orders  = $previous['groups'][ $key ]['orders'];
				$prev_revenue = round( $previous['groups'][ $key ]['revenue'], 2 );
			}

			$channel = 'channel' === $group_by ? $group['label'] : $this->dominant( $group['channels'] );
			$row     = array(
				'key'              => (string) $key,
				'label'            => $group['label'],
				'channel'          => $channel,
				'orders'           => $group['orders'],
				'revenue'          => round( $group['revenue'], 2 ),
				'share'            => $total_revenue > 0 ? round( $group['revenue'] / $total_revenue * 100, 1 ) : 0.0,
				'previous_orders'  => $prev_orders,
				'previous_revenue' => $prev_revenue,
				'revenue_change'   => $prev_revenue > 0 ? round( ( $group['revenue'] - $prev_revenue ) / $prev_revenue * 100, 1 ) : null,
			);

			if ( 'campaign' === $group_by ) {
				$row['source'] = $this->dominant( $group['sources'] );
				$row['medium'] = $this->dominant( $group['mediums'] );
			} elseif ( 'source_campaign' === $group_by ) {
				$row['source']   = (string) $group['pair_source'];
				$row['medium']   = $this->dominant( $group['mediums'] );
				$row['campaign'] = (string) $group['pair_campaign'];
			}

			$rows[] = $row;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['revenue'] <=> $a['revenue'];
			}
		);
		$rows = array_slice( $rows, 0, $limit );

		$truncated = $current['truncated'] || ( null !== $previous && $previous['truncated'] );

		$result = array(
			'start_date'            => $start_date,
			'end_date'              => $end_date,
			'group_by'              => $group_by,
			'currency'              => $this->store_currency(),
			'total_orders'          => $current['orders'],
			'total_revenue'         => round( $total_revenue, 2 ),
			'tracked_orders'        => $current['tracked'],
			'rows'                  => $rows,
			'other_currency_orders' => $current['other_currency'],
			'truncated'             => $truncated,
			'note'                  => $this->build_note( $current, $truncated ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Count orders in the given statuses (used for failed and cancelled orders).
	 *
	 * @param string   $start_date Y-m-d, inclusive.
	 * @param string   $end_date   Y-m-d, inclusive.
	 * @param string[] $statuses   Order statuses without the wc- prefix.
	 * @return int
	 */
	public function count_orders( string $start_date, string $end_date, array $statuses ): int {
		$result = $this->query_orders(
			array(
				'type'         => 'shop_order',
				'paginate'     => true,
				'limit'        => 1,
				'return'       => 'ids',
				'status'       => $statuses,
				'date_created' => $this->date_range_arg( $start_date, $end_date ),
			)
		);

		return (int) $result['total'];
	}

	/**
	 * Map WooCommerce's source type, utm_source and utm_medium to a channel.
	 *
	 * Source types WooCommerce records: utm, organic, referral, typein, admin,
	 * mobile_app and pos. A gclid click is stored as utm / google / cpc.
	 *
	 * @param string $source_type Value of the source_type attribution meta.
	 * @param string $utm_source  Value of the utm_source attribution meta.
	 * @param string $utm_medium  Value of the utm_medium attribution meta.
	 * @return string One of the CHANNEL_* labels.
	 */
	public function channel_for( string $source_type, string $utm_source, string $utm_medium ): string {
		$type   = $this->clean( $source_type );
		$source = $this->clean( $utm_source );
		$medium = $this->clean( $utm_medium );

		if ( '' === $type && '' === $source && '' === $medium ) {
			return self::CHANNEL_NOT_TRACKED;
		}

		switch ( $type ) {
			case 'admin':
			case 'pos':
				return self::CHANNEL_MANUAL;
			case 'typein':
				return self::CHANNEL_DIRECT;
			case 'organic':
				return self::CHANNEL_ORGANIC_SEARCH;
			case 'mobile_app':
				return self::CHANNEL_OTHER;
			case 'referral':
				if ( in_array( $medium, self::EMAIL_MEDIUMS, true ) ) {
					return self::CHANNEL_EMAIL;
				}
				return $this->in_list( $source, self::SOCIAL_NETWORKS ) ? self::CHANNEL_ORGANIC_SOCIAL : self::CHANNEL_REFERRAL;
		}

		return $this->channel_from_utm( $source, $medium );
	}

	/**
	 * Channel for a utm (or unknown) source type.
	 *
	 * @param string $source Cleaned utm_source.
	 * @param string $medium Cleaned utm_medium.
	 * @return string
	 */
	private function channel_from_utm( string $source, string $medium ): string {
		$is_search = $this->in_list( $source, self::SEARCH_ENGINES );
		$is_social = $this->in_list( $source, self::SOCIAL_NETWORKS );

		if ( in_array( $medium, self::PAID_MEDIUMS, true ) ) {
			if ( in_array( $medium, self::PAID_SEARCH_MEDIUMS, true ) || $is_search ) {
				return self::CHANNEL_PAID_SEARCH;
			}
			if ( in_array( $medium, self::PAID_SOCIAL_MEDIUMS, true ) || $is_social ) {
				return self::CHANNEL_PAID_SOCIAL;
			}
			return self::CHANNEL_OTHER;
		}

		if ( in_array( $medium, self::EMAIL_MEDIUMS, true ) || in_array( $source, array( 'email', 'e-mail', 'newsletter' ), true ) ) {
			return self::CHANNEL_EMAIL;
		}

		if ( 'organic' === $medium ) {
			if ( $is_social ) {
				return self::CHANNEL_ORGANIC_SOCIAL;
			}
			return ( $is_search || '' === $source ) ? self::CHANNEL_ORGANIC_SEARCH : self::CHANNEL_OTHER;
		}

		if ( in_array( $medium, self::SOCIAL_MEDIUMS, true ) ) {
			return self::CHANNEL_ORGANIC_SOCIAL;
		}

		if ( 'referral' === $medium ) {
			return $is_social ? self::CHANNEL_ORGANIC_SOCIAL : self::CHANNEL_REFERRAL;
		}

		if ( '' === $medium ) {
			if ( $is_social ) {
				return self::CHANNEL_ORGANIC_SOCIAL;
			}
			if ( '' === $source || 'direct' === $source ) {
				return self::CHANNEL_DIRECT;
			}
		}

		return self::CHANNEL_OTHER;
	}

	/**
	 * Lowercase, trim and blank out WooCommerce's placeholder values.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function clean( $value ): string {
		$value = strtolower( trim( (string) $value ) );

		if ( '(direct)' === $value ) {
			return 'direct';
		}

		return in_array( $value, array( '(none)', '(not set)' ), true ) ? '' : $value;
	}

	/**
	 * Whether a source or host names one of the listed networks.
	 *
	 * Matches any dot-separated label, so m.facebook.com and www.google.com match.
	 *
	 * @param string   $source Cleaned source or host.
	 * @param string[] $names  Names.
	 * @return bool
	 */
	private function in_list( string $source, array $names ): bool {
		if ( '' === $source ) {
			return false;
		}
		if ( in_array( $source, $names, true ) ) {
			return true;
		}
		foreach ( explode( '.', $source ) as $label ) {
			if ( in_array( $label, $names, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read and group every paid order in a period.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @param string $group_by   Grouping.
	 * @return array
	 */
	private function aggregate( string $start_date, string $end_date, string $group_by ): array {
		$out = array(
			'groups'         => array(),
			'orders'         => 0,
			'revenue'        => 0.0,
			'tracked'        => 0,
			'other_currency' => 0,
			'truncated'      => false,
		);

		$currency = $this->store_currency();
		$seen     = 0;
		$page     = 1;

		do {
			$result = $this->query_orders(
				array(
					'type'         => 'shop_order',
					'paginate'     => true,
					'limit'        => self::PAGE_SIZE,
					'page'         => $page,
					'status'       => $this->paid_statuses(),
					'date_created' => $this->date_range_arg( $start_date, $end_date ),
					'orderby'      => 'date',
					'order'        => 'ASC',
				)
			);

			foreach ( $result['orders'] as $order ) {
				++$seen;
				$this->add_order( $out, $order, $group_by, $currency );
			}

			$more = $page < $result['max_num_pages'] && ! empty( $result['orders'] );
			if ( $more && $seen >= self::MAX_ORDERS ) {
				$out['truncated'] = true;
				break;
			}
			++$page;
		} while ( $more );

		return $out;
	}

	/**
	 * Fold one order into the aggregate.
	 *
	 * @param array  $out      Aggregate (by reference).
	 * @param object $order    WooCommerce order.
	 * @param string $group_by Grouping.
	 * @param string $currency Store currency.
	 * @return void
	 */
	private function add_order( array &$out, $order, string $group_by, string $currency ): void {
		// Refund objects can appear in order queries; they are not sales.
		if ( ! method_exists( $order, 'get_total_refunded' ) ) {
			return;
		}

		if ( (string) $order->get_currency() !== $currency ) {
			++$out['other_currency'];
			return;
		}

		$revenue = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );

		$type    = (string) $order->get_meta( $this->meta_key( 'source_type' ) );
		$source  = (string) $order->get_meta( $this->meta_key( 'utm_source' ) );
		$medium  = (string) $order->get_meta( $this->meta_key( 'utm_medium' ) );
		$channel = $this->channel_for( $type, $source, $medium );
		$tracked = self::CHANNEL_NOT_TRACKED !== $channel;

		list( $key, $label ) = $this->group_key( $group_by, $order, $tracked, $channel, $type, $source, $medium );

		if ( ! isset( $out['groups'][ $key ] ) ) {
			$out['groups'][ $key ] = array(
				'label'    => $label,
				'orders'   => 0,
				'revenue'  => 0.0,
				'channels' => array(),
				'sources'  => array(),
				'mediums'  => array(),
			);
			if ( 'source_campaign' === $group_by ) {
				list( $out['groups'][ $key ]['pair_source'], $out['groups'][ $key ]['pair_campaign'] ) = $this->pair_parts( $order, $tracked, $source, $type );
			}
		}

		$group             = &$out['groups'][ $key ];
		$group['orders']  += 1;
		$group['revenue'] += $revenue;
		$group['channels'][ $channel ] = ( $group['channels'][ $channel ] ?? 0.0 ) + $revenue;
		if ( 'campaign' === $group_by || 'source_campaign' === $group_by ) {
			$src = '' === $this->clean( $source ) ? '(none)' : $this->clean( $source );
			$mdm = '' === $this->clean( $medium ) ? '(none)' : $this->clean( $medium );

			$group['sources'][ $src ] = ( $group['sources'][ $src ] ?? 0.0 ) + $revenue;
			$group['mediums'][ $mdm ] = ( $group['mediums'][ $mdm ] ?? 0.0 ) + $revenue;
		}
		unset( $group );

		$out['orders']  += 1;
		$out['revenue'] += $revenue;
		if ( $tracked ) {
			$out['tracked'] += 1;
		}
	}

	/**
	 * Group key and display label for one order.
	 *
	 * @param string $group_by Grouping.
	 * @param object $order    Order.
	 * @param bool   $tracked  Whether the order has attribution data.
	 * @param string $channel  Channel.
	 * @param string $type     Raw source type.
	 * @param string $source   Raw utm_source.
	 * @param string $medium   Raw utm_medium.
	 * @return array{0:string,1:string}
	 */
	private function group_key( string $group_by, $order, bool $tracked, string $channel, string $type, string $source, string $medium ): array {
		if ( 'channel' === $group_by ) {
			return array( $channel, $channel );
		}

		if ( 'device' === $group_by ) {
			$device = trim( (string) $order->get_meta( $this->meta_key( 'device_type' ) ) );
			$device = '' === $device ? 'Unknown' : $device;
			return array( $device, $device );
		}

		if ( 'landing_page' === $group_by ) {
			return $this->landing_key( (string) $order->get_meta( $this->meta_key( 'session_entry' ) ) );
		}

		if ( ! $tracked ) {
			return array( '(not tracked)', 'Not tracked' );
		}

		$src = $this->clean( $source );
		$mdm = $this->clean( $medium );

		if ( 'source_campaign' === $group_by ) {
			list( $pair_source, $pair_campaign ) = $this->pair_parts( $order, true, $source, $type );
			$pair                                = $pair_source . ' / ' . ( '' === $pair_campaign ? '(no campaign)' : $pair_campaign );
			return array( $pair, $pair );
		}

		if ( 'campaign' === $group_by ) {
			$campaign = trim( (string) $order->get_meta( $this->meta_key( 'utm_campaign' ) ) );
			return '' === $campaign || '(none)' === $campaign ? array( '(no campaign)', 'No campaign' ) : array( $campaign, $campaign );
		}

		if ( '' === $src ) {
			$src = 'typein' === strtolower( trim( $type ) ) ? 'direct' : '(none)';
		}

		if ( 'source' === $group_by ) {
			return array( $src, ucfirst( $src ) );
		}

		$pair = $src . ' / ' . ( '' === $mdm ? '(none)' : $mdm );

		return array( $pair, $pair );
	}

	/**
	 * Source and exact campaign of an order for the source_campaign grouping.
	 *
	 * @param object $order   Order.
	 * @param bool   $tracked Whether the order has attribution data.
	 * @param string $source  Raw utm_source.
	 * @param string $type    Raw source type.
	 * @return array{0:string,1:string} Source (lowercased) and campaign ('' when none or untracked).
	 */
	private function pair_parts( $order, bool $tracked, string $source, string $type ): array {
		if ( ! $tracked ) {
			return array( '(none)', '' );
		}

		$src = $this->clean( $source );
		if ( '' === $src ) {
			$src = 'typein' === strtolower( trim( $type ) ) ? 'direct' : '(none)';
		}

		$campaign = trim( (string) $order->get_meta( $this->meta_key( 'utm_campaign' ) ) );

		return array( $src, '(none)' === $campaign ? '' : $campaign );
	}

	/**
	 * Landing page key (path only, no query string or host).
	 *
	 * @param string $entry Stored session_entry URL.
	 * @return array{0:string,1:string}
	 */
	private function landing_key( string $entry ): array {
		$entry = trim( $entry );
		if ( '' === $entry ) {
			return array( '(unknown)', 'Unknown' );
		}

		$path = wp_parse_url( $entry, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';

		return array( $path, $path );
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
	 * Plain-language caveats for the answer.
	 *
	 * @param array $current   Aggregate for the period.
	 * @param bool  $truncated Whether any period was cut at the order cap.
	 * @return string
	 */
	private function build_note( array $current, bool $truncated ): string {
		$notes = array( 'Attribution is last-click as recorded by WooCommerce\'s own order attribution, not Google Analytics attribution. Ad clicks count as paid only when the link carries UTM tags or a Google gclid; a Facebook fbclid alone is not read.' );

		$untracked = $current['orders'] - $current['tracked'];
		if ( $untracked > 0 && $current['orders'] > 0 ) {
			$notes[] = sprintf(
				'%1$d%% of orders (%2$d of %3$d) have no attribution data (older orders, or order attribution is switched off) and show as Not tracked.',
				(int) round( $untracked / $current['orders'] * 100 ),
				$untracked,
				$current['orders']
			);
		}

		if ( $current['other_currency'] > 0 ) {
			$notes[] = sprintf( '%d order(s) in a currency other than the store currency are left out of the sums.', $current['other_currency'] );
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
	 * Inclusive "start...end" timestamp range in the site time zone.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @return string
	 */
	private function date_range_arg( string $start_date, string $end_date ): string {
		$zone  = wp_timezone();
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, $zone );
		$end   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date, $zone );

		$from = $start ? $start->getTimestamp() : 0;
		$to   = $end ? $end->setTime( 23, 59, 59 )->getTimestamp() : time();

		return $from . '...' . $to;
	}

	/**
	 * Order meta key for one of WooCommerce's attribution fields.
	 *
	 * WooCommerce stores them as _wc_order_attribution_{field}; the prefix is
	 * filterable on its side, so the same filter is applied here.
	 *
	 * @param string $field Field name such as utm_source.
	 * @return string
	 */
	private function meta_key( string $field ): string {
		$hook   = 'wc_order_attribution_tracking_field_prefix';
		$prefix = trim( (string) apply_filters( $hook, 'wc_order_attribution_' ), '_' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- WooCommerce's own filter.

		return '_' . $prefix . '_' . $field;
	}

	/**
	 * Run one WooCommerce order query and normalise the paginated result.
	 *
	 * @param array $args wc_get_orders() arguments.
	 * @return array{orders:array,total:int,max_num_pages:int}
	 */
	protected function query_orders( array $args ): array {
		$result = wc_get_orders( $args );

		if ( is_object( $result ) && isset( $result->orders ) ) {
			return array(
				'orders'        => (array) $result->orders,
				'total'         => (int) ( $result->total ?? 0 ),
				'max_num_pages' => (int) ( $result->max_num_pages ?? 1 ),
			);
		}

		return array(
			'orders'        => is_array( $result ) ? $result : array(),
			'total'         => is_array( $result ) ? count( $result ) : 0,
			'max_num_pages' => 1,
		);
	}

	/**
	 * Statuses that count as paid.
	 *
	 * @return string[]
	 */
	protected function paid_statuses(): array {
		return function_exists( 'wc_get_is_paid_statuses' ) ? array_values( (array) wc_get_is_paid_statuses() ) : array( 'processing', 'completed' );
	}

	/**
	 * Store currency code.
	 *
	 * @return string
	 */
	protected function store_currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';
	}
}
