<?php
/**
 * WooCommerce Abilities
 *
 * Answers "where did my sales come from?" and "where do shoppers drop off?"
 * from the store's own orders, joined with GA4 when it is connected. Only
 * aggregates are returned, never customer or order details.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

use Specflux_Marketing_Analytics\API_Clients\GA4_Client;
use Specflux_Marketing_Analytics\Commerce\Edd_Sales_Reader;
use Specflux_Marketing_Analytics\Commerce\Woo_Sales_Reader;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the WooCommerce sales abilities.
 */
class Woo_Abilities {

	/**
	 * Default period length in days.
	 */
	const DEFAULT_DAYS = 28;

	/**
	 * GA4 ecommerce events, in funnel order.
	 */
	const FUNNEL_EVENTS = array(
		'view_item'      => 'Product views',
		'add_to_cart'    => 'Added to cart',
		'begin_checkout' => 'Started checkout',
		'purchase'       => 'Purchases',
	);

	/**
	 * Sales reader.
	 *
	 * @var Woo_Sales_Reader
	 */
	private $reader;

	/**
	 * Easy Digital Downloads sales reader.
	 *
	 * @var Edd_Sales_Reader
	 */
	private $edd;

	/**
	 * GA4 client, or null to build one on demand.
	 *
	 * @var GA4_Client|null
	 */
	private $ga4;

	/**
	 * Whether GA4 is connected, or null to read stored credentials.
	 *
	 * @var bool|null
	 */
	private $ga4_connected;

	/**
	 * Constructor.
	 *
	 * @param Woo_Sales_Reader|null $reader        Optional reader.
	 * @param GA4_Client|null       $ga4           Optional GA4 client.
	 * @param bool|null             $ga4_connected Optional connection override.
	 * @param Edd_Sales_Reader|null $edd           Optional Easy Digital Downloads reader.
	 */
	public function __construct( $reader = null, $ga4 = null, $ga4_connected = null, $edd = null ) {
		$this->reader        = $reader ? $reader : new Woo_Sales_Reader();
		$this->edd           = $edd ? $edd : new Edd_Sales_Reader();
		$this->ga4           = $ga4;
		$this->ga4_connected = $ga4_connected;
	}

	/**
	 * Register the abilities.
	 */
	public function register() {
		$has_woo = $this->reader->available();
		$has_edd = $this->edd->available();

		// Nothing to read without a store, so don't advertise the tools.
		if ( ! $has_woo && ! $has_edd ) {
			return;
		}

		$dates = array(
			'start_date' => array(
				'type'        => 'string',
				'description' => 'Period start, YYYY-MM-DD (inclusive). Default: 27 days before end_date.',
			),
			'end_date'   => array(
				'type'        => 'string',
				'description' => 'Period end, YYYY-MM-DD (inclusive). Default: today.',
			),
			'date_range' => array(
				'type'        => 'string',
				'description' => 'Relative alternative such as "7daysAgo" or "30daysAgo" (ending today), ignored when start_date or end_date is given. Default 28 days.',
			),
		);

		wp_register_ability(
			'marketing-analytics/get-sales-by-source',
			$this->definition(
				__( 'Get Sales by Source', 'specflux-marketing-analytics-chat' ),
				__( 'Where did this store\'s sales come from? Works for WooCommerce and Easy Digital Downloads (the answer names the store it came from in the "store" field; with both active pass store). Paid orders and revenue (refunds subtracted; Easy Digital Downloads also skips test orders, lists top downloads and keeps each currency separate) grouped by channel (Organic Search, Paid Search, Paid Social, Organic Social, Email, Referral, Direct, Manual / admin, Other, Not tracked), source, source / medium, campaign, source and campaign together (so the same campaign name on two networks stays separate), device or landing page, with share of revenue and the change versus the previous period of the same length. Campaign rows also name the dominant source and medium; source and campaign rows name the source, dominant medium and campaign. Uses the last-click order attribution recorded at checkout (WooCommerce\'s own, or this plugin\'s for Easy Digital Downloads), so orders placed before attribution was on show as Not tracked. Only totals are returned, never customer or order details. Use it for "where did my sales come from", "which campaign made money" or "is email or search better for revenue". Default period is the last 28 days.', 'specflux-marketing-analytics-chat' ),
				array_merge(
					$dates,
					array(
						'group_by' => array(
							'type'        => 'string',
							'description' => 'How to group the sales.',
							'enum'        => Woo_Sales_Reader::GROUPS,
							'default'     => 'channel',
						),
						'store'    => array(
							'type'        => 'string',
							'description' => 'Which store to read when both are active. Default: woocommerce. (device grouping is WooCommerce only.)',
							'enum'        => array( 'woocommerce', 'easy-digital-downloads' ),
						),
						'limit'    => array(
							'type'        => 'integer',
							'description' => 'Most rows to return (1 to 100). Totals always cover every order.',
							'default'     => 20,
						),
					)
				),
				'execute_get_sales_by_source'
			)
		);

		if ( ! $has_woo ) {
			return;
		}

		wp_register_ability(
			'marketing-analytics/get-store-funnel',
			$this->definition(
				__( 'Get Store Funnel', 'specflux-marketing-analytics-chat' ),
				__( 'How shoppers move toward a purchase. With GA4 receiving shop events it returns product views, added to cart, started checkout and purchases (as GA4 event counts, not people) next to WooCommerce paid orders. With GA4 connected but no shop events it returns sessions to paid orders and the conversion rate. Without GA4 it returns paid orders, failed and cancelled orders and average order value. Stages that have no data are left out and explained in the note, never shown as 0. Use it for "what is my conversion rate" or "where do shoppers drop off". Default period is the last 28 days.', 'specflux-marketing-analytics-chat' ),
				$dates,
				'execute_get_store_funnel'
			)
		);
	}

	/**
	 * Shared registration arguments.
	 *
	 * @param string $label       Label.
	 * @param string $description Description.
	 * @param array  $properties  Input schema properties.
	 * @param string $callback    Execute method name.
	 * @return array
	 */
	private function definition( $label, $description, array $properties, $callback ) {
		return array(
			'label'               => $label,
			'description'         => $description,
			'category'            => 'marketing-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => $properties,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'start_date' => array( 'type' => 'string' ),
					'end_date'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => array( $this, $callback ),
			'permission_callback' => array( Abilities_Registrar::class, 'can_access' ),
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		);
	}

	/**
	 * Execute get-sales-by-source.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_sales_by_source( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args = (array) $args;
				list( $start, $end ) = $this->resolve_period( $args );

				if ( $this->use_edd( $args ) ) {
					return $this->edd->get_sales_by_source(
						$start,
						$end,
						isset( $args['group_by'] ) ? (string) $args['group_by'] : 'channel',
						isset( $args['limit'] ) ? (int) $args['limit'] : 20,
						true
					);
				}

				$result          = $this->reader->get_sales_by_source(
					$start,
					$end,
					isset( $args['group_by'] ) ? (string) $args['group_by'] : 'channel',
					isset( $args['limit'] ) ? (int) $args['limit'] : 20,
					true
				);
				$result['store'] = 'woocommerce';

				return $result;
			}
		);
	}

	/**
	 * Whether to answer from Easy Digital Downloads: it is the only store, or was asked for.
	 *
	 * @param array $args Tool arguments.
	 * @return bool
	 */
	private function use_edd( array $args ) {
		if ( ! $this->edd->available() ) {
			return false;
		}

		return ! $this->reader->available() || ( isset( $args['store'] ) && 'easy-digital-downloads' === $args['store'] );
	}

	/**
	 * Execute get-store-funnel.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_store_funnel( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				list( $start, $end ) = $this->resolve_period( (array) $args );

				return $this->build_funnel( $start, $end );
			}
		);
	}

	/**
	 * Build the funnel for a period, choosing the richest basis available.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array
	 */
	public function build_funnel( $start, $end ) {
		$sales  = $this->reader->get_sales_by_source( $start, $end, 'channel', 1, false );
		$orders = (int) $sales['total_orders'];
		$result = array(
			'start_date'      => $start,
			'end_date'        => $end,
			'currency'        => $sales['currency'],
			'paid_orders'     => $orders,
			'revenue'         => $sales['total_revenue'],
			'failed_or_cancelled_orders' => $this->reader->count_orders( $start, $end, array( 'failed', 'cancelled' ) ),
			'average_order_value'        => $orders > 0 ? round( $sales['total_revenue'] / $orders, 2 ) : null,
		);
		$notes  = array();

		if ( ! $this->is_ga4_connected() ) {
			$result['basis'] = 'orders_only';
			$notes[]         = 'GA4 is not connected, so there are no session or shop-event counts. Connect Google Analytics 4 to see sessions to orders and the conversion rate.';

			return $this->finish( $result, $notes );
		}

		$range = $start . ',' . $end;

		try {
			$events = $this->ga4_event_counts( $range );
		} catch ( \Throwable $e ) {
			$events = null;
			$notes[] = 'GA4 could not be read just now (' . $e->getMessage() . '), so only WooCommerce orders are shown.';
		}

		if ( null === $events ) {
			$result['basis'] = 'orders_only';

			return $this->finish( $result, $notes );
		}

		if ( $events ) {
			$result['basis']  = 'ga4_ecommerce_events';
			$result['unit']   = 'GA4 event counts (not people)';
			$result['stages'] = $this->build_stages( $events );
			$missing          = array_diff( array_keys( self::FUNNEL_EVENTS ), array_keys( $events ) );
			if ( $missing ) {
				$notes[] = 'GA4 has not received these shop events in this period, so they are left out rather than shown as 0: ' . implode( ', ', $missing ) . '.';
			}
			$notes[] = 'GA4 counts events, and can differ from WooCommerce paid orders because of consent, ad blockers and tracking set-up.';

			return $this->finish( $result, $notes );
		}

		try {
			$sessions = $this->ga4_sessions( $range );
		} catch ( \Throwable $e ) {
			$sessions = null;
			$notes[]  = 'GA4 sessions could not be read just now (' . $e->getMessage() . ').';
		}

		if ( null === $sessions ) {
			$result['basis'] = 'orders_only';

			return $this->finish( $result, $notes );
		}

		$result['basis']    = 'sessions_to_orders';
		$result['sessions'] = $sessions;
		if ( $sessions > 0 ) {
			$result['conversion_rate_pct'] = round( $orders / $sessions * 100, 2 );
		}
		$notes[] = 'GA4 is connected but isn\'t receiving shop events (view_item, add_to_cart, begin_checkout, purchase); install a GA4 ecommerce integration to see product view and add to cart stages.';
		$notes[] = 'Conversion rate is WooCommerce paid orders divided by GA4 sessions.';

		return $this->finish( $result, $notes );
	}

	/**
	 * Attach the note and return.
	 *
	 * @param array    $result Result.
	 * @param string[] $notes  Notes.
	 * @return array
	 */
	private function finish( array $result, array $notes ) {
		$result['note'] = implode( ' ', $notes );

		return $result;
	}

	/**
	 * Turn event counts into ordered stages with step-to-step rates.
	 *
	 * @param array $events Event name => count, only for events GA4 reported.
	 * @return array[]
	 */
	private function build_stages( array $events ) {
		$stages   = array();
		$previous = null;

		foreach ( self::FUNNEL_EVENTS as $event => $label ) {
			if ( ! isset( $events[ $event ] ) ) {
				continue;
			}
			$count    = (int) $events[ $event ];
			$stages[] = array(
				'stage'                => $label,
				'event'                => $event,
				'events'               => $count,
				'rate_from_previous_pct' => ( null !== $previous && $previous > 0 ) ? round( $count / $previous * 100, 1 ) : null,
			);
			$previous = $count;
		}

		return $stages;
	}

	/**
	 * GA4 ecommerce event counts for a period.
	 *
	 * @param string $range "start,end".
	 * @return array Event name => count, only events with a positive count.
	 */
	private function ga4_event_counts( $range ) {
		$report = $this->ga4_client()->run_report(
			array( 'eventCount' ),
			array( 'eventName' ),
			$range,
			array(
				'limit'            => 20,
				'dimension_filter' => array(
					'filter' => array(
						'fieldName'    => 'eventName',
						'inListFilter' => array( 'values' => array_keys( self::FUNNEL_EVENTS ) ),
					),
				),
			)
		);

		$counts = array();
		foreach ( (array) ( $report['rows'] ?? array() ) as $row ) {
			$name  = (string) ( $row['eventName'] ?? '' );
			$count = (int) ( $row['eventCount'] ?? 0 );
			if ( isset( self::FUNNEL_EVENTS[ $name ] ) && $count > 0 ) {
				$counts[ $name ] = $count;
			}
		}

		return $counts;
	}

	/**
	 * GA4 session total for a period.
	 *
	 * @param string $range "start,end".
	 * @return int
	 */
	private function ga4_sessions( $range ) {
		$report = $this->ga4_client()->run_report( array( 'sessions' ), array(), $range );

		if ( isset( $report['rows'][0]['sessions'] ) ) {
			return (int) $report['rows'][0]['sessions'];
		}

		return (int) ( $report['totals']['sessions'] ?? 0 );
	}

	/**
	 * GA4 client.
	 *
	 * @return GA4_Client
	 */
	private function ga4_client() {
		if ( null === $this->ga4 ) {
			$this->ga4 = new GA4_Client();
		}

		return $this->ga4;
	}

	/**
	 * Whether GA4 credentials are stored.
	 *
	 * @return bool
	 */
	private function is_ga4_connected() {
		if ( null !== $this->ga4_connected ) {
			return $this->ga4_connected;
		}

		return ( new Credential_Manager() )->has_credentials( 'ga4' );
	}

	/**
	 * Resolve the period from start_date, end_date or a relative date_range.
	 *
	 * @param array $args Tool arguments.
	 * @return array{0:string,1:string}
	 * @throws \Exception When a date is malformed or start is after end.
	 */
	private function resolve_period( array $args ) {
		foreach ( array( 'start_date', 'end_date' ) as $key ) {
			if ( ! empty( $args[ $key ] ) && ! self::is_date( $args[ $key ] ) ) {
				throw new \Exception( esc_html( $key . ' must be a valid date in Y-m-d format.' ) );
			}
		}

		$days = self::DEFAULT_DAYS;
		if ( empty( $args['start_date'] ) && empty( $args['end_date'] ) && ! empty( $args['date_range'] ) && preg_match( '/^(\d{1,4})daysago$/i', (string) $args['date_range'], $m ) ) {
			$days = max( 1, (int) $m[1] );
		}

		$end   = ! empty( $args['end_date'] ) ? $args['end_date'] : gmdate( 'Y-m-d' );
		$start = ! empty( $args['start_date'] ) ? $args['start_date'] : gmdate( 'Y-m-d', strtotime( $end . ' -' . ( $days - 1 ) . ' days' ) );

		if ( $start > $end ) {
			throw new \Exception( 'start_date must not be after end_date.' );
		}

		return array( $start, $end );
	}

	/**
	 * Strictly validate a Y-m-d date.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_date( $value ) {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value );

		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}
}
