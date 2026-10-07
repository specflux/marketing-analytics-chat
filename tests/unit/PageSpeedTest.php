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
		unset( $GLOBALS['specflux_mac_test_filters'], $GLOBALS['specflux_mac_test_cron'], $GLOBALS['specflux_mac_test_cron_args'], $GLOBALS['specflux_mac_test_single'], $GLOBALS['specflux_mac_test_spawn_cron'] );
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

		$this->assertSame( 32, $summary['score'] );
		$this->assertSame( "10.9\u{a0}s", $summary['lab']['lcp']['display_value'] );
		$this->assertEqualsWithDelta( 1055.2, $summary['lab']['tbt']['value'], 0.1 );
		$this->assertArrayHasKey( 'tti', $summary['lab'] );

		$this->assertSame( 2900, $summary['field']['metrics']['lcp_ms']['percentile'] );
		$this->assertSame( 0.04, $summary['field']['metrics']['cls']['percentile'] );
		$this->assertSame( 'AVERAGE', $summary['field']['overall_category'] );
		$this->assertSame( 'FAST', $summary['origin']['overall_category'] );
		$this->assertSame( 'failed', PageSpeed_Client::assess( $summary['field'] ) );
		$this->assertSame( 'passed', PageSpeed_Client::assess( $summary['origin'] ) );

		$this->assertSame(
			array( 'unused-javascript', 'unminified-javascript', 'mainthread-work-breakdown', 'bf-cache', 'unsized-images' ),
			array_column( $summary['issues'], 'id' )
		);
		$this->assertSame( 580, $summary['issues'][0]['savings_ms'] );
		$this->assertSame( 94194, $summary['issues'][0]['savings_bytes'] );
		$this->assertNull( $summary['issues'][2]['savings_ms'] );
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
		$this->assertSame( 32, $summary['score'] );
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
			$this->assertStringContainsString( 'quota used up', $e->getMessage() );
			$this->assertStringContainsString( 'try again tomorrow', $e->getMessage() );
		}

		$this->assertSame( 32, $client->get_summary( '', 'mobile' )['score'] );
	}

	/**
	 * A rejected key gets an actionable message.
	 */
	public function test_invalid_key_error() {
		$client = $this->client( array( new Response( 400, array(), '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","errors":[{"reason":"badRequest"}],"details":[{"reason":"API_KEY_INVALID"}]}}' ) ), 'bad' );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'PageSpeed Insights API is enabled' );
		$client->get_summary( '', 'mobile' );
	}

	/**
	 * A 403 (API disabled / key restricted) gets the same guidance.
	 */
	public function test_forbidden_error() {
		$client = $this->client( array( new Response( 403, array(), '{"error":{"code":403,"message":"PageSpeed Insights API has not been used in project"}}' ) ), 'k' );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Check the key' );
		$client->get_summary( '', 'mobile' );
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
	 * The key is required: pagespeed is not connected until one is saved.
	 */
	public function test_platform_requires_key() {
		( new PageSpeed_Platform() )->register_hooks();
		$this->assertContains( 'specflux_mac_platforms', array_column( $GLOBALS['mock_filters'], 'hook' ) );

		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = array( new PageSpeed_Platform(), 'register_platform' );
		Platform_Registry::reset();

		$definition = Platform_Registry::get( 'pagespeed' );
		$this->assertNotNull( $definition );
		$this->assertSame( array( 'api_key' ), $definition['fields'] );
		$this->assertSame( array(), $definition['optional_fields'] );
		$this->assertNotEmpty( $definition['prompt_hint'] );
		$this->assertSame( 'https://developers.google.com/speed/docs/insights/v5/get-started', $definition['help_url'] );
		$this->assertSame( DAY_IN_SECONDS, $definition['cache_ttl'] );
		$this->assertFalse( ( new Credential_Manager() )->has_credentials( 'pagespeed' ) );
		$this->assertFalse( ( new Credential_Manager() )->has_credentials( 'clarity' ) );
	}

	/**
	 * A platform whose fields are all optional counts as connected without saved values.
	 */
	public function test_all_optional_platform_is_connected() {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = static function ( $platforms ) {
			$platforms['optional_only'] = array(
				'slug'            => 'optional_only',
				'auth'            => 'api_key',
				'fields'          => array( 'api_key' ),
				'optional_fields' => array( 'api_key' ),
			);
			return $platforms;
		};
		Platform_Registry::reset();

		$this->assertTrue( ( new Credential_Manager() )->has_credentials( 'optional_only' ) );
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

		// The CWV call above cached the home page, so the audit ranks it and flags the bad URL.
		$audit = json_decode( $abilities->execute_audit_top_pages_speed( array( 'limit' => 2 ) )['content'][0]['text'], true );
		$this->assertSame( 32, $audit['rows'][0]['score'] );
		$this->assertArrayHasKey( 'error', $audit['rows'][1] );
		$this->assertTrue( $audit['complete'] );

		$bad = $abilities->execute_get_page_speed( array( 'url' => 'https://evil.example.org/' ) );
		$this->assertTrue( $bad['isError'] );
	}

	/**
	 * Abilities over a stub client with a fixed page list.
	 *
	 * @param PageSpeed_Client $client Client.
	 * @param array            $paths  Page paths.
	 * @return PageSpeed_Abilities
	 */
	private function abilities_for( PageSpeed_Client $client, array $paths = array( '/' ) ) {
		return new class( $client, $paths ) extends PageSpeed_Abilities {
			/**
			 * Client.
			 *
			 * @var PageSpeed_Client
			 */
			private $stub;

			/**
			 * Paths.
			 *
			 * @var array
			 */
			private $paths;

			/**
			 * Constructor.
			 *
			 * @param PageSpeed_Client $stub  Client.
			 * @param array            $paths Paths.
			 */
			public function __construct( $stub, $paths ) {
				$this->stub  = $stub;
				$this->paths = $paths;
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
			 * Fixed pages.
			 *
			 * @param int $limit Limit.
			 * @return array
			 */
			protected function pick_pages( $limit ) {
				$pages = array();
				foreach ( $this->paths as $path ) {
					$pages[] = array(
						'path'  => $path,
						'views' => null,
					);
				}
				return array(
					'source' => 'home_and_latest_posts',
					'pages'  => $pages,
				);
			}
		};
	}

	/**
	 * Decode a tool result.
	 *
	 * @param array $result Tool result.
	 * @return array
	 */
	private function decode( $result ) {
		return json_decode( $result['content'][0]['text'], true );
	}

	/**
	 * Run a callback with a short PHP time limit.
	 *
	 * @param callable $fn Callback.
	 */
	private function with_low_time( callable $fn ) {
		$old = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '30' ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		try {
			$fn();
		} finally {
			ini_set( 'max_execution_time', (string) $old ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
	}

	/**
	 * Cached results are returned unchanged, even with little PHP time left.
	 */
	public function test_cached_path_unchanged() {
		$client = $this->client( array( new Response( 200, array(), $this->fixture() ) ) );
		$client->get_summary( '/', 'mobile' );
		$this->assertCount( 1, $this->history );

		$this->with_low_time(
			function () use ( $client ) {
				$out = $this->decode( $this->abilities_for( $client )->execute_get_page_speed( array( 'url' => '/' ) ) );
				$this->assertSame( 32, $out['score'] );
				$this->assertArrayNotHasKey( 'status', $out );
			}
		);
		$this->assertCount( 1, $this->history );
		$this->assertArrayNotHasKey( 'specflux_mac_test_single', $GLOBALS );
	}

	/**
	 * Uncached with little time: queued, no HTTP call, no fake score.
	 */
	public function test_uncached_low_time_is_queued() {
		$client = $this->client( array() );

		$this->with_low_time(
			function () use ( $client ) {
				$abilities = $this->abilities_for( $client );
				$out       = $this->decode( $abilities->execute_get_page_speed( array( 'url' => '/' ) ) );
				$this->assertSame( 'queued', $out['status'] );
				$this->assertSame( 'https://example.com/', $out['url'] );
				$this->assertSame( 'mobile', $out['strategy'] );
				$this->assertSame( 60, $out['retry_after_seconds'] );
				$this->assertArrayNotHasKey( 'score', $out );

				$cwv = $this->decode( $abilities->execute_get_core_web_vitals( array( 'url' => '/pricing/', 'strategy' => 'desktop' ) ) );
				$this->assertSame( 'queued', $cwv['status'] );
				$this->assertArrayNotHasKey( 'page_assessment', $cwv );
			}
		);
		$this->assertCount( 0, $this->history );
		$this->assertSame( PageSpeed_Client::CRON_HOOK, $GLOBALS['specflux_mac_test_single'][0][0] );
		$this->assertSame( array( 'https://example.com/', 'mobile' ), $GLOBALS['specflux_mac_test_single'][0][1] );
		$this->assertSame( 2, $GLOBALS['specflux_mac_test_spawn_cron'] );
	}

	/**
	 * Uncached with enough time (unlimited) runs inline; the filter can veto that.
	 */
	public function test_uncached_enough_time_runs_sync_unless_filtered() {
		$client = $this->client( array( new Response( 200, array(), $this->fixture() ) ) );
		$out    = $this->decode( $this->abilities_for( $client )->execute_get_page_speed( array( 'url' => '/' ) ) );
		$this->assertSame( 32, $out['score'] );
		$this->assertCount( 1, $this->history );
		$this->assertArrayNotHasKey( 'specflux_mac_test_single', $GLOBALS );

		$GLOBALS['specflux_mac_test_filters']['specflux_mac_pagespeed_sync'] = static function () {
			return false;
		};
		$out = $this->decode( $this->abilities_for( $this->client( array() ) )->execute_get_page_speed( array( 'url' => '/about/' ) ) );
		$this->assertSame( 'queued', $out['status'] );
		$this->assertCount( 1, $this->history );
	}

	/**
	 * A second request does not schedule a duplicate while locked or already scheduled.
	 */
	public function test_queue_dedupes() {
		$client = $this->client( array() );
		$this->assertTrue( $client->queue( '/', 'mobile' ) );
		$this->assertFalse( $client->queue( '/', 'mobile' ) );
		$this->assertCount( 1, $GLOBALS['specflux_mac_test_single'] );

		// Lock gone but event still scheduled: still no duplicate.
		global $mock_transients;
		$mock_transients = array();
		$this->assertFalse( $client->queue( '/', 'mobile' ) );
		$this->assertCount( 1, $GLOBALS['specflux_mac_test_single'] );
		$this->assertSame( 1, $GLOBALS['specflux_mac_test_spawn_cron'] );
	}

	/**
	 * The cron hook stores the result in the cache and releases the lock.
	 */
	public function test_cron_hook_stores_result() {
		$stack  = HandlerStack::create( new MockHandler( array( new Response( 200, array(), $this->fixture() ) ) ) );
		$stack->push( Middleware::history( $this->history ) );
		$client = new PageSpeed_Client( '', new Client( array( 'handler' => $stack, 'http_errors' => false ) ) );
		$client->queue( '/', 'mobile' );
		$lock = 'specflux_mac_psi_lock_' . md5( 'https://example.com/|mobile' );
		$this->assertNotFalse( get_transient( $lock ) );
		$this->assertFalse( $client->is_cached( '/', 'mobile' ) );

		PageSpeed_Client::run_background( 'https://example.com/', 'mobile', $client );

		$this->assertTrue( $client->is_cached( '/', 'mobile' ) );
		$this->assertFalse( get_transient( $lock ) );
		$this->assertCount( 1, $this->history );
	}

	/**
	 * A failed background run is swallowed, not cached, and releases the lock.
	 */
	public function test_cron_hook_failure_is_not_cached() {
		$client = $this->client( array( new Response( 429, array(), '{"error":{"message":"quota"}}' ) ) );
		$client->queue( '/', 'mobile' );

		PageSpeed_Client::run_background( 'https://example.com/', 'mobile', $client );

		$this->assertFalse( $client->is_cached( '/', 'mobile' ) );
		$this->assertFalse( get_transient( 'specflux_mac_psi_lock_' . md5( 'https://example.com/|mobile' ) ) );
	}

	/**
	 * Audit ranks cached pages immediately and queues the rest.
	 */
	public function test_audit_mixes_cached_and_queued() {
		$client = $this->client( array( new Response( 200, array(), $this->fixture() ) ) );
		$client->get_summary( '/', 'mobile' );

		$audit = $this->decode( $this->abilities_for( $client, array( '/', '/pricing/', '/blog/' ) )->execute_audit_top_pages_speed( array( 'limit' => 3 ) ) );
		$this->assertCount( 1, $audit['rows'] );
		$this->assertSame( 32, $audit['rows'][0]['score'] );
		$this->assertFalse( $audit['complete'] );
		$this->assertSame( array( 'https://example.com/pricing/', 'https://example.com/blog/' ), $audit['queued'] );
		$this->assertNotEmpty( $audit['message'] );
		$this->assertCount( 2, $GLOBALS['specflux_mac_test_single'] );
		$this->assertCount( 1, $this->history );
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
