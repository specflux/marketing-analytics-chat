<?php
/**
 * Tests for the WooCommerce sales reader, platform, abilities, email section and widget row.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Abilities\Abilities_Registrar;
use Specflux_Marketing_Analytics\Abilities\Woo_Abilities;
use Specflux_Marketing_Analytics\Admin\Widget_Headline;
use Specflux_Marketing_Analytics\API_Clients\GA4_Client;
use Specflux_Marketing_Analytics\Commerce\Woo_Platform;
use Specflux_Marketing_Analytics\Commerce\Woo_Sales_Reader;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary_Email;

/**
 * Minimal stand-in for WC_Order.
 */
class Fake_Woo_Order {

	/**
	 * Constructor.
	 *
	 * @param string $date     Y-m-d H:i (UTC).
	 * @param float  $total    Order total.
	 * @param array  $meta     Attribution fields without the meta prefix.
	 * @param float  $refunded Refunded total.
	 * @param string $status   Status without wc- prefix.
	 * @param string $currency Currency.
	 */
	public function __construct( public string $date, public float $total, public array $meta = array(), public float $refunded = 0.0, public string $status = 'completed', public string $currency = 'USD' ) {}

	/**
	 * Total.
	 *
	 * @return float
	 */
	public function get_total() {
		return $this->total;
	}

	/**
	 * Refunded total.
	 *
	 * @return float
	 */
	public function get_total_refunded() {
		return $this->refunded;
	}

	/**
	 * Currency.
	 *
	 * @return string
	 */
	public function get_currency() {
		return $this->currency;
	}

	/**
	 * Meta lookup using WooCommerce's real _wc_order_attribution_ keys.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	public function get_meta( $key ) {
		return (string) ( $this->meta[ str_replace( '_wc_order_attribution_', '', $key ) ] ?? '' );
	}
}

/**
 * Reader fed from an in-memory order list instead of wc_get_orders().
 */
class Woo_Reader_Double extends Woo_Sales_Reader {

	/**
	 * Orders.
	 *
	 * @var Fake_Woo_Order[]
	 */
	public $orders = array();

	/**
	 * Whether WooCommerce counts as active.
	 *
	 * @var bool
	 */
	public $active = true;

	/**
	 * Page size override (0 keeps the argument).
	 *
	 * @var int
	 */
	public $page_size = 0;

	/**
	 * Recorded query argument lists.
	 *
	 * @var array[]
	 */
	public $calls = array();

	/**
	 * Active flag.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return $this->active;
	}

	/**
	 * Filter, paginate and return orders like wc_get_orders( paginate ).
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	protected function query_orders( array $args ): array {
		$this->calls[] = $args;
		list( $from, $to ) = array_map( 'intval', explode( '...', $args['date_created'] ) );

		$matches = array_values(
			array_filter(
				$this->orders,
				static function ( $order ) use ( $args, $from, $to ) {
					$ts = strtotime( $order->date . ' UTC' );
					return in_array( $order->status, $args['status'], true ) && $ts >= $from && $ts <= $to;
				}
			)
		);

		$size = $this->page_size ? $this->page_size : (int) $args['limit'];
		$page = (int) ( $args['page'] ?? 1 );

		return array(
			'orders'        => array_slice( $matches, ( $page - 1 ) * $size, $size ),
			'total'         => count( $matches ),
			'max_num_pages' => (int) max( 1, ceil( count( $matches ) / $size ) ),
		);
	}
}

/**
 * GA4 client with scripted reports; never calls Google.
 */
class Woo_Ga4_Double extends GA4_Client {

	/**
	 * Event rows for the funnel report.
	 *
	 * @var array
	 */
	public $events = array();

	/**
	 * Sessions value.
	 *
	 * @var int
	 */
	public $sessions = 0;

	/**
	 * Throw instead of answering.
	 *
	 * @var bool
	 */
	public $fail = false;

	/**
	 * No credentials.
	 */
	public function __construct() {}

	/**
	 * Scripted report.
	 *
	 * @param array  $metrics    Metrics.
	 * @param array  $dimensions Dimensions.
	 * @param string $date_range Range.
	 * @param array  $options    Options.
	 * @return array
	 * @throws \Exception When $fail is set.
	 */
	public function run_report( $metrics, $dimensions = array(), $date_range = '7daysAgo', $options = array() ) {
		if ( $this->fail ) {
			throw new \Exception( 'quota' );
		}
		if ( in_array( 'eventName', $dimensions, true ) ) {
			return array( 'rows' => $this->events );
		}
		return array( 'rows' => array( array( 'sessions' => (string) $this->sessions ) ) );
	}
}

/**
 * Tests.
 */
class WooSalesTest extends TestCase {

	const START = '2026-09-21';
	const END   = '2026-09-27';

	/**
	 * Reset shared state.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mock_transients'] = array();
		Abilities_Registrar::reset();
		Platform_Registry::reset();
	}

	/**
	 * Restore shared state.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		$GLOBALS['mock_transients'] = array();
		Abilities_Registrar::reset();
		Platform_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Build an order inside the current week.
	 *
	 * @param float  $total  Total.
	 * @param array  $meta   Attribution fields.
	 * @param string $date   Date.
	 * @param array  $extra  Extra constructor overrides: refunded, status, currency.
	 * @return Fake_Woo_Order
	 */
	private function order( float $total, array $meta = array(), string $date = '2026-09-23 10:00', array $extra = array() ): Fake_Woo_Order {
		return new Fake_Woo_Order( $date, $total, $meta, $extra['refunded'] ?? 0.0, $extra['status'] ?? 'completed', $extra['currency'] ?? 'USD' );
	}

	/**
	 * Reader holding the given orders.
	 *
	 * @param Fake_Woo_Order[] $orders Orders.
	 * @return Woo_Reader_Double
	 */
	private function reader( array $orders = array() ): Woo_Reader_Double {
		$reader         = new Woo_Reader_Double();
		$reader->orders = $orders;
		return $reader;
	}

	/**
	 * Channel mapping as WooCommerce records it.
	 *
	 * @return array[]
	 */
	public static function channel_cases(): array {
		return array(
			'gclid click (utm / google / cpc)' => array( 'utm', 'google', 'cpc', 'Paid Search' ),
			'bing ppc'                         => array( 'utm', 'bing', 'ppc', 'Paid Search' ),
			'facebook cpc'                     => array( 'utm', 'facebook', 'cpc', 'Paid Social' ),
			'instagram paid-social'            => array( 'utm', 'instagram', 'paid-social', 'Paid Social' ),
			'unknown source paid_social'       => array( 'utm', 'meta_ads', 'paid_social', 'Paid Social' ),
			'unknown source cpc'               => array( 'utm', 'somenetwork', 'cpc', 'Other' ),
			'display from google'              => array( 'utm', 'google', 'display', 'Paid Search' ),
			'utm email'                        => array( 'utm', 'mailchimp', 'email', 'Email' ),
			'utm newsletter'                   => array( 'utm', 'newsletter', '', 'Email' ),
			'utm social'                       => array( 'utm', 'linkedin', 'social', 'Organic Social' ),
			'utm google organic'               => array( 'utm', 'google', 'organic', 'Organic Search' ),
			'utm referral medium'              => array( 'utm', 'partner-blog', 'referral', 'Referral' ),
			'utm unknown'                      => array( 'utm', 'podcast', 'audio', 'Other' ),
			'sourcebuster organic'             => array( 'organic', 'google', 'organic', 'Organic Search' ),
			'referral social host'             => array( 'referral', 'm.facebook.com', 'referral', 'Organic Social' ),
			'referral t.co'                    => array( 'referral', 't.co', 'referral', 'Organic Social' ),
			'referral blog'                    => array( 'referral', 'someblog.com', 'referral', 'Referral' ),
			'typein'                           => array( 'typein', '(direct)', '(none)', 'Direct' ),
			'admin'                            => array( 'admin', '', '', 'Manual / admin' ),
			'pos'                              => array( 'pos', '', '', 'Manual / admin' ),
			'mobile app'                       => array( 'mobile_app', '', '', 'Other' ),
			'no meta'                          => array( '', '', '', 'Not tracked' ),
			'placeholders only'                => array( '', '(none)', '(none)', 'Not tracked' ),
		);
	}

	/**
	 * Channel mapping table.
	 *
	 * @dataProvider channel_cases
	 *
	 * @param string $type     Source type.
	 * @param string $source   utm_source.
	 * @param string $medium   utm_medium.
	 * @param string $expected Channel.
	 */
	public function test_channel_for( string $type, string $source, string $medium, string $expected ): void {
		$this->assertSame( $expected, ( new Woo_Sales_Reader() )->channel_for( $type, $source, $medium ) );
	}

	/**
	 * Only paid statuses count, refunds are subtracted and the query asks for the paid statuses.
	 */
	public function test_paid_statuses_and_refunds(): void {
		$reader = $this->reader(
			array(
				$this->order( 100.0, array( 'source_type' => 'typein' ) ),
				$this->order( 80.0, array( 'source_type' => 'typein' ), '2026-09-23 10:00', array( 'refunded' => 30.0 ) ),
				$this->order( 50.0, array( 'source_type' => 'typein' ), '2026-09-23 10:00', array( 'status' => 'failed' ) ),
				$this->order( 60.0, array( 'source_type' => 'typein' ), '2026-09-23 10:00', array( 'status' => 'cancelled' ) ),
				$this->order( 70.0, array( 'source_type' => 'typein' ), '2026-09-23 10:00', array( 'status' => 'processing' ) ),
			)
		);

		$result = $reader->get_sales_by_source( self::START, self::END, 'channel', 20, false );

		$this->assertSame( 3, $result['total_orders'] );
		$this->assertSame( 220.0, $result['total_revenue'] );
		$this->assertSame( array( 'processing', 'completed' ), $reader->calls[0]['status'] );
		$this->assertTrue( $reader->calls[0]['paginate'] );
		$this->assertSame( 'shop_order', $reader->calls[0]['type'] );
		$this->assertSame( 'USD', $result['currency'] );
	}

	/**
	 * Orders in another currency are counted but excluded from the sums.
	 */
	public function test_other_currency_excluded(): void {
		$reader = $this->reader(
			array(
				$this->order( 100.0, array( 'source_type' => 'typein' ) ),
				$this->order( 500.0, array( 'source_type' => 'typein' ), '2026-09-23 10:00', array( 'currency' => 'EUR' ) ),
			)
		);

		$result = $reader->get_sales_by_source( self::START, self::END, 'channel', 20, false );

		$this->assertSame( 1, $result['total_orders'] );
		$this->assertSame( 100.0, $result['total_revenue'] );
		$this->assertSame( 1, $result['other_currency_orders'] );
		$this->assertStringContainsString( 'other than the store currency', $result['note'] );
	}

	/**
	 * Untracked orders land in Not tracked and are reflected in tracked_orders and the note.
	 */
	public function test_untracked_orders(): void {
		$reader = $this->reader(
			array(
				$this->order( 100.0, array( 'source_type' => 'organic', 'utm_source' => 'google', 'utm_medium' => 'organic' ) ),
				$this->order( 100.0 ),
			)
		);

		$result = $reader->get_sales_by_source( self::START, self::END, 'channel', 20, false );

		$this->assertSame( 2, $result['total_orders'] );
		$this->assertSame( 1, $result['tracked_orders'] );
		$keys = array_column( $result['rows'], 'key' );
		$this->assertContains( 'Not tracked', $keys );
		$this->assertContains( 'Organic Search', $keys );
		$this->assertStringContainsString( '50% of orders', $result['note'] );
	}

	/**
	 * Pagination reads every page and truncates at the order cap.
	 */
	public function test_pagination_and_truncation(): void {
		$orders = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$orders[] = $this->order( 10.0, array( 'source_type' => 'typein' ) );
		}
		$reader            = $this->reader( $orders );
		$reader->page_size = 3;

		$result = $reader->get_sales_by_source( self::START, self::END, 'channel', 20, false );

		$this->assertSame( 7, $result['total_orders'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertCount( 3, $reader->calls );
		$this->assertSame( array( 1, 2, 3 ), array_column( $reader->calls, 'page' ) );

		// Past the cap: stop reading and flag it. 200 orders per page, 20,000 cap = 100 pages.
		$many = array();
		for ( $i = 0; $i < 20400; $i++ ) {
			$many[] = $this->order( 1.0, array( 'source_type' => 'typein' ) );
		}
		$GLOBALS['mock_transients'] = array();
		$big                        = $this->reader( $many );
		$big_result  = $big->get_sales_by_source( self::START, self::END, 'channel', 20, false );

		$this->assertTrue( $big_result['truncated'] );
		$this->assertSame( 20000, $big_result['total_orders'] );
		$this->assertCount( 100, $big->calls );
		$this->assertStringContainsString( 'Only the first 20000 orders', $big_result['note'] );
	}

	/**
	 * The previous period has the same length and feeds the change columns.
	 */
	public function test_previous_period_comparison(): void {
		$reader = $this->reader(
			array(
				$this->order( 150.0, array( 'source_type' => 'typein' ), '2026-09-22 09:00' ),
				$this->order( 100.0, array( 'source_type' => 'typein' ), '2026-09-15 09:00' ),
				$this->order( 40.0, array( 'source_type' => 'organic', 'utm_source' => 'google', 'utm_medium' => 'organic' ), '2026-09-16 09:00' ),
				$this->order( 5.0, array( 'source_type' => 'typein' ), '2026-09-13 23:59' ),
			)
		);

		$result = $reader->get_sales_by_source( self::START, self::END, 'channel', 20, true );
		$direct = $result['rows'][0];

		$this->assertSame( 'Direct', $direct['key'] );
		$this->assertSame( 150.0, $direct['revenue'] );
		$this->assertSame( 100.0, $direct['previous_revenue'] );
		$this->assertSame( 1, $direct['previous_orders'] );
		$this->assertSame( 50.0, $direct['revenue_change'] );
		$this->assertSame( 100.0, $direct['share'] );
		$this->assertSame( '2026-09-14', gmdate( 'Y-m-d', (int) explode( '...', $reader->calls[1]['date_created'] )[0] ) );
		$this->assertSame( '2026-09-20', gmdate( 'Y-m-d', (int) explode( '...', $reader->calls[1]['date_created'] )[1] ) );

		// A channel with no sales last period has no percentage change.
		$GLOBALS['mock_transients'] = array();
		$new                        = $this->reader( array( $this->order( 10.0, array( 'source_type' => 'typein' ) ) ) );
		$this->assertNull( $new->get_sales_by_source( self::START, self::END )['rows'][0]['revenue_change'] );
	}

	/**
	 * Each group_by produces the expected keys.
	 */
	public function test_group_by_variants(): void {
		$reader = $this->reader(
			array(
				$this->order(
					100.0,
					array(
						'source_type'   => 'utm',
						'utm_source'    => 'Google',
						'utm_medium'    => 'cpc',
						'utm_campaign'  => 'Spring_Sale',
						'device_type'   => 'Mobile',
						'session_entry' => 'https://example.com/shop/hat/?utm_source=google&gclid=abc',
					)
				),
				$this->order( 50.0, array( 'source_type' => 'typein', 'device_type' => 'Desktop', 'session_entry' => 'https://example.com/' ) ),
				$this->order( 20.0 ),
			)
		);

		$keys = static function ( $result ) {
			return array_column( $result['rows'], 'key' );
		};

		$this->assertSame( array( 'google', 'direct', '(not tracked)' ), $keys( $reader->get_sales_by_source( self::START, self::END, 'source', 20, false ) ) );
		$this->assertSame( array( 'google / cpc', 'direct / (none)', '(not tracked)' ), $keys( $reader->get_sales_by_source( self::START, self::END, 'source_medium', 20, false ) ) );
		$this->assertSame( array( 'Spring_Sale', '(no campaign)', '(not tracked)' ), $keys( $reader->get_sales_by_source( self::START, self::END, 'campaign', 20, false ) ) );
		$this->assertSame( array( 'Mobile', 'Desktop', 'Unknown' ), $keys( $reader->get_sales_by_source( self::START, self::END, 'device', 20, false ) ) );
		$landing = $reader->get_sales_by_source( self::START, self::END, 'landing_page', 20, false );
		$this->assertSame( array( '/shop/hat/', '/', '(unknown)' ), $keys( $landing ) );
		$this->assertStringNotContainsString( 'utm_source=google', wp_json_encode( $landing ), 'Query strings are dropped from landing pages.' );

		$invalid = $reader->get_sales_by_source( self::START, self::END, 'bogus', 20, false );
		$this->assertSame( 'channel', $invalid['group_by'] );

		$limited = $reader->get_sales_by_source( self::START, self::END, 'source', 1, false );
		$this->assertCount( 1, $limited['rows'] );
		$this->assertSame( 3, $limited['total_orders'], 'Totals cover every order, not just the capped rows.' );
	}

	/**
	 * Campaign rows keep the campaign value as stored and carry the dominant source and medium.
	 */
	public function test_campaign_rows_carry_source_and_medium(): void {
		$reader = $this->reader(
			array(
				$this->order( 100.0, array( 'source_type' => 'utm', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'Brand_US' ) ),
				$this->order( 30.0, array( 'source_type' => 'utm', 'utm_source' => 'facebook', 'utm_medium' => 'paid-social', 'utm_campaign' => 'Brand_US' ) ),
			)
		);

		$row = $reader->get_sales_by_source( self::START, self::END, 'campaign', 20, false )['rows'][0];

		$this->assertSame( 'Brand_US', $row['key'] );
		$this->assertSame( 'google', $row['source'] );
		$this->assertSame( 'cpc', $row['medium'] );
		$this->assertSame( 'Paid Search', $row['channel'] );

		$channel_row = $reader->get_sales_by_source( self::START, self::END, 'channel', 20, false )['rows'][0];
		$this->assertArrayNotHasKey( 'source', $channel_row );
	}

	/**
	 * Results are cached for 15 minutes.
	 */
	public function test_results_are_cached(): void {
		$reader = $this->reader( array( $this->order( 10.0, array( 'source_type' => 'typein' ) ) ) );

		$reader->get_sales_by_source( self::START, self::END, 'channel', 20, false );
		$calls = count( $reader->calls );
		$reader->get_sales_by_source( self::START, self::END, 'channel', 20, false );

		$this->assertSame( $calls, count( $reader->calls ) );
	}

	/**
	 * No personal data keys anywhere in the output.
	 */
	public function test_no_pii_in_output(): void {
		$reader = $this->reader(
			array(
				$this->order( 10.0, array( 'source_type' => 'typein', 'user_agent' => 'Mozilla/5.0 secret-agent', 'session_entry' => 'https://example.com/a?email=bob@example.com' ) ),
			)
		);

		foreach ( array_merge( Woo_Sales_Reader::GROUPS ) as $group ) {
			$json = wp_json_encode( $reader->get_sales_by_source( self::START, self::END, $group, 20, true ) );
			foreach ( array( 'email', 'name', 'address', 'ip', 'order_id', 'secret-agent', 'bob@' ) as $needle ) {
				if ( 'email' === $needle || 'name' === $needle || 'address' === $needle || 'ip' === $needle ) {
					$this->assertDoesNotMatchRegularExpression( '/"[a-z_]*' . $needle . '[a-z_]*":/', $json, $group . ' leaks a ' . $needle . ' key' );
				} else {
					$this->assertStringNotContainsString( $needle, $json, $group . ' leaks ' . $needle );
				}
			}
		}
	}

	/**
	 * Abilities are registered only with WooCommerce, and are read-only.
	 */
	public function test_abilities_registered_only_with_woocommerce(): void {
		$off         = $this->reader();
		$off->active = false;
		( new Woo_Abilities( $off ) )->register();
		$this->assertSame( array(), Abilities_Registrar::get_registered_tools() );

		( new Woo_Abilities( $this->reader() ) )->register();
		$tools = Abilities_Registrar::get_registered_tools();
		$this->assertArrayHasKey( 'marketing-analytics/get-sales-by-source', $tools );
		$this->assertArrayHasKey( 'marketing-analytics/get-store-funnel', $tools );
		foreach ( $tools as $config ) {
			$this->assertTrue( $config['meta']['annotations']['readonly'] );
		}
	}

	/**
	 * Without WooCommerce, the real static check is false and the platform stays out of the registry.
	 */
	public function test_platform_registers_only_with_woocommerce(): void {
		$this->assertFalse( Woo_Sales_Reader::is_available() );

		$active = new Woo_Platform( $this->reader() );
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = array( $active, 'register_platform' );
		Platform_Registry::reset();
		$definition = Platform_Registry::get( 'woocommerce' );
		$this->assertSame( 'none', $definition['auth'] );
		$this->assertStringContainsString( 'last-click', $definition['prompt_hint'] );
		$this->assertTrue( $active->test_connection()['success'] );

		$off         = $this->reader();
		$off->active = false;
		$inactive    = new Woo_Platform( $off );
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = array( $inactive, 'register_platform' );
		Platform_Registry::reset();
		$this->assertFalse( Platform_Registry::is_registered( 'woocommerce' ) );
		$this->assertFalse( $inactive->test_connection()['success'] );
	}

	/**
	 * Decode a tool result.
	 *
	 * @param array $response Ability_Response::tool output.
	 * @return array
	 */
	private function decode( array $response ): array {
		$this->assertArrayNotHasKey( 'isError', $response, $response['content'][0]['text'] );
		return json_decode( $response['content'][0]['text'], true );
	}

	/**
	 * Funnel built from GA4 shop events: omits stages GA4 did not report, labels counts as events.
	 */
	public function test_funnel_ga4_events_basis_without_zero_fill(): void {
		$ga4         = new Woo_Ga4_Double();
		$ga4->events = array(
			array( 'eventName' => 'view_item', 'eventCount' => '1000' ),
			array( 'eventName' => 'add_to_cart', 'eventCount' => '100' ),
			array( 'eventName' => 'purchase', 'eventCount' => '20' ),
		);
		$reader      = $this->reader( array( $this->order( 50.0, array( 'source_type' => 'typein' ) ) ) );
		$abilities   = new Woo_Abilities( $reader, $ga4, true );

		$funnel = $abilities->build_funnel( self::START, self::END );

		$this->assertSame( 'ga4_ecommerce_events', $funnel['basis'] );
		$this->assertSame( array( 'view_item', 'add_to_cart', 'purchase' ), array_column( $funnel['stages'], 'event' ) );
		$this->assertSame( 10.0, $funnel['stages'][1]['rate_from_previous_pct'] );
		$this->assertNull( $funnel['stages'][0]['rate_from_previous_pct'] );
		$this->assertStringContainsString( 'not people', $funnel['unit'] );
		$this->assertStringContainsString( 'begin_checkout', $funnel['note'] );
		$this->assertSame( 1, $funnel['paid_orders'] );
		$this->assertNotContains( 0, array_column( $funnel['stages'], 'events' ) );
	}

	/**
	 * GA4 connected without shop events falls back to sessions and orders.
	 */
	public function test_funnel_sessions_to_orders_basis(): void {
		$ga4           = new Woo_Ga4_Double();
		$ga4->sessions = 400;
		$reader        = $this->reader( array_fill( 0, 8, $this->order( 25.0, array( 'source_type' => 'typein' ) ) ) );

		$funnel = ( new Woo_Abilities( $reader, $ga4, true ) )->build_funnel( self::START, self::END );

		$this->assertSame( 'sessions_to_orders', $funnel['basis'] );
		$this->assertSame( 400, $funnel['sessions'] );
		$this->assertSame( 2.0, $funnel['conversion_rate_pct'] );
		$this->assertArrayNotHasKey( 'stages', $funnel );
		$this->assertStringContainsString( 'isn\'t receiving shop events', $funnel['note'] );
	}

	/**
	 * Zero sessions gives no conversion rate rather than a made-up one.
	 */
	public function test_funnel_zero_sessions_has_no_rate(): void {
		$funnel = ( new Woo_Abilities( $this->reader(), new Woo_Ga4_Double(), true ) )->build_funnel( self::START, self::END );

		$this->assertSame( 'sessions_to_orders', $funnel['basis'] );
		$this->assertArrayNotHasKey( 'conversion_rate_pct', $funnel );
	}

	/**
	 * Without GA4 the funnel is orders only, with failed and cancelled orders and AOV.
	 */
	public function test_funnel_orders_only_basis(): void {
		$reader = $this->reader(
			array(
				$this->order( 100.0, array( 'source_type' => 'typein' ) ),
				$this->order( 50.0, array( 'source_type' => 'typein' ) ),
				$this->order( 20.0, array(), '2026-09-23 10:00', array( 'status' => 'failed' ) ),
				$this->order( 20.0, array(), '2026-09-24 10:00', array( 'status' => 'cancelled' ) ),
			)
		);

		$funnel = ( new Woo_Abilities( $reader, null, false ) )->build_funnel( self::START, self::END );

		$this->assertSame( 'orders_only', $funnel['basis'] );
		$this->assertSame( 2, $funnel['paid_orders'] );
		$this->assertSame( 2, $funnel['failed_or_cancelled_orders'] );
		$this->assertSame( 75.0, $funnel['average_order_value'] );
		$this->assertArrayNotHasKey( 'sessions', $funnel );
		$this->assertArrayNotHasKey( 'stages', $funnel );

		$GLOBALS['mock_transients'] = array();
		$empty                      = ( new Woo_Abilities( $this->reader(), null, false ) )->build_funnel( self::START, self::END );
		$this->assertNull( $empty['average_order_value'] );
	}

	/**
	 * A GA4 failure degrades to orders only and says why.
	 */
	public function test_funnel_ga4_failure_degrades(): void {
		$ga4       = new Woo_Ga4_Double();
		$ga4->fail = true;

		$funnel = ( new Woo_Abilities( $this->reader(), $ga4, true ) )->build_funnel( self::START, self::END );

		$this->assertSame( 'orders_only', $funnel['basis'] );
		$this->assertStringContainsString( 'GA4 could not be read', $funnel['note'] );
	}

	/**
	 * The ability callbacks validate dates and return tool responses.
	 */
	public function test_ability_callbacks(): void {
		$reader    = $this->reader( array( $this->order( 10.0, array( 'source_type' => 'typein' ) ) ) );
		$abilities = new Woo_Abilities( $reader, null, false );

		$sales = $this->decode( $abilities->execute_get_sales_by_source( array( 'start_date' => self::START, 'end_date' => self::END, 'group_by' => 'source', 'limit' => 5 ) ) );
		$this->assertSame( 'source', $sales['group_by'] );
		$this->assertSame( 1, $sales['total_orders'] );

		$bad = $abilities->execute_get_sales_by_source( array( 'start_date' => '2026-13-45' ) );
		$this->assertTrue( $bad['isError'] );

		$reversed = $abilities->execute_get_store_funnel( array( 'start_date' => self::END, 'end_date' => self::START ) );
		$this->assertTrue( $reversed['isError'] );
	}

	/**
	 * Weekly summary collects sales only when there are paid orders; the email shows the section.
	 */
	public function test_weekly_sales_section(): void {
		$reader  = $this->reader(
			array(
				$this->order( 300.0, array( 'source_type' => 'organic', 'utm_source' => 'google', 'utm_medium' => 'organic' ), '2026-09-23 10:00' ),
				$this->order( 100.0, array( 'source_type' => 'typein' ), '2026-09-24 10:00' ),
				$this->order( 200.0, array( 'source_type' => 'typein' ), '2026-09-16 10:00' ),
			)
		);
		$summary = new Weekly_Summary( array(), array(), null, $reader );
		$range   = array(
			'current'  => array( self::START, self::END ),
			'previous' => array( '2026-09-14', '2026-09-20' ),
		);

		$sales = $summary->collect_sales( $range );

		$this->assertSame( 400.0, $sales['revenue'] );
		$this->assertSame( 200.0, $sales['previous_revenue'] );
		$this->assertSame( 100.0, $sales['change'] );
		$this->assertSame( 'Organic Search', $sales['channels'][0]['label'] );
		$this->assertSame( 75.0, $sales['channels'][0]['share'] );

		$email    = new Weekly_Summary_Email();
		$sections = $email->build_sections(
			array(
				'platforms' => array(),
				'sales'     => array(
					'status' => 'ok',
					'data'   => $sales,
				),
			)
		);
		$this->assertSame( 'sales', $sections[0]['id'] );
		$this->assertSame( 'Sales by channel', $sections[0]['title'] );
		$this->assertStringContainsString( 'Organic Search', $sections[0]['html'] );
		$this->assertStringContainsString( '400.00 USD', $sections[0]['html'] );

		$this->assertSame( array(), $email->build_sections( array( 'platforms' => array() ) ), 'No sales key, no section.' );

		$GLOBALS['mock_transients'] = array();
		$empty_week                 = new Weekly_Summary( array(), array(), null, $this->reader() );
		$this->assertNull( $empty_week->collect_sales( $range ), 'No paid orders, no section.' );

		$off         = $this->reader();
		$off->active = false;
		$this->assertNull( ( new Weekly_Summary( array(), array(), null, $off ) )->collect_sales( $range ) );
	}

	/**
	 * Widget row renders only from supplied data and is empty for the "none" marker.
	 */
	public function test_widget_sales_row(): void {
		$html = Widget_Headline::render_sales(
			array(
				'currency' => 'USD',
				'revenue'  => 400.0,
				'change'   => 100.0,
				'channels' => array(
					array(
						'label'   => 'Organic Search',
						'revenue' => 300.0,
						'share'   => 75.0,
					),
				),
			)
		);

		$this->assertStringContainsString( 'Sales: 400.00 USD', $html );
		$this->assertStringContainsString( 'Organic Search', $html );
		$this->assertStringContainsString( 'is-up', $html );
		$this->assertSame( '', Widget_Headline::render_sales( 'none' ) );
		$this->assertSame( '', Widget_Headline::render_sales( null ) );

		$this->assertNull( Widget_Headline::cached_sales() );
		set_transient( Widget_Headline::SALES_TRANSIENT, 'none', 60 );
		$this->assertSame( 'none', Widget_Headline::cached_sales() );
	}
}
