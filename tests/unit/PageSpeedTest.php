<?php
/**
 * Tests for the PageSpeed Insights client, platform and abilities.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit {

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Abilities\PageSpeed_Abilities;
use Specflux_Marketing_Analytics\API_Clients\PageSpeed_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;
use Specflux_Marketing_Analytics\Integrations\PageSpeed_Platform;

/**
 * PageSpeed test class.
 */
class PageSpeedTest extends TestCase {

	/**
	 * Captured Guzzle requests.
	 *
	 * @var array
	 */
	private $history = array();

	/**
	 * Reset state.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options, $mock_transients;
		$mock_options    = array();
		$mock_transients = array();
		$this->history   = array();
		unset( $GLOBALS['specflux_mac_test_filters'] );
		Platform_Registry::reset();
	}

	/**
	 * Clear filters.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['specflux_mac_test_filters'] );
		Platform_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Fixture body.
	 *
	 * @return string
	 */
	private function fixture() {
		return (string) file_get_contents( __DIR__ . '/../fixtures/pagespeed-response.json' );
	}

	/**
	 * Build a client over canned responses.
	 *
	 * @param array       $responses Responses.
	 * @param string|null $key       API key.
	 * @return PageSpeed_Client
	 */
	private function client( array $responses, $key = '' ) {
		$stack = HandlerStack::create( new MockHandler( $responses ) );
		$stack->push( Middleware::history( $this->history ) );

		return new PageSpeed_Client( $key, new Client( array( 'handler' => $stack, 'http_errors' => false ) ) );
	}

	/**
	 * Summary extraction from a PSI response.
	 */
	public function test_summary_extraction() {
		$summary = $this->client( array( new Response( 200, array(), $this->fixture() ) ) )->get_summary( '', 'mobile' );

		$this->assertSame( 62, $summary['score'] );
		$this->assertSame( '3.2 s', $summary['lab']['lcp']['display_value'] );
		$this->assertSame( 450, $summary['lab']['tbt']['value'] );
		$this->assertArrayNotHasKey( 'tti', $summary['lab'] );

		$this->assertSame( 2900, $summary['field']['metrics']['lcp_ms']['percentile'] );
		$this->assertSame( 0.04, $summary['field']['metrics']['cls']['percentile'] );
		$this->assertSame( 'AVERAGE', $summary['field']['overall_category'] );
		$this->assertSame( 'FAST', $summary['origin']['overall_category'] );
		$this->assertSame( 'failed', PageSpeed_Client::assess( $summary['field'] ) );
		$this->assertSame( 'passed', PageSpeed_Client::assess( $summary['origin'] ) );

		$this->assertSame( array( 'render-blocking-resources', 'unused-javascript', 'dom-size' ), array_column( $summary['issues'], 'id' ) );
		$this->assertSame( 900, $summary['issues'][0]['savings_ms'] );
		$this->assertSame( 307200, $summary['issues'][1]['savings_bytes'] );
		$this->assertArrayNotHasKey( 'lighthouseResult', $summary );
	}

	/**
	 * Missing field data yields nulls, not errors.
	 */
	public function test_missing_field_data() {
		$body = json_decode( $this->fixture(), true );
		unset( $body['loadingExperience']['metrics'], $body['originLoadingExperience'] );

		$summary = PageSpeed_Client::extract_summary( $body );

		$this->assertNull( $summary['field'] );
		$this->assertNull( $summary['origin'] );
		$this->assertSame( 'no_data', PageSpeed_Client::assess( $summary['field'] ) );
		$this->assertSame( 62, $summary['score'] );
	}

	/**
	 * Quota errors give an actionable message and are not cached.
	 */
	public function test_quota_error_not_cached() {
		$client = $this->client(
			array(
				new Response( 429, array(), '{"error":{"code":429,"message":"Quota exceeded"}}' ),
				new Response( 200, array(), $this->fixture() ),
			)
		);

		try {
			$client->get_summary( '', 'mobile' );
			$this->fail( 'Expected exception' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'quota exceeded', $e->getMessage() );
			$this->assertStringContainsString( 'API key', $e->getMessage() );
		}

		$this->assertSame( 62, $client->get_summary( '', 'mobile' )['score'] );
	}

	/**
	 * Invalid URL errors surface Google's message.
	 */
	public function test_invalid_url_error() {
		$client = $this->client( array( new Response( 400, array(), '{"error":{"code":400,"message":"Lighthouse returned error: FAILED_DOCUMENT_REQUEST"}}' ) ) );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'FAILED_DOCUMENT_REQUEST' );
		$client->get_summary( '/missing/', 'mobile' );
	}

	/**
	 * Off-host URLs are rejected before any HTTP call.
	 */
	public function test_off_host_url_rejected() {
		$client = $this->client( array( new Response( 200, array(), $this->fixture() ) ) );

		foreach ( array( 'https://evil.example.org/', '//evil.example.org/x', 'ftp://example.com/', 'javascript:alert(1)' ) as $bad ) {
			try {
				$client->get_summary( $bad, 'mobile' );
				$this->fail( 'Expected rejection of ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'own host', $e->getMessage() );
			}
		}
		$this->assertCount( 0, $this->history );
	}

	/**
	 * Paths and same-host URLs resolve; the host filter can widen access.
	 */
	public function test_url_resolution_and_filter() {
		$client = $this->client( array() );

		$this->assertSame( 'https://example.com/', $client->resolve_url( '' ) );
		$this->assertSame( 'https://example.com/pricing/', $client->resolve_url( '/pricing/' ) );
		$this->assertSame( 'https://example.com/a', $client->resolve_url( 'https://example.com/a' ) );

		$GLOBALS['specflux_mac_test_filters']['specflux_mac_pagespeed_allowed_hosts'] = static function ( $hosts ) {
				$hosts[] = 'staging.example.org';
				return $hosts;
			};
		$this->assertSame( 'https://staging.example.org/', $client->resolve_url( 'https://staging.example.org/' ) );
	}

	/**
	 * The key is appended only when saved, and requests carry the documented params.
	 */
	public function test_key_appended_only_when_saved() {
		$keyless = $this->client( array( new Response( 200, array(), $this->fixture() ) ), '' );
		$keyless->get_summary( '/pricing/', 'desktop' );
		$query = (string) $this->history[0]['request']->getUri()->getQuery();
		$this->assertStringContainsString( 'strategy=desktop', $query );
		$this->assertStringContainsString( 'category=performance', $query );
		$this->assertStringContainsString( 'url=https%3A%2F%2Fexample.com%2Fpricing%2F', $query );
		$this->assertStringNotContainsString( 'key=', $query );

		$this->history = array();
		$keyed         = $this->client( array( new Response( 200, array(), $this->fixture() ) ), 'ABC123' );
		$keyed->get_summary( '/other/', 'mobile' );
		$this->assertStringContainsString( 'key=ABC123', (string) $this->history[0]['request']->getUri()->getQuery() );
	}

	/**
	 * A second call for the same url + strategy hits the cache.
	 */
	public function test_results_are_cached() {
		$client = $this->client( array( new Response( 200, array(), $this->fixture() ) ) );
		$client->get_summary( '', 'mobile' );
		$client->get_summary( '', 'mobile' );

		$this->assertCount( 1, $this->history );
		$this->assertTrue( $client->is_cached( '', 'mobile' ) );
		$this->assertFalse( $client->is_cached( '', 'desktop' ) );
	}

	/**
	 * The platform registers keyless and counts as connected with nothing saved.
	 */
	public function test_platform_connected_without_key() {
		( new PageSpeed_Platform() )->register_hooks();
		$this->assertContains( 'specflux_mac_platforms', array_column( $GLOBALS['mock_filters'], 'hook' ) );

		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = array( new PageSpeed_Platform(), 'register_platform' );
		Platform_Registry::reset();

		$definition = Platform_Registry::get( 'pagespeed' );
		$this->assertNotNull( $definition );
		$this->assertSame( array( 'api_key' ), $definition['optional_fields'] );
		$this->assertSame( DAY_IN_SECONDS, $definition['cache_ttl'] );
		$this->assertTrue( ( new Credential_Manager() )->has_credentials( 'pagespeed' ) );
		$this->assertFalse( ( new Credential_Manager() )->has_credentials( 'clarity' ) );
	}

	/**
	 * Abilities return compact JSON and surface errors through Ability_Response.
	 */
	public function test_abilities_responses() {
		$client    = $this->client( array( new Response( 200, array(), $this->fixture() ) ) );
		$abilities = new class( $client ) extends PageSpeed_Abilities {
			/**
			 * Client.
			 *
			 * @var PageSpeed_Client
			 */
			private $stub;

			/**
			 * Constructor.
			 *
			 * @param PageSpeed_Client $stub Client.
			 */
			public function __construct( $stub ) {
				$this->stub = $stub;
			}

			/**
			 * Return the stub.
			 *
			 * @return PageSpeed_Client
			 */
			protected function make_client() {
				return $this->stub;
			}

			/**
			 * Fixed page list.
			 *
			 * @param int $limit Limit.
			 * @return array
			 */
			protected function pick_pages( $limit ) {
				return array(
					'source' => 'home_and_latest_posts',
					'pages'  => array(
						array(
							'path'  => '/',
							'views' => null,
						),
						array(
							'path'  => 'https://evil.example.org/',
							'views' => null,
						),
					),
				);
			}
		};

		$cwv  = json_decode( $abilities->execute_get_core_web_vitals( array() )['content'][0]['text'], true );
		$this->assertSame( 'failed', $cwv['page_assessment'] );
		$this->assertSame( 'passed', $cwv['origin_assessment'] );

		$audit = json_decode( $abilities->execute_audit_top_pages_speed( array( 'limit' => 2 ) )['content'][0]['text'], true );
		$this->assertSame( 62, $audit['rows'][0]['score'] );
		$this->assertArrayHasKey( 'error', $audit['rows'][1] );
		$this->assertFalse( $audit['partial'] );

		$bad = $abilities->execute_get_page_speed( array( 'url' => 'https://evil.example.org/' ) );
		$this->assertTrue( $bad['isError'] );
	}

	/**
	 * With no time budget the audit stops early and says so.
	 */
	public function test_audit_partial_when_out_of_time() {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_pagespeed_time_budget'] = static function () {
				return 1;
			};

		$abilities = new class( $this->client( array() ) ) extends PageSpeed_Abilities {
			/**
			 * Client.
			 *
			 * @var PageSpeed_Client
			 */
			private $stub;

			/**
			 * Constructor.
			 *
			 * @param PageSpeed_Client $stub Client.
			 */
			public function __construct( $stub ) {
				$this->stub = $stub;
			}

			/**
			 * Return the stub.
			 *
			 * @return PageSpeed_Client
			 */
			protected function make_client() {
				return $this->stub;
			}
		};

		$audit = json_decode( $abilities->execute_audit_top_pages_speed( array( 'limit' => 3 ) )['content'][0]['text'], true );
		$this->assertTrue( $audit['partial'] );
		$this->assertStringContainsString( 'Stopped early', $audit['note'] );
	}
}
}

namespace {
if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Minimal home_url stand-in for tests.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://example.com' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
	}
}
}
