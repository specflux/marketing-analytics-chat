<?php
/**
 * Tests for the Bing_Webmaster_Client class and Bing platform registration.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\API_Clients\Bing_Webmaster_Client;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;
use Specflux_Marketing_Analytics\Integrations\Bing_Integration;

/**
 * Bing Webmaster client test class.
 */
class BingWebmasterClientTest extends TestCase {

	const KEY = 'sampleapikeyedecc1ea4ae341cc8b6';

	/**
	 * Reset state and the HTTP mock.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options, $mock_transients;
		$mock_options    = array();
		$mock_transients = array();
		$GLOBALS['mock_http_requests'] = array();
		unset( $GLOBALS['mock_http_handler'], $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		Platform_Registry::reset();
	}

	/**
	 * Clean up globals.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['mock_http_handler'], $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		Platform_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Make the mock transport answer with a JSON body.
	 *
	 * @param mixed $payload Value to wrap as {"d": payload}, or a raw array for errors.
	 * @param int   $code    HTTP status.
	 * @param bool  $wrap    Whether to wrap in "d".
	 */
	private function respond( $payload, int $code = 200, bool $wrap = true ): void {
		$GLOBALS['mock_http_handler'] = static function () use ( $payload, $code, $wrap ) {
			return array(
				'response' => array( 'code' => $code ),
				'body'     => wp_json_encode( $wrap ? array( 'd' => $payload ) : $payload ),
			);
		};
	}

	/**
	 * Build a client with explicit credentials.
	 */
	private function client( string $site = 'https://example.com/' ): Bing_Webmaster_Client {
		return new Bing_Webmaster_Client( self::KEY, $site );
	}

	/**
	 * Bing's /Date(ms-0700)/ values become the local calendar day.
	 */
	public function test_parse_date_formats(): void {
		$this->assertSame( '2011-09-16', Bing_Webmaster_Client::parse_date( '/Date(1316156400000-0700)/' ) );
		$this->assertSame( '2011-09-16', Bing_Webmaster_Client::parse_date( '/Date(1316156400000)/' ) );
		$this->assertSame( '2011-09-16', Bing_Webmaster_Client::parse_date( '/Date(1316127600000+0100)/' ) );
		$this->assertSame( '2011-09-16', Bing_Webmaster_Client::parse_date( '2011-09-16T00:00:00-07:00' ) );
		$this->assertNull( Bing_Webmaster_Client::parse_date( 'garbage' ) );
		$this->assertNull( Bing_Webmaster_Client::parse_date( null ) );
	}

	/**
	 * The {"d": ...} envelope is unwrapped, dates normalised and the request shaped per the docs.
	 */
	public function test_traffic_stats_unwrap_and_request_shape(): void {
		$this->respond(
			array(
				array( '__type' => 'RankAndTrafficStats:#Microsoft.Bing.Webmaster.Api', 'Clicks' => 15, 'Date' => '/Date(1316156400000-0700)/', 'Impressions' => 100 ),
				array( 'Clicks' => 5, 'Date' => '/Date(1316070000000-0700)/', 'Impressions' => 50 ),
			)
		);

		$rows = $this->client()->get_rank_and_traffic_stats();

		$this->assertSame(
			array(
				array( 'date' => '2011-09-15', 'clicks' => 5, 'impressions' => 50 ),
				array( 'date' => '2011-09-16', 'clicks' => 15, 'impressions' => 100 ),
			),
			$rows
		);

		$request = $GLOBALS['mock_http_requests'][0];
		$this->assertStringStartsWith( 'https://ssl.bing.com/webmaster/api.svc/json/GetRankAndTrafficStats?', $request['url'] );
		$this->assertStringContainsString( 'siteUrl=https%3A%2F%2Fexample.com%2F', $request['url'] );
		$this->assertStringContainsString( 'apikey=' . self::KEY, $request['url'] );
		$this->assertGreaterThan( 0, $request['args']['timeout'] );
	}

	/**
	 * Rows outside the window are dropped (inclusive bounds).
	 */
	public function test_period_filtering(): void {
		$this->respond(
			array(
				array( 'Clicks' => 1, 'Date' => '/Date(1316156400000-0700)/', 'Impressions' => 10 ), // 2011-09-16.
				array( 'Clicks' => 2, 'Date' => '/Date(1316242800000-0700)/', 'Impressions' => 20 ), // 2011-09-17.
				array( 'Clicks' => 3, 'Date' => '/Date(1316329200000-0700)/', 'Impressions' => 30 ), // 2011-09-18.
			)
		);

		$rows = $this->client()->get_rank_and_traffic_stats( '2011-09-17', '2011-09-17' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 2, $rows[0]['clicks'] );

		$this->assertCount( 2, $this->client()->get_rank_and_traffic_stats( '2011-09-17', null ) );
	}

	/**
	 * Weekly query rows are aggregated, weighted and sorted by clicks; limit applies.
	 */
	public function test_query_stats_aggregate_sort_and_limit(): void {
		$this->respond(
			array(
				array( 'Query' => 'a', 'Clicks' => 2, 'Impressions' => 100, 'AvgClickPosition' => 4, 'AvgImpressionPosition' => 10, 'Date' => '/Date(1316156400000-0700)/' ),
				array( 'Query' => 'a', 'Clicks' => 8, 'Impressions' => 300, 'AvgClickPosition' => 2, 'AvgImpressionPosition' => 6, 'Date' => '/Date(1316761200000-0700)/' ),
				array( 'Query' => 'b', 'Clicks' => 20, 'Impressions' => 40, 'AvgClickPosition' => 1, 'AvgImpressionPosition' => 1, 'Date' => '/Date(1316156400000-0700)/' ),
				array( 'Query' => 'old', 'Clicks' => 99, 'Impressions' => 99, 'AvgClickPosition' => 1, 'AvgImpressionPosition' => 1, 'Date' => '/Date(1000000000000-0700)/' ),
			)
		);

		$rows = $this->client()->get_query_stats( '2011-09-01', '2011-09-30', 10 );

		$this->assertSame( array( 'b', 'a' ), array_column( $rows, 'query' ) );
		$this->assertSame( 10, $rows[1]['clicks'] );
		$this->assertSame( 400, $rows[1]['impressions'] );
		$this->assertSame( 0.025, $rows[1]['ctr'] );
		$this->assertSame( 7.0, $rows[1]['avg_impression_position'] ); // (10*100 + 6*300) / 400 = 7.
		$this->assertSame( 2.4, $rows[1]['avg_click_position'] ); // (4*2 + 2*8) / 10 = 2.4.

		$this->assertCount( 1, $this->client()->get_query_stats( '2011-09-01', '2011-09-30', 1 ) );
	}

	/**
	 * Page stats expose the URL under "page".
	 */
	public function test_page_stats_use_page_key(): void {
		$this->respond( array( array( 'Query' => 'https://example.com/x', 'Clicks' => 1, 'Impressions' => 2, 'Date' => '/Date(1316156400000-0700)/' ) ) );

		$rows = $this->client()->get_page_stats( '2011-09-01', '2011-09-30' );
		$this->assertSame( 'https://example.com/x', $rows[0]['page'] );
		$this->assertStringContainsString( 'GetPageStats', $GLOBALS['mock_http_requests'][0]['url'] );
	}

	/**
	 * Crawl stats are mapped to snake_case keys.
	 */
	public function test_crawl_stats_mapping(): void {
		$this->respond( array( array( 'Code2xx' => 9998, 'Code4xx' => 1, 'Code5xx' => 1, 'CrawlErrors' => 2, 'CrawledPages' => 7, 'InIndex' => 1000, 'InLinks' => 2048, 'Date' => '/Date(1316156400000-0700)/' ) ) );

		$rows = $this->client()->get_crawl_stats();
		$this->assertSame( '2011-09-16', $rows[0]['date'] );
		$this->assertSame( 9998, $rows[0]['http_2xx'] );
		$this->assertSame( 1000, $rows[0]['in_index'] );
		$this->assertSame( 2, $rows[0]['crawl_errors'] );
	}

	/**
	 * Results are cached per method and site, so a second call makes no request.
	 */
	public function test_results_are_cached(): void {
		$this->respond( array( array( 'Clicks' => 1, 'Date' => '/Date(1316156400000-0700)/', 'Impressions' => 2 ) ) );

		$this->client()->get_rank_and_traffic_stats();
		$this->client()->get_rank_and_traffic_stats( '2011-09-16', '2011-09-16' );

		$this->assertCount( 1, $GLOBALS['mock_http_requests'] );
	}

	/**
	 * An InvalidApiKey 400 becomes a helpful exception without the key.
	 */
	public function test_invalid_key_error(): void {
		$this->respond( array( 'ErrorCode' => 3, 'Message' => 'InvalidApiKey' ), 400, false );

		try {
			$this->client()->get_crawl_stats();
			$this->fail( 'Expected exception.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'rejected the API key', $e->getMessage() );
			$this->assertStringNotContainsString( self::KEY, $e->getMessage() );
		}
	}

	/**
	 * Transport errors surface as exceptions with the key scrubbed.
	 */
	public function test_transport_error_scrubs_key(): void {
		$GLOBALS['mock_http_handler'] = static function () {
			return new \WP_Error( 'http_request_failed', 'cURL error 28 for ?apikey=' . BingWebmasterClientTest::KEY );
		};

		try {
			$this->client()->get_crawl_stats();
			$this->fail( 'Expected exception.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'Could not reach Bing', $e->getMessage() );
			$this->assertStringNotContainsString( self::KEY, $e->getMessage() );
		}
	}

	/**
	 * A 200 body without the "d" envelope is rejected.
	 */
	public function test_missing_envelope_is_error(): void {
		$this->respond( array( 'unexpected' => true ), 200, false );

		$this->expectException( \Exception::class );
		$this->client()->get_crawl_stats();
	}

	/**
	 * Connection test passes when the site is verified (trailing slash and case ignored).
	 */
	public function test_connection_success(): void {
		$this->respond( array( array( 'Url' => 'https://Example.com', 'IsVerified' => true ) ) );

		$result = $this->client( 'https://example.com/' )->test_connection();
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Connection test lists verified sites when the configured one is not among them.
	 */
	public function test_connection_site_not_verified_lists_sites(): void {
		$this->respond(
			array(
				array( 'Url' => 'https://one.test/', 'IsVerified' => true ),
				array( 'Url' => 'https://two.test/', 'IsVerified' => true ),
			)
		);

		$result = $this->client( 'https://example.com/' )->test_connection();
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'https://one.test/, https://two.test/', $result['message'] );
		$this->assertStringContainsString( 'https://example.com/', $result['message'] );
	}

	/**
	 * A site that exists but is unverified gets a distinct message.
	 */
	public function test_connection_unverified_site(): void {
		$this->respond( array( array( 'Url' => 'https://example.com/', 'IsVerified' => false ) ) );

		$result = $this->client()->test_connection();
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not verified yet', $result['message'] );
	}

	/**
	 * An invalid key fails the connection test with the API's message.
	 */
	public function test_connection_invalid_key(): void {
		$this->respond( array( 'ErrorCode' => 3, 'Message' => 'InvalidApiKey' ), 400, false );

		$result = $this->client()->test_connection();
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'rejected the API key', $result['message'] );
	}

	/**
	 * The live API prefixes the message ("ERROR!!! InvalidApiKey", seen 2026-10-06); it gets the same helpful text.
	 */
	public function test_live_error_prefix_is_stripped(): void {
		$this->respond( array( 'ErrorCode' => 3, 'Message' => 'ERROR!!! InvalidApiKey' ), 400, false );

		$result = $this->client()->test_connection();
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'rejected the API key', $result['message'] );
		$this->assertStringNotContainsString( 'ERROR!!!', $result['message'] );
	}

	/**
	 * The constructor throws a connect-Bing message when nothing is stored.
	 */
	public function test_constructor_requires_credentials(): void {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Connections' );
		new Bing_Webmaster_Client();
	}

	/**
	 * Stored credentials are resolved through the Credential_Manager.
	 */
	public function test_constructor_resolves_stored_credentials(): void {
		$this->register_platform();
		$manager = new \Specflux_Marketing_Analytics\Credentials\Credential_Manager();
		$manager->save_credentials( 'bing', array( 'api_key' => self::KEY, 'site_url' => 'https://example.com/' ) );

		$this->assertSame( 'https://example.com/', ( new Bing_Webmaster_Client() )->get_site_url() );
	}

	/**
	 * Wire the integration's filter into the mocked registry.
	 */
	private function register_platform(): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = array( new Bing_Integration(), 'register_platform' );
		Platform_Registry::reset();
	}

	/**
	 * The platform registers with the generic panel's required keys.
	 */
	public function test_platform_registration(): void {
		$this->register_platform();

		$bing = Platform_Registry::get( 'bing' );
		$this->assertNotNull( $bing );
		$this->assertSame( 'api_key', $bing['auth'] );
		$this->assertSame( array( 'api_key', 'site_url' ), $bing['fields'] );
		$this->assertSame( 6 * HOUR_IN_SECONDS, $bing['cache_ttl'] );
		$this->assertNotSame( '', $bing['help_url'] );
		$this->assertTrue( is_callable( $bing['connection_test'] ) );
		$this->assertContains( 'clarity', Platform_Registry::slugs() );
	}

	/**
	 * The registered connection test reports a clear failure when not configured.
	 */
	public function test_registered_connection_test_unconfigured(): void {
		$this->register_platform();

		$result = call_user_func( Platform_Registry::get( 'bing' )['connection_test'] );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Connections', $result['message'] );
	}
}
