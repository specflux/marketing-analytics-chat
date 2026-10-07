<?php
/**
 * Tests for the Matomo client, platform registration and abilities.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Abilities\Abilities_Registrar;
use Specflux_Marketing_Analytics\Abilities\Matomo_Abilities;
use Specflux_Marketing_Analytics\API_Clients\Matomo_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;
use Specflux_Marketing_Analytics\Integrations\Matomo_Integration;

/**
 * Matomo test class.
 */
class MatomoClientTest extends TestCase {

	const TOKEN = 'abcdef0123456789abcdef0123456789';

	/**
	 * Reset state and the HTTP mock.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options, $mock_transients;
		$mock_options                  = array();
		$mock_transients               = array();
		$GLOBALS['mock_http_requests'] = array();
		unset( $GLOBALS['mock_http_handler'] );
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = array( new Matomo_Integration(), 'register_platform' );
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
	 * Answer every request with a JSON body.
	 *
	 * @param mixed $payload JSON-encodable body.
	 * @param int   $code    HTTP status.
	 */
	private function respond( $payload, int $code = 200 ): void {
		$GLOBALS['mock_http_handler'] = static function () use ( $payload, $code ) {
			return array(
				'response' => array( 'code' => $code ),
				'body'     => wp_json_encode( $payload ),
			);
		};
	}

	/**
	 * Answer by Matomo method name.
	 *
	 * @param array $map Method => payload.
	 */
	private function respond_by_method( array $map ): void {
		$GLOBALS['mock_http_handler'] = static function ( $url ) use ( $map ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$payload = $map[ $query['method'] ] ?? array(
				'result'  => 'error',
				'message' => 'unmocked ' . $query['method'],
			);
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( $payload ),
			);
		};
	}

	/**
	 * Build a client with explicit credentials.
	 */
	private function client( string $url = 'https://analytics.example.com/' ): Matomo_Client {
		return new Matomo_Client( $url, '7', self::TOKEN );
	}

	/**
	 * Only https URLs (or http for localhost) are accepted.
	 */
	public function test_url_validation(): void {
		$this->assertSame( 'https://a.example.com/', Matomo_Client::normalize_url( 'https://A.example.com' ) );
		$this->assertSame( 'https://a.example.com/matomo/', Matomo_Client::normalize_url( 'https://a.example.com/matomo/index.php' ) );
		$this->assertSame( 'http://localhost:8080/', Matomo_Client::normalize_url( 'http://localhost:8080' ) );
		$this->assertSame( 'http://127.0.0.1/', Matomo_Client::normalize_url( 'http://127.0.0.1/' ) );
		$this->assertNull( Matomo_Client::normalize_url( 'http://a.example.com/' ) );
		$this->assertNull( Matomo_Client::normalize_url( 'ftp://a.example.com/' ) );
		$this->assertNull( Matomo_Client::normalize_url( 'a.example.com' ) );
		$this->assertNull( Matomo_Client::normalize_url( 'https://a.example.com/?token_auth=x' ) );
		$this->assertNull( Matomo_Client::normalize_url( 'https://user:pw@a.example.com/' ) );
	}

	/**
	 * Constructor rejects bad URLs and site IDs.
	 */
	public function test_constructor_rejects_bad_input(): void {
		$this->expectException( \Exception::class );
		new Matomo_Client( 'http://evil.example.com/', '1', self::TOKEN );
	}

	/**
	 * A non-numeric site ID is rejected.
	 */
	public function test_constructor_rejects_bad_site_id(): void {
		$this->expectExceptionMessage( 'Site ID' );
		new Matomo_Client( 'https://a.example.com/', 'abc', self::TOKEN );
	}

	/**
	 * Missing stored credentials give a helpful error.
	 */
	public function test_constructor_without_credentials(): void {
		$this->expectExceptionMessage( 'not connected' );
		new Matomo_Client();
	}

	/**
	 * Requests POST to the API with the token and site in the body, never the URL.
	 */
	public function test_request_shape_token_in_body(): void {
		$this->respond( array( 'nb_visits' => 10 ) );

		$this->client()->get_traffic( '2026-09-01', '2026-09-28' );

		$request = $GLOBALS['mock_http_requests'][0];
		$this->assertStringStartsWith( 'https://analytics.example.com/?', $request['url'] );
		$this->assertStringContainsString( 'module=API', $request['url'] );
		$this->assertStringContainsString( 'method=VisitsSummary.get', $request['url'] );
		$this->assertStringContainsString( 'format=JSON', $request['url'] );
		$this->assertStringNotContainsString( self::TOKEN, $request['url'] );
		$this->assertStringNotContainsString( 'token_auth', $request['url'] );
		$this->assertSame( self::TOKEN, $request['args']['body']['token_auth'] );
		$this->assertSame( 7, $request['args']['body']['idSite'] );
		$this->assertSame( 'range', $request['args']['body']['period'] );
		$this->assertSame( '2026-09-01,2026-09-28', $request['args']['body']['date'] );
	}

	/**
	 * Traffic is parsed (percent strings become ratios) and compared with the previous period.
	 */
	public function test_traffic_parsing_and_previous_period(): void {
		$calls = array();
		$GLOBALS['mock_http_handler'] = static function ( $url, $args ) use ( &$calls ) {
			$calls[] = $args['body']['date'];
			$payload = 1 === count( $calls )
				? array( 'nb_visits' => 200, 'nb_actions' => 500, 'bounce_rate' => '40%', 'avg_time_on_site' => 90, 'nb_actions_per_visit' => 2.5, 'nb_visits_converted' => 20 )
				: array( 'nb_visits' => 100, 'nb_actions' => 400, 'bounce_rate' => '50%', 'avg_time_on_site' => 60, 'nb_actions_per_visit' => 4, 'nb_visits_converted' => 0 );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( $payload ),
			);
		};

		$data = $this->client()->get_traffic( '2026-09-15', '2026-09-28' );

		$this->assertSame( array( '2026-09-15,2026-09-28', '2026-09-01,2026-09-14' ), $calls );
		$this->assertSame( 200, $data['current']['visits'] );
		$this->assertNull( $data['current']['unique_visitors'] );
		$this->assertSame( 0.4, $data['current']['bounce_rate'] );
		$this->assertSame( '2026-09-01', $data['previous']['start_date'] );
		$this->assertSame( '2026-09-14', $data['previous']['end_date'] );
		$this->assertSame( array( 'absolute' => 100, 'percent' => 100.0 ), $data['change']['visits'] );
		$this->assertSame( -0.1, $data['change']['bounce_rate']['absolute'] );
		$this->assertNull( $data['change']['unique_visitors'] );
		$this->assertNull( $data['change']['visits_converted']['percent'] );
	}

	/**
	 * Top pages use flat=1 and are mapped.
	 */
	public function test_top_pages(): void {
		$this->respond(
			array(
				array( 'label' => '/cart/', 'url' => 'https://s.example/cart/', 'nb_hits' => 47, 'nb_visits' => 35, 'entry_nb_visits' => 10, 'avg_time_on_page' => 57, 'bounce_rate' => '49%', 'exit_rate' => '34%' ),
			)
		);

		$rows = $this->client()->get_top_pages( '2026-09-01', '2026-09-28', 5 );

		$this->assertSame( 'https://s.example/cart/', $rows[0]['url'] );
		$this->assertSame( 47, $rows[0]['pageviews'] );
		$this->assertSame( 0.49, $rows[0]['bounce_rate'] );
		$body = $GLOBALS['mock_http_requests'][0]['args']['body'];
		$this->assertSame( 1, $body['flat'] );
		$this->assertSame( 5, $body['filter_limit'] );
		$this->assertStringContainsString( 'method=Actions.getPageUrls', $GLOBALS['mock_http_requests'][0]['url'] );
	}

	/**
	 * Sources combine four reports; optional failures become warnings.
	 */
	public function test_sources_with_partial_failure(): void {
		$row = array( 'label' => 'Search Engines', 'nb_visits' => 100, 'nb_actions' => 250, 'bounce_count' => 40, 'sum_visit_length' => 9000, 'nb_visits_converted' => 7 );
		$this->respond_by_method(
			array(
				'Referrers.getReferrerType'   => array( $row ),
				'Referrers.getWebsites'       => array( array_merge( $row, array( 'label' => 'example.org' ) ) ),
				'Referrers.getSearchEngines'  => array( array_merge( $row, array( 'label' => 'Google' ) ) ),
				// Campaigns unmocked, so Matomo "errors".
			)
		);

		$data = $this->client()->get_sources( '2026-09-01', '2026-09-28', 10 );

		$this->assertSame( 'Search Engines', $data['referrer_types'][0]['name'] );
		$this->assertSame( 0.4, $data['referrer_types'][0]['bounce_rate'] );
		$this->assertSame( 90, $data['referrer_types'][0]['avg_time_on_site_sec'] );
		$this->assertSame( 'example.org', $data['websites'][0]['name'] );
		$this->assertSame( 'Google', $data['search_engines'][0]['name'] );
		$this->assertSame( array(), $data['campaigns'] );
		$this->assertCount( 1, $data['warnings'] );
		$this->assertStringContainsString( 'campaigns', $data['warnings'][0] );
	}

	/**
	 * Goals are discovered from goal_N_* columns and named from Goals.getGoals.
	 */
	public function test_goals(): void {
		$this->respond_by_method(
			array(
				'Goals.get'     => array(
					'nb_visits'                   => 1000,
					'nb_conversions'              => 90,
					'nb_visits_converted'         => 80,
					'revenue'                     => 123.456,
					'conversion_rate'             => '8%',
					'goal_4_nb_conversions'       => 30,
					'goal_4_nb_visits_converted'  => 30,
					'goal_4_revenue'              => 30,
					'goal_5_nb_conversions'       => 60,
					'goal_5_nb_visits_converted'  => 50,
					'goal_5_revenue'              => 93.456,
				),
				'Goals.getGoals' => array( array( 'idgoal' => 4, 'name' => 'Signup' ), array( 'idgoal' => 5, 'name' => 'Purchase' ) ),
			)
		);

		$data = $this->client()->get_goals( '2026-09-01', '2026-09-28' );

		$this->assertSame( 90, $data['totals']['conversions'] );
		$this->assertSame( 0.08, $data['totals']['conversion_rate'] );
		$this->assertSame( 123.46, $data['totals']['revenue'] );
		$this->assertSame( 'Purchase', $data['goals'][0]['name'] );
		$this->assertSame( 60, $data['goals'][0]['conversions'] );
		$this->assertSame( 0.05, $data['goals'][0]['conversion_rate'] );
		$this->assertSame( 'Signup', $data['goals'][1]['name'] );
	}

	/**
	 * Matomo errors arrive as HTTP 200 with result=error.
	 */
	public function test_error_shape_http_200(): void {
		$this->respond(
			array(
				'result'  => 'error',
				'message' => "You can't access this resource as it requires 'view' access for the website id = 7.",
			)
		);

		$this->expectExceptionMessage( "requires 'view' access" );
		$this->client()->get_top_pages( '2026-09-01', '2026-09-28', 5 );
	}

	/**
	 * The token never appears in surfaced error messages.
	 */
	public function test_token_is_scrubbed_from_errors(): void {
		$this->respond(
			array(
				'result'  => 'error',
				'message' => 'Bad token ' . self::TOKEN . ' and token_auth=' . self::TOKEN,
			)
		);

		try {
			$this->client()->get_goals( '2026-09-01', '2026-09-28' );
			$this->fail( 'Expected an exception.' );
		} catch ( \Exception $e ) {
			$this->assertStringNotContainsString( self::TOKEN, $e->getMessage() );
			$this->assertStringContainsString( '[redacted]', $e->getMessage() );
		}
	}

	/**
	 * HTTP errors and non-JSON bodies are reported without leaking the token.
	 */
	public function test_http_error_and_garbage(): void {
		$GLOBALS['mock_http_handler'] = static function () {
			return array(
				'response' => array( 'code' => 500 ),
				'body'     => '<html>boom</html>',
			);
		};
		try {
			$this->client()->get_traffic( '2026-09-01', '2026-09-28' );
			$this->fail( 'Expected an exception.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'HTTP 500', $e->getMessage() );
		}

		$GLOBALS['mock_http_handler'] = static function () {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html>login page</html>',
			);
		};
		$this->expectExceptionMessage( 'Unexpected response' );
		$this->client()->get_traffic( '2026-09-01', '2026-09-28' );
	}

	/**
	 * Transport errors have the token stripped.
	 */
	public function test_transport_error_scrubbed(): void {
		$GLOBALS['mock_http_handler'] = static function () {
			return new \WP_Error( 'http_request_failed', 'cURL error for ?token_auth=' . self::TOKEN );
		};

		try {
			$this->client()->get_traffic( '2026-09-01', '2026-09-28' );
			$this->fail( 'Expected an exception.' );
		} catch ( \Exception $e ) {
			$this->assertStringNotContainsString( self::TOKEN, $e->getMessage() );
		}
	}

	/**
	 * Connection test: success names the site and version.
	 */
	public function test_connection_success(): void {
		$this->respond_by_method(
			array(
				'API.getMatomoVersion'        => array( 'value' => '5.1.0' ),
				'SitesManager.getSiteFromId'  => array( array( 'idsite' => 7, 'name' => 'My Shop' ) ),
			)
		);

		$result = $this->client()->test_connection();

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'My Shop', $result['message'] );
		$this->assertStringContainsString( '5.1.0', $result['message'] );
		$this->assertStringContainsString( 'method=API.getMatomoVersion', $GLOBALS['mock_http_requests'][0]['url'] );
		$this->assertStringContainsString( 'method=SitesManager.getSiteFromId', $GLOBALS['mock_http_requests'][1]['url'] );
		$this->assertArrayNotHasKey( 'idSite', $GLOBALS['mock_http_requests'][0]['args']['body'] );
		$this->assertSame( 7, $GLOBALS['mock_http_requests'][1]['args']['body']['idSite'] );
	}

	/**
	 * Connection test: unreachable server.
	 */
	public function test_connection_unreachable(): void {
		$GLOBALS['mock_http_handler'] = static function () {
			return new \WP_Error( 'http_request_failed', 'Could not resolve host' );
		};

		$result = $this->client()->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Could not reach Matomo', $result['message'] );
	}

	/**
	 * Connection test: version works but the token or site is rejected.
	 */
	public function test_connection_bad_token_or_site(): void {
		$this->respond_by_method(
			array(
				'API.getMatomoVersion'       => array( 'value' => '5.1.0' ),
				'SitesManager.getSiteFromId' => array(
					'result'  => 'error',
					'message' => 'Unable to authenticate with the provided token. It is either invalid or expired.',
				),
			)
		);

		$result = $this->client()->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Site ID or Auth token was not accepted', $result['message'] );
		$this->assertStringContainsString( 'Unable to authenticate', $result['message'] );
	}

	/**
	 * Connection test: an empty site result is not a pass.
	 */
	public function test_connection_empty_site(): void {
		$this->respond_by_method(
			array(
				'API.getMatomoVersion'       => array( 'value' => '5.1.0' ),
				'SitesManager.getSiteFromId' => array(),
			)
		);

		$result = $this->client()->test_connection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'no site with ID 7', $result['message'] );
	}

	/**
	 * The platform registers with the expected shape.
	 */
	public function test_platform_registration(): void {
		$platform = Platform_Registry::get( 'matomo' );

		$this->assertSame( 'api_key', $platform['auth'] );
		$this->assertSame( array( 'url', 'site_id', 'token_auth' ), $platform['fields'] );
		$this->assertSame( HOUR_IN_SECONDS, $platform['cache_ttl'] );
		$this->assertSame( 'Matomo URL', Platform_Registry::field_label( $platform, 'url' ) );
		$this->assertSame( 'Site ID', Platform_Registry::field_label( $platform, 'site_id' ) );
		$this->assertSame( 'Auth token', Platform_Registry::field_label( $platform, 'token_auth' ) );
		$this->assertTrue( Platform_Registry::is_secret_field( 'token_auth' ) );
		$this->assertNotEmpty( $platform['help_url'] );
		$this->assertNotEmpty( $platform['prompt_hint'] );
		$this->assertIsCallable( $platform['connection_test'] );
	}

	/**
	 * The registered connection test reports missing credentials without throwing.
	 */
	public function test_registered_connection_test_without_credentials(): void {
		$result = Matomo_Integration::test_connection();
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'not connected', $result['message'] );
	}

	/**
	 * Nothing registers until Matomo is connected; then four read-only tools appear.
	 */
	public function test_abilities_registered_and_annotated(): void {
		$before = count( Abilities_Registrar::get_registered_tools() );
		( new Matomo_Abilities() )->register();
		$this->assertCount( $before, Abilities_Registrar::get_registered_tools() );

		( new Credential_Manager() )->save_credentials(
			'matomo',
			array(
				'url'        => 'https://analytics.example.com/',
				'site_id'    => '7',
				'token_auth' => self::TOKEN,
			)
		);
		( new Matomo_Abilities() )->register();

		$tools = Abilities_Registrar::get_registered_tools();
		foreach ( array( 'get-matomo-traffic', 'get-matomo-top-pages', 'get-matomo-sources', 'get-matomo-goals' ) as $slug ) {
			$this->assertArrayHasKey( 'marketing-analytics/' . $slug, $tools );
			$tool = $tools[ 'marketing-analytics/' . $slug ];
			$this->assertSame(
				array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				$tool['meta']['annotations'],
				$slug
			);
			$this->assertSame( array( Abilities_Registrar::class, 'can_access' ), $tool['permission_callback'] );
			$this->assertStringContainsString( 'Google Analytics', $tool['description'] );
		}
		$this->assertArrayHasKey( 'limit', $tools['marketing-analytics/get-matomo-top-pages']['input_schema']['properties'] );
		$this->assertArrayNotHasKey( 'limit', $tools['marketing-analytics/get-matomo-traffic']['input_schema']['properties'] );
	}

	/**
	 * An ability runs end to end against the mocked transport.
	 */
	public function test_ability_end_to_end(): void {
		( new Credential_Manager() )->save_credentials(
			'matomo',
			array(
				'url'        => 'https://analytics.example.com/',
				'site_id'    => '7',
				'token_auth' => self::TOKEN,
			)
		);
		$this->respond( array( array( 'label' => '/a', 'url' => 'https://x/a', 'nb_hits' => 3 ) ) );

		$result = ( new Matomo_Abilities() )->execute_get_top_pages( array( 'limit' => 9999 ) );

		$this->assertSame( 500, $GLOBALS['mock_http_requests'][0]['args']['body']['filter_limit'] );
		$this->assertStringContainsString( '/a', wp_json_encode( $result ) );
	}
}
