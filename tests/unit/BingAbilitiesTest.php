<?php
/**
 * Tests for the Bing_Abilities class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Abilities\Abilities_Registrar;
use Specflux_Marketing_Analytics\Abilities\Bing_Abilities;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;
use Specflux_Marketing_Analytics\Integrations\Bing_Integration;

/**
 * Bing abilities test class.
 */
class BingAbilitiesTest extends TestCase {

	/**
	 * Reset state.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options, $mock_transients;
		$mock_options    = array();
		$mock_transients = array();
		$GLOBALS['mock_http_requests'] = array();
		unset( $GLOBALS['mock_http_handler'] );
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = array( new Bing_Integration(), 'register_platform' );
		Platform_Registry::reset();
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['mock_http_handler'], $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		Platform_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Nothing is registered until Bing is connected.
	 */
	public function test_not_registered_without_credentials(): void {
		$before = count( Abilities_Registrar::get_registered_tools() );
		( new Bing_Abilities() )->register();
		$this->assertCount( $before, Abilities_Registrar::get_registered_tools() );
	}

	/**
	 * Four tools register with the expected names and a shared permission callback.
	 */
	public function test_registers_four_tools(): void {
		( new Credential_Manager() )->save_credentials( 'bing', array( 'api_key' => 'k', 'site_url' => 'https://example.com/' ) );
		( new Bing_Abilities() )->register();

		$tools = Abilities_Registrar::get_registered_tools();
		foreach ( array( 'get-bing-search-performance', 'get-bing-top-queries', 'get-bing-top-pages', 'get-bing-crawl-stats' ) as $slug ) {
			$this->assertArrayHasKey( 'marketing-analytics/' . $slug, $tools );
		}
		$queries = $tools['marketing-analytics/get-bing-top-queries'];
		$this->assertSame( array( Abilities_Registrar::class, 'can_access' ), $queries['permission_callback'] );
		$this->assertSame( 25, $queries['input_schema']['properties']['limit']['default'] );
		$this->assertStringContainsString( 'Search Console', $queries['description'] );
	}

	/**
	 * Default period is the 28 days ending yesterday.
	 */
	public function test_default_period(): void {
		$period = Bing_Abilities::resolve_period( array() );
		$days   = ( strtotime( $period['end_date'] ) - strtotime( $period['start_date'] ) ) / DAY_IN_SECONDS;

		$this->assertSame( 27.0, (float) $days );
		$this->assertSame( ( new \DateTimeImmutable( 'yesterday', wp_timezone() ) )->format( 'Y-m-d' ), $period['end_date'] );
	}

	/**
	 * Malformed and inverted dates are rejected.
	 */
	public function test_invalid_periods_rejected(): void {
		foreach ( array( array( 'start_date' => '2026-13-40' ), array( 'end_date' => 'yesterday' ), array( 'start_date' => '2026-02-02', 'end_date' => '2026-01-01' ) ) as $args ) {
			try {
				Bing_Abilities::resolve_period( $args );
				$this->fail( 'Expected exception.' );
			} catch ( \Exception $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	/**
	 * When not connected, the tool returns a clear error pointing at Connections.
	 */
	public function test_execute_not_connected_error(): void {
		$result = ( new Bing_Abilities() )->execute_get_top_queries( array() );

		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'Connections', $result['content'][0]['text'] );
	}

	/**
	 * Search performance returns totals and the resolved period.
	 */
	public function test_execute_search_performance_totals(): void {
		( new Credential_Manager() )->save_credentials( 'bing', array( 'api_key' => 'k', 'site_url' => 'https://example.com/' ) );
		$GLOBALS['mock_http_handler'] = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'd' => array(
							array( 'Clicks' => 10, 'Impressions' => 100, 'Date' => '/Date(1316156400000-0700)/' ),
							array( 'Clicks' => 5, 'Impressions' => 100, 'Date' => '/Date(1316242800000-0700)/' ),
						),
					)
				),
			);
		};

		$result = ( new Bing_Abilities() )->execute_get_search_performance( array( 'start_date' => '2011-09-16', 'end_date' => '2011-09-17' ) );
		$data   = json_decode( $result['content'][0]['text'], true );

		$this->assertArrayNotHasKey( 'isError', $result );
		$this->assertSame( 15, $data['totals']['clicks'] );
		$this->assertSame( 200, $data['totals']['impressions'] );
		$this->assertSame( 0.075, $data['totals']['ctr'] );
		$this->assertSame( '2011-09-16', $data['period']['start_date'] );
		$this->assertCount( 2, $data['rows'] );
	}
}
