<?php
/**
 * Tests for the WooCommerce_Abilities class.
 *
 * @package Marketing_Analytics_MCP
 */

namespace Marketing_Analytics_MCP\Tests\unit;

use Marketing_Analytics_MCP\Abilities\WooCommerce_Abilities;
use PHPUnit\Framework\TestCase;

/**
 * WooCommerce Abilities test class.
 */
class WooCommerceAbilitiesTest extends TestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array();
	}

	/**
	 * Test register exits when GA4 credentials missing.
	 */
	public function test_register_exits_when_ga4_credentials_missing(): void {
		global $mock_options;
		$mock_options = array();

		$abilities = new WooCommerce_Abilities();
		// Should not throw, just returns early.
		$abilities->register();
		$this->assertTrue( true );
	}

	/**
	 * Test funnel drop-off calculation with sample data.
	 */
	public function test_funnel_dropoff_calculation(): void {
		$abilities = new WooCommerce_Abilities();

		$funnel = array(
			array( 'step' => 'view_item', 'count' => 1000 ),
			array( 'step' => 'add_to_cart', 'count' => 400 ),
			array( 'step' => 'begin_checkout', 'count' => 200 ),
			array( 'step' => 'add_payment_info', 'count' => 150 ),
			array( 'step' => 'purchase', 'count' => 100 ),
		);

		$result = $abilities->calculate_funnel_dropoff( $funnel );

		$this->assertCount( 5, $result );

		// First step should have 0 drop-off.
		$this->assertEquals( 0, $result[0]['dropoff_rate'] );

		// view_item (1000) -> add_to_cart (400) = 60% drop-off.
		$this->assertEquals( 60.0, $result[1]['dropoff_rate'] );

		// add_to_cart (400) -> begin_checkout (200) = 50% drop-off.
		$this->assertEquals( 50.0, $result[2]['dropoff_rate'] );

		// begin_checkout (200) -> add_payment_info (150) = 25% drop-off.
		$this->assertEquals( 25.0, $result[3]['dropoff_rate'] );

		// add_payment_info (150) -> purchase (100) = 33.33% drop-off.
		$this->assertEqualsWithDelta( 33.33, $result[4]['dropoff_rate'], 0.01 );
	}

	/**
	 * Test funnel drop-off with zero counts.
	 */
	public function test_funnel_dropoff_with_zero_counts(): void {
		$abilities = new WooCommerce_Abilities();

		$funnel = array(
			array( 'step' => 'view_item', 'count' => 0 ),
			array( 'step' => 'add_to_cart', 'count' => 0 ),
		);

		$result = $abilities->calculate_funnel_dropoff( $funnel );

		$this->assertEquals( 0, $result[0]['dropoff_rate'] );
		$this->assertEquals( 0, $result[1]['dropoff_rate'] );
	}

	/**
	 * Test behavior when WooCommerce not active.
	 */
	public function test_woocommerce_class_not_required_for_registration(): void {
		// WooCommerce class shouldn't exist in test environment.
		$this->assertFalse( class_exists( 'WooCommerce' ) );

		// The abilities class itself should still be instantiable.
		$abilities = new WooCommerce_Abilities();
		$this->assertInstanceOf( WooCommerce_Abilities::class, $abilities );
	}
}
