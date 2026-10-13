<?php
/**
 * Tests for the Easy Digital Downloads sales reader, platform, ability and weekly row.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Abilities\Abilities_Registrar;
use Specflux_Marketing_Analytics\Abilities\Woo_Abilities;
use Specflux_Marketing_Analytics\Commerce\Edd_Platform;
use Specflux_Marketing_Analytics\Commerce\Edd_Sales_Reader;
use Specflux_Marketing_Analytics\Commerce\Woo_Sales_Reader;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary;

/**
 * Reader fed from in-memory rows instead of edd_get_orders() and friends.
 */
class Edd_Reader_Double extends Edd_Sales_Reader {

	/**
	 * Order rows (sale and refund), including personal fields that must never be read.
	 *
	 * @var array[]
	 */
	public $rows = array();

	/**
	 * Item rows.
	 *
	 * @var array[]
	 */
	public $items = array();

	/**
	 * Attribution meta by order id.
	 *
	 * @var array
	 */
	public $meta = array();

	/**
	 * Whether EDD counts as active.
	 *
	 * @var bool
	 */
	public $active = true;

	/**
	 * Recorded query arguments.
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
	 * Mimic edd_get_orders() including the `fields` projection.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	protected function query_orders( array $args ): array {
		$this->calls[] = $args;
		$out           = array();
		foreach ( $this->rows as $row ) {
			if ( $row['type'] !== $args['type'] ) {
				continue;
			}
			if ( isset( $args['mode'] ) && $row['mode'] !== $args['mode'] ) {
				continue;
			}
			if ( isset( $args['status__in'] ) && ! in_array( $row['status'], $args['status__in'], true ) ) {
				continue;
			}
			if ( isset( $args['parent__in'] ) && ! in_array( $row['parent'], $args['parent__in'], true ) ) {
				continue;
			}
			if ( isset( $args['date_created_query'] ) && ( $row['date'] < $args['date_created_query']['after'] || $row['date'] > $args['date_created_query']['before'] ) ) {
				continue;
			}
			$out[] = (object) array_intersect_key( $row, array_flip( $args['fields'] ) );
		}

		return array_slice( $out, (int) ( $args['offset'] ?? 0 ), (int) $args['number'] );
	}

	/**
	 * Mimic edd_get_order_items().
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	protected function query_items( array $args ): array {
		$this->calls[] = $args;
		$out           = array();
		foreach ( $this->items as $item ) {
			if ( in_array( $item['order_id'], $args['order_id__in'], true ) ) {
				$out[] = (object) array_intersect_key( $item, array_flip( $args['fields'] ) );
			}
		}

		return $out;
	}

	/**
	 * Attribution meta.
	 *
	 * @param int $order_id Order id.
	 * @return mixed
	 */
	protected function order_attribution( int $order_id ) {
		return $this->meta[ $order_id ] ?? '';
	}
}

/**
 * Easy Digital Downloads sales tests.
 */
class EddSalesTest extends TestCase {

	const START = '2026-09-21';
	const END   = '2026-09-27';

	/**
	 * Reset registered tools and transients.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mock_transients'] = array();
		Abilities_Registrar::reset();
	}

	/**
	 * Restore shared state.
	 */
	protected function tearDown(): void {
		$GLOBALS['mock_transients'] = array();
		Abilities_Registrar::reset();
		parent::tearDown();
	}

	/**
	 * A sale row.
	 *
	 * @param int    $id       Id.
	 * @param float  $total    Total.
	 * @param string $currency Currency.
	 * @param string $date     UTC datetime.
	 * @param string $mode     live|test.
	 * @param string $status   Status.
	 * @return array
	 */
	private function sale( int $id, float $total, string $currency = 'USD', string $date = '2026-09-23 10:00:00', string $mode = 'live', string $status = 'complete' ): array {
		return array(
			'id'       => $id,
			'type'     => 'sale',
			'parent'   => 0,
			'total'    => $total,
			'currency' => $currency,
			'mode'     => $mode,
			'status'   => $status,
			'date'     => $date,
			'email'    => 'bob@example.com',
			'ip'       => '203.0.113.9',
			'name'     => 'Bob Buyer',
		);
	}

	/**
	 * Fresh double.
	 *
	 * @param array $rows  Order rows.
	 * @param array $meta  Meta by id.
	 * @param array $items Item rows.
	 * @return Edd_Reader_Double
	 */
	private function reader( array $rows = array(), array $meta = array(), array $items = array() ): Edd_Reader_Double {
		$reader        = new Edd_Reader_Double();
		$reader->rows  = $rows;
		$reader->meta  = $meta;
		$reader->items = $items;

		return $reader;
	}

	/**
	 * EDD missing: unavailable, no platform, no ability.
	 */
	public function test_edd_missing(): void {
		$this->assertFalse( Edd_Sales_Reader::is_available() );

		$reader         = $this->reader();
		$reader->active = false;
		$this->assertSame( array(), ( new Edd_Platform( $reader ) )->register_platform( array() ) );
		$this->assertFalse( ( new Edd_Platform( $reader ) )->test_connection()['success'] );

		( new Woo_Abilities( null, null, null, $reader ) )->register();
		$this->assertSame( array(), Abilities_Registrar::get_registered_tools() );
	}

	/**
	 * Platform and read-only ability when EDD is present.
	 */
	public function test_platform_and_ability_with_edd(): void {
		$reader    = $this->reader();
		$platforms = ( new Edd_Platform( $reader ) )->register_platform( array() );
		$this->assertArrayHasKey( 'easy-digital-downloads', $platforms );
		$this->assertTrue( ( new Edd_Platform( $reader ) )->test_connection()['success'] );

		( new Woo_Abilities( null, null, null, $reader ) )->register();
		$tools = Abilities_Registrar::get_registered_tools();
		$this->assertArrayHasKey( 'marketing-analytics/get-sales-by-source', $tools );
		$this->assertArrayNotHasKey( 'marketing-analytics/get-store-funnel', $tools );
		$this->assertTrue( $tools['marketing-analytics/get-sales-by-source']['meta']['annotations']['readonly'] );
	}

	/**
	 * Empty data gives zeroes, no rows and no invented currency rows.
	 */
	public function test_empty_data(): void {
		$result = $this->reader()->get_sales_by_source( self::START, self::END );

		$this->assertSame( 'easy-digital-downloads', $result['store'] );
		$this->assertSame( 0, $result['total_orders'] );
		$this->assertSame( array(), $result['rows'] );
		$this->assertSame( array(), $result['currencies'] );
	}

	/**
	 * Currencies are never summed.
	 */
	public function test_multi_currency_kept_separate(): void {
		$reader = $this->reader(
			array( $this->sale( 1, 100.0, 'USD' ), $this->sale( 2, 50.0, 'USD' ), $this->sale( 3, 80.0, 'EUR' ) ),
			array( 1 => array( 'source_type' => 'typein' ), 2 => array( 'source_type' => 'typein' ), 3 => array( 'source_type' => 'typein' ) )
		);

		$result = $reader->get_sales_by_source( self::START, self::END );

		$this->assertCount( 2, $result['currencies'] );
		$this->assertSame( 'USD', $result['currency'] );
		$this->assertSame( 150.0, $result['total_revenue'] );
		$this->assertSame( 'EUR', $result['currencies'][1]['currency'] );
		$this->assertSame( 80.0, $result['currencies'][1]['total_revenue'] );
		$this->assertStringContainsString( 'never added together', $result['note'] );
	}

	/**
	 * Refund rows are subtracted from the parent; test mode and unpaid orders are skipped.
	 */
	public function test_refunds_subtracted_and_test_mode_skipped(): void {
		$refund = $this->sale( 9, -30.0 );
		$refund = array_merge( $refund, array( 'type' => 'refund', 'parent' => 1 ) );
		$reader = $this->reader(
			array(
				$this->sale( 1, 100.0 ),
				$refund,
				$this->sale( 2, 999.0, 'USD', '2026-09-23 10:00:00', 'test' ),
				$this->sale( 3, 500.0, 'USD', '2026-09-23 10:00:00', 'live', 'pending' ),
				$this->sale( 4, 500.0, 'USD', '2026-09-23 10:00:00', 'live', 'refunded' ),
			),
			array( 1 => array( 'source_type' => 'typein' ) ),
			array(
				array( 'order_id' => 1, 'product_id' => 7, 'product_name' => 'Plugin A', 'quantity' => 2, 'total' => 100.0 ),
				array( 'order_id' => 9, 'product_id' => 7, 'product_name' => 'Plugin A', 'quantity' => -1, 'total' => -30.0 ),
			)
		);

		$result = $reader->get_sales_by_source( self::START, self::END );

		$this->assertSame( 1, $result['total_orders'] );
		$this->assertSame( 70.0, $result['total_revenue'] );
		$this->assertSame( 70.0, $result['rows'][0]['revenue'] );
		$this->assertSame( array( 'name' => 'Plugin A', 'units' => 1, 'revenue' => 70.0 ), $result['top_downloads'][0] );
	}

	/**
	 * Orders without attribution meta are labelled Not tracked, never zero-filled.
	 */
	public function test_untracked_orders(): void {
		$reader = $this->reader(
			array( $this->sale( 1, 100.0 ), $this->sale( 2, 50.0 ) ),
			array( 1 => array( 'source_type' => 'utm', 'source' => 'google', 'medium' => 'cpc', 'campaign' => 'spring', 'landing_page' => 'https://example.com/pricing?x=1' ) )
		);

		$result = $reader->get_sales_by_source( self::START, self::END );
		$labels = array_column( $result['rows'], 'revenue', 'label' );

		$this->assertSame( 100.0, $labels['Paid Search'] );
		$this->assertSame( 50.0, $labels['Not tracked'] );
		$this->assertSame( 1, $result['tracked_orders'] );
		$this->assertStringContainsString( 'Not tracked', $result['note'] );

		$campaign = $reader->get_sales_by_source( self::START, self::END, 'campaign' );
		$this->assertSame( 'spring', $campaign['rows'][0]['label'] );
		$this->assertSame( 'google', $campaign['rows'][0]['source'] );
	}

	/**
	 * Previous-period comparison.
	 */
	public function test_previous_period(): void {
		$reader = $this->reader(
			array( $this->sale( 1, 150.0 ), $this->sale( 2, 100.0, 'USD', '2026-09-16 10:00:00' ) ),
			array( 1 => array( 'source_type' => 'typein' ), 2 => array( 'source_type' => 'typein' ) )
		);

		$row = $reader->get_sales_by_source( self::START, self::END )['rows'][0];

		$this->assertSame( 100.0, $row['previous_revenue'] );
		$this->assertSame( 50.0, $row['revenue_change'] );
	}

	/**
	 * Only non-personal columns are requested and nothing personal is returned.
	 */
	public function test_no_personal_data_read(): void {
		$reader = $this->reader(
			array( $this->sale( 1, 100.0 ) ),
			array( 1 => array( 'source_type' => 'typein' ) ),
			array( array( 'order_id' => 1, 'product_id' => 7, 'product_name' => 'Plugin A', 'quantity' => 1, 'total' => 100.0 ) )
		);

		foreach ( Edd_Sales_Reader::GROUPS as $group ) {
			$json = wp_json_encode( $reader->get_sales_by_source( self::START, self::END, $group ) );
			foreach ( array( 'bob@example.com', '203.0.113.9', 'Bob Buyer' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $json );
			}
		}

		foreach ( $reader->calls as $call ) {
			$this->assertNotEmpty( $call['fields'] );
			foreach ( array( 'email', 'ip', 'name', 'user_id', 'customer_id', 'payment_key' ) as $personal ) {
				$this->assertNotContains( $personal, $call['fields'] );
			}
		}
	}

	/**
	 * Weekly summary and widget use EDD only when WooCommerce is absent.
	 */
	public function test_weekly_sales_row_uses_edd_without_woo(): void {
		$edd     = $this->reader(
			array( $this->sale( 1, 300.0 ), $this->sale( 2, 100.0, 'USD', '2026-09-16 10:00:00' ) ),
			array( 1 => array( 'source_type' => 'organic', 'source' => 'google', 'medium' => 'organic' ) )
		);
		$woo     = new class() extends Woo_Sales_Reader {
			/**
			 * WooCommerce is absent.
			 *
			 * @return bool
			 */
			public function available(): bool {
				return false;
			}
		};
		$summary = new Weekly_Summary( array(), array(), null, $woo, $edd );
		$range   = array(
			'current'  => array( self::START, self::END ),
			'previous' => array( '2026-09-14', '2026-09-20' ),
		);

		$sales = $summary->collect_sales( $range );

		$this->assertSame( 'USD', $sales['currency'] );
		$this->assertSame( 300.0, $sales['revenue'] );
		$this->assertSame( 200.0, $sales['change'] );
		$this->assertSame( 'Organic Search', $sales['channels'][0]['label'] );

		$edd->active = false;
		$this->assertNull( ( new Weekly_Summary( array(), array(), null, $woo, $edd ) )->collect_sales( $range ) );
	}
}
