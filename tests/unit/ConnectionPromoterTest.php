<?php
/**
 * Tests for the Connection Promoter class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Admin\Connection_Promoter;
use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;

/**
 * Connection Promoter test class.
 */
class ConnectionPromoterTest extends TestCase {

	/**
	 * Connection Promoter instance.
	 *
	 * @var Connection_Promoter
	 */
	private $promoter;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options   = array();
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		Platform_Registry::reset();
		$this->promoter = new Connection_Promoter();
	}

	/**
	 * Clean up the registry.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		Platform_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Test class exists.
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( 'Specflux_Marketing_Analytics\Admin\Connection_Promoter' ) );
	}

	/**
	 * Test get_connected_platforms returns empty array when nothing is connected.
	 */
	public function test_no_platforms_connected(): void {
		$connected = $this->promoter->get_connected_platforms();

		$this->assertIsArray( $connected );
		$this->assertEmpty( $connected );
	}

	/**
	 * Test get_next_recommendation returns clarity first when nothing connected.
	 */
	public function test_recommends_clarity_first(): void {
		$next = $this->promoter->get_next_recommendation( array() );

		$this->assertNotEmpty( $next );
		$this->assertEquals( 'clarity', $next['key'] );
		$this->assertEquals( 'Microsoft Clarity', $next['name'] );
	}

	/**
	 * Test recommendation after clarity is connected.
	 */
	public function test_recommends_ga4_after_clarity(): void {
		$next = $this->promoter->get_next_recommendation( array( 'clarity' ) );

		$this->assertNotEmpty( $next );
		$this->assertEquals( 'ga4', $next['key'] );
		$this->assertEquals( 'Google Analytics 4', $next['name'] );
	}

	/**
	 * Test recommendation after clarity and ga4 are connected.
	 */
	public function test_recommends_gsc_last(): void {
		$next = $this->promoter->get_next_recommendation( array( 'clarity', 'ga4' ) );

		$this->assertNotEmpty( $next );
		$this->assertEquals( 'gsc', $next['key'] );
		$this->assertEquals( 'Google Search Console', $next['name'] );
	}

	/**
	 * Test no recommendation when all connected.
	 */
	public function test_no_recommendation_when_all_connected(): void {
		$next = $this->promoter->get_next_recommendation( array( 'clarity', 'ga4', 'gsc' ) );

		$this->assertEmpty( $next );
	}

	/**
	 * Test get_connection_prompt with no connections.
	 */
	public function test_connection_prompt_no_connections(): void {
		$prompt = $this->promoter->get_connection_prompt();

		$this->assertIsArray( $prompt );
		$this->assertEquals( 0, $prompt['connected_count'] );
		$this->assertNotEmpty( $prompt['message'] );
		$this->assertNotEmpty( $prompt['next'] );
		$this->assertNotEmpty( $prompt['cta'] );
	}

	/**
	 * Test connection prompt has required keys.
	 */
	public function test_connection_prompt_structure(): void {
		$prompt = $this->promoter->get_connection_prompt();

		$this->assertArrayHasKey( 'connected_count', $prompt );
		$this->assertArrayHasKey( 'message', $prompt );
		$this->assertArrayHasKey( 'next', $prompt );
		$this->assertArrayHasKey( 'cta', $prompt );
	}

	/**
	 * The platform list is the registry, so every registered platform counts toward the total.
	 */
	public function test_total_follows_the_platform_registry(): void {
		$this->assertSame( count( Platform_Registry::slugs() ), $this->promoter->get_platform_total() );

		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = static function ( $platforms ) {
			$platforms['matomo'] = array(
				'slug'        => 'matomo',
				'label'       => 'Matomo',
				'auth'        => 'api_key',
				'fields'      => array( 'token' ),
				'description' => 'Visits and goals from Matomo.',
			);
			return $platforms;
		};
		Platform_Registry::reset();

		$this->assertSame( 4, $this->promoter->get_platform_total() );
	}

	/**
	 * Connected platforms come from the registry, not a fixed three.
	 */
	public function test_connected_platforms_include_registered_extras(): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = static function ( $platforms ) {
			$platforms['optional'] = array(
				'slug'            => 'optional',
				'label'           => 'Optional Source',
				'auth'            => 'api_key',
				'fields'          => array( 'note' ),
				'optional_fields' => array( 'note' ),
			);
			return $platforms;
		};
		Platform_Registry::reset();

		$this->assertSame( array( 'optional' ), $this->promoter->get_connected_platforms(), 'A key platform whose fields are all optional counts as connected.' );
	}

	/**
	 * Once the three shipped platforms are done, the next recommendation is the next registered one.
	 */
	public function test_recommendation_continues_into_registered_extras(): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = static function ( $platforms ) {
			$platforms['matomo'] = array(
				'slug'        => 'matomo',
				'label'       => 'Matomo',
				'auth'        => 'api_key',
				'fields'      => array( 'token' ),
				'description' => 'Visits and goals from Matomo.',
				'tab'         => false,
			);
			return $platforms;
		};
		Platform_Registry::reset();

		$next = $this->promoter->get_next_recommendation( array( 'clarity', 'ga4', 'gsc' ) );

		$this->assertSame( 'matomo', $next['key'] );
		$this->assertSame( 'Matomo', $next['name'] );
		$this->assertSame( '', $next['tab'], 'A platform without a tab has no tab slug.' );
		$this->assertSame( 'visits and goals from Matomo.', $next['benefit'] );
		$this->assertEmpty( $this->promoter->get_next_recommendation( array( 'clarity', 'ga4', 'gsc', 'matomo' ) ) );
	}

	/**
	 * The all-connected hook fires only when every registered platform is connected.
	 */
	public function test_all_connected_hook_does_not_fire_while_something_is_missing(): void {
		$GLOBALS['specflux_mac_test_actions'] = array();

		$this->assertFalse( $this->promoter->maybe_fire_all_connected() );
		$this->assertSame( array(), $GLOBALS['specflux_mac_test_actions'] );
	}

	/**
	 * With every platform connected the hook fires once.
	 */
	public function test_all_connected_hook_fires_when_complete(): void {
		$GLOBALS['specflux_mac_test_actions']                           = array();
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = static function () {
			return array(
				'only' => array(
					'slug'            => 'only',
					'label'           => 'Only',
					'auth'            => 'api_key',
					'fields'          => array( 'note' ),
					'optional_fields' => array( 'note' ),
				),
			);
		};
		Platform_Registry::reset();

		$this->assertTrue( $this->promoter->maybe_fire_all_connected() );
		$this->assertSame( 'specflux_mac_all_platforms_connected', $GLOBALS['specflux_mac_test_actions'][0][0] );
	}

	/**
	 * Test recommendation skips already-connected platforms.
	 */
	public function test_recommendation_skips_connected(): void {
		// Only ga4 connected - should recommend clarity first (priority order).
		$next = $this->promoter->get_next_recommendation( array( 'ga4' ) );

		$this->assertEquals( 'clarity', $next['key'] );
	}

	/**
	 * Test next recommendation includes benefit text.
	 */
	public function test_recommendation_includes_benefit(): void {
		$next = $this->promoter->get_next_recommendation( array() );

		$this->assertArrayHasKey( 'benefit', $next );
		$this->assertNotEmpty( $next['benefit'] );
	}
}
