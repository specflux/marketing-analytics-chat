<?php
/**
 * Tests for the Ajax_Handler class.
 *
 * These drive the PUBLIC AJAX callbacks (no reflection) and assert on the
 * payloads recorded by the wp_send_json_success()/wp_send_json_error() mocks
 * in tests/bootstrap.php.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Admin\Ajax_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Ajax Handler test class.
 */
class AjaxHandlerTest extends TestCase {

	/**
	 * Handler under test.
	 *
	 * @var Ajax_Handler
	 */
	private $handler;

	/**
	 * Set up a clean request/response environment for every test.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $mock_json_responses, $mock_options, $mock_transients, $mock_nonce_valid, $mock_user_can, $wpdb;

		$mock_json_responses = array();
		$mock_options        = array();
		$mock_transients     = array();
		$mock_nonce_valid    = true;
		$mock_user_can       = true;
		$wpdb->queries       = array();

		$_POST = array();

		$this->handler = new Ajax_Handler();
	}

	/**
	 * Restore globals so later tests are not affected.
	 */
	protected function tearDown(): void {
		global $mock_nonce_valid, $mock_user_can, $wpdb;

		$mock_nonce_valid = true;
		$mock_user_can    = true;
		$wpdb->queries    = array();

		$_POST = array();

		parent::tearDown();
	}

	/**
	 * Get the recorded JSON responses.
	 *
	 * @return array
	 */
	private function responses() {
		global $mock_json_responses;
		return is_array( $mock_json_responses ) ? $mock_json_responses : array();
	}

	/**
	 * Get the single recorded JSON response, failing if there is not exactly one.
	 *
	 * @return array
	 */
	private function single_response() {
		$responses = $this->responses();
		$this->assertCount( 1, $responses, 'Expected exactly one JSON response to be emitted.' );
		return $responses[0];
	}

	/**
	 * Count recorded DELETE queries against the options table.
	 *
	 * @return int
	 */
	private function delete_query_count() {
		global $wpdb;
		$count = 0;
		foreach ( $wpdb->queries as $query ) {
			if ( false !== stripos( $query, 'DELETE FROM' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/*
	 * ---------------------------------------------------------------------
	 * (a) Missing / bad nonce: error response AND no side effect.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A completely missing nonce must be rejected before any cache purge runs.
	 */
	public function test_clear_caches_rejects_missing_nonce_and_deletes_nothing(): void {
		// No $_POST['nonce'] at all.
		$this->handler->clear_caches();

		// Side effect first: the purge must not have happened at all.
		$this->assertSame( 0, $this->delete_query_count(), 'No cache purge may run without a nonce.' );

		$response = $this->single_response();

		$this->assertFalse( $response['success'], 'Missing nonce must produce an error response.' );
		$this->assertSame(
			'Security check failed. Please refresh the page and try again.',
			$response['data']['message']
		);
		$this->assertSame( 0, $this->delete_query_count(), 'No cache purge may run without a valid nonce.' );
	}

	/**
	 * A present-but-invalid nonce must be rejected before any cache purge runs.
	 */
	public function test_clear_caches_rejects_bad_nonce_and_deletes_nothing(): void {
		global $mock_nonce_valid;

		$_POST['nonce']   = 'not-a-real-nonce';
		$mock_nonce_valid = false;

		$this->handler->clear_caches();

		// Side effect first: the purge must not have happened at all.
		$this->assertSame( 0, $this->delete_query_count(), 'No cache purge may run with an invalid nonce.' );

		$response = $this->single_response();

		$this->assertFalse( $response['success'], 'Bad nonce must produce an error response.' );
		$this->assertSame(
			'Security check failed. Please refresh the page and try again.',
			$response['data']['message']
		);
		$this->assertSame( 0, $this->delete_query_count(), 'No cache purge may run with an invalid nonce.' );
	}

	/**
	 * The wizard dismissal must not write its option when the nonce is bad.
	 */
	public function test_dismiss_onboarding_wizard_rejects_bad_nonce_and_writes_no_option(): void {
		global $mock_nonce_valid, $mock_options;

		$_POST['nonce']   = 'not-a-real-nonce';
		$mock_nonce_valid = false;

		$this->handler->dismiss_onboarding_wizard();

		// Side effect first: the option must not have been written at all.
		$this->assertArrayNotHasKey(
			'specflux_mac_onboarding_complete',
			$mock_options,
			'The onboarding option must not be written when the nonce check fails.'
		);

		$response = $this->single_response();

		$this->assertFalse( $response['success'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * (b) Insufficient capability: error response AND no side effect.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A valid nonce is not enough — the capability check must also pass.
	 */
	public function test_clear_caches_rejects_insufficient_capability_and_deletes_nothing(): void {
		global $mock_user_can;

		$_POST['nonce'] = 'valid';
		$mock_user_can  = false;

		$this->handler->clear_caches();

		// Side effect first: the purge must not have happened at all.
		$this->assertSame( 0, $this->delete_query_count(), 'No cache purge may run without the plugin capability.' );

		$response = $this->single_response();

		$this->assertFalse( $response['success'], 'Missing capability must produce an error response.' );
		$this->assertSame(
			'You do not have permission to perform this action.',
			$response['data']['message']
		);
		$this->assertSame( 0, $this->delete_query_count(), 'No cache purge may run without the plugin capability.' );
	}

	/**
	 * The wizard dismissal must not write its option without the capability.
	 */
	public function test_dismiss_onboarding_wizard_rejects_insufficient_capability(): void {
		global $mock_user_can, $mock_options;

		$_POST['nonce'] = 'valid';
		$mock_user_can  = false;

		$this->handler->dismiss_onboarding_wizard();

		// Side effect first: the option must not have been written at all.
		$this->assertArrayNotHasKey(
			'specflux_mac_onboarding_complete',
			$mock_options,
			'The onboarding option must not be written without the plugin capability.'
		);

		$response = $this->single_response();

		$this->assertFalse( $response['success'] );
		$this->assertSame(
			'You do not have permission to perform this action.',
			$response['data']['message']
		);
	}

	/**
	 * The dashboard metrics callback is guarded by its own nonce action.
	 */
	public function test_refresh_dashboard_metrics_rejects_insufficient_capability(): void {
		global $mock_user_can;

		$_POST['nonce'] = 'valid';
		$mock_user_can  = false;

		$this->handler->handle_refresh_dashboard_metrics();

		$response = $this->single_response();

		$this->assertFalse( $response['success'] );
		$this->assertSame(
			'You do not have permission to perform this action.',
			$response['data']['message']
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * (c) Happy paths.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * An authorised clear_caches request purges caches and returns a message.
	 */
	public function test_clear_caches_success_runs_delete_and_returns_message(): void {
		$_POST['nonce'] = 'valid';

		$this->handler->clear_caches();

		$response = $this->single_response();

		$this->assertTrue( $response['success'], 'Authorised request must produce a success response.' );
		$this->assertIsArray( $response['data'] );
		$this->assertArrayHasKey( 'message', $response['data'] );
		$this->assertStringContainsString( 'cache entries', $response['data']['message'] );
		$this->assertSame( 1, $this->delete_query_count(), 'Exactly one cache purge query is expected.' );
	}

	/**
	 * An authorised dismissal writes the option and returns a success payload.
	 */
	public function test_dismiss_onboarding_wizard_success_writes_option(): void {
		global $mock_options;

		$_POST['nonce'] = 'valid';

		$this->handler->dismiss_onboarding_wizard();

		$response = $this->single_response();

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'message' => 'Onboarding wizard dismissed.' ), $response['data'] );
		$this->assertArrayHasKey( 'specflux_mac_onboarding_complete', $mock_options );
		$this->assertTrue( $mock_options['specflux_mac_onboarding_complete'] );
	}

	/**
	 * The dashboard metrics callback returns the expected payload shape.
	 */
	public function test_refresh_dashboard_metrics_success_payload_shape(): void {
		$_POST['nonce'] = 'valid';

		$this->handler->handle_refresh_dashboard_metrics();

		$response = $this->single_response();

		$this->assertTrue( $response['success'] );
		$this->assertIsArray( $response['data'] );
		$this->assertSame( 'Metrics refreshed from cache.', $response['data']['message'] );
		$this->assertArrayHasKey( 'metrics', $response['data'] );
		$this->assertIsArray( $response['data']['metrics'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Error paths that must survive the refactor.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * An unknown platform still yields the original error message.
	 */
	public function test_test_connection_rejects_unsupported_platform(): void {
		$_POST['nonce']    = 'valid';
		$_POST['platform'] = 'bogus';

		$this->handler->test_connection();

		$response = $this->single_response();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Unsupported platform: bogus', $response['data']['message'] );
	}

	/**
	 * save_credentials rejects a platform it does not handle.
	 */
	public function test_save_credentials_rejects_unsupported_platform(): void {
		$_POST['nonce']    = 'valid';
		$_POST['platform'] = 'facebook';

		$this->handler->save_credentials();

		$response = $this->single_response();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Unsupported platform.', $response['data']['message'] );
	}

	/**
	 * Register a registry-only api_key platform for generic save tests.
	 */
	private function register_bing(): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = static function ( $platforms ) {
			$platforms['bing'] = array(
				'slug'            => 'bing',
				'label'           => 'Bing',
				'fields'          => array( 'api_key', 'site_url', 'note' ),
				'optional_fields' => array( 'note' ),
				'connection_test' => static function () {
					return array(
						'success' => true,
						'message' => 'Bing OK',
					);
				},
			);
			return $platforms;
		};
		\Specflux_Marketing_Analytics\Credentials\Platform_Registry::reset();
	}

	/**
	 * Remove the registered test platform.
	 */
	private function unregister_bing(): void {
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		\Specflux_Marketing_Analytics\Credentials\Platform_Registry::reset();
	}

	/**
	 * Generic save stores fields, allows blank optional fields and returns the test result.
	 */
	public function test_generic_save_registered_platform(): void {
		$this->register_bing();
		$_POST['nonce']    = 'valid';
		$_POST['platform'] = 'bing';
		$_POST['fields']   = array(
			'api_key'  => 'abc123',
			'site_url' => 'https://example.com',
			'note'     => '',
			'extra'    => 'ignored',
		);

		$this->handler->save_credentials();
		$response = $this->single_response();

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'Bing OK', $response['data']['message'] );

		$saved = ( new \Specflux_Marketing_Analytics\Credentials\Credential_Manager() )->get_credentials( 'bing' );
		$this->assertSame( 'abc123', $saved['api_key'] );
		$this->assertArrayNotHasKey( 'extra', $saved );
		$this->unregister_bing();
	}

	/**
	 * Generic save rejects blank required fields.
	 */
	public function test_generic_save_requires_required_fields(): void {
		$this->register_bing();
		$_POST['nonce']    = 'valid';
		$_POST['platform'] = 'bing';
		$_POST['fields']   = array(
			'api_key'  => 'abc123',
			'site_url' => '',
		);

		$this->handler->save_credentials();
		$response = $this->single_response();
		$this->unregister_bing();

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'site_url', $response['data']['message'] );
	}

	/**
	 * A blank secret keeps the stored value.
	 */
	public function test_generic_save_keeps_stored_secret_when_blank(): void {
		$this->register_bing();
		( new \Specflux_Marketing_Analytics\Credentials\Credential_Manager() )->save_credentials(
			'bing',
			array(
				'api_key'  => 'stored-key',
				'site_url' => 'https://old.example.com',
				'note'     => '',
			)
		);
		$_POST['nonce']    = 'valid';
		$_POST['platform'] = 'bing';
		$_POST['fields']   = array(
			'api_key'  => \Specflux_Marketing_Analytics\Credentials\Platform_Registry::SECRET_MASK,
			'site_url' => 'https://new.example.com',
		);

		$this->handler->save_credentials();
		$response = $this->single_response();

		$this->assertTrue( $response['success'] );
		$saved = ( new \Specflux_Marketing_Analytics\Credentials\Credential_Manager() )->get_credentials( 'bing' );
		$this->assertSame( 'stored-key', $saved['api_key'] );
		$this->assertSame( 'https://new.example.com', $saved['site_url'] );
		$this->unregister_bing();
	}

	/**
	 * Generic save rejects unregistered and Google OAuth platforms.
	 */
	public function test_generic_save_rejects_unregistered_and_oauth_platforms(): void {
		foreach ( array( 'nope', 'ga4' ) as $platform ) {
			$GLOBALS['mock_json_responses'] = array();
			$_POST['nonce']                 = 'valid';
			$_POST['platform']              = $platform;
			$_POST['fields']                = array( 'api_key' => 'x' );
			$this->handler->save_credentials();
			$response = $this->single_response();
			$this->assertFalse( $response['success'] );
			$this->assertSame( 'Unsupported platform.', $response['data']['message'] );
		}
	}

	/**
	 * save_ga4_property requires a property ID.
	 */
	public function test_save_ga4_property_requires_property_id(): void {
		$_POST['nonce'] = 'valid';

		$this->handler->save_ga4_property();

		$response = $this->single_response();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Please select a property.', $response['data']['message'] );
	}

	/**
	 * save_gsc_site requires a site URL.
	 */
	public function test_save_gsc_site_requires_site_url(): void {
		$_POST['nonce'] = 'valid';

		$this->handler->save_gsc_site();

		$response = $this->single_response();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Please select a site.', $response['data']['message'] );
	}

	/**
	 * Exactly one JSON response is emitted per call — the old code could fall
	 * through a wp_send_json_error() that had no return and emit twice.
	 */
	public function test_guard_failure_emits_exactly_one_response(): void {
		global $mock_user_can;

		$_POST['nonce'] = 'valid';
		$mock_user_can  = false;

		$this->handler->save_credentials();

		$this->assertCount( 1, $this->responses() );
	}

	/**
	 * The request log for test_connection must never contain a raw api_token.
	 */
	public function test_test_connection_redacts_api_token_from_logs(): void {
		$_POST['nonce']     = 'valid';
		$_POST['platform']  = 'bogus';
		$_POST['api_token'] = 'super-secret-token-value';

		$redacted = \Specflux_Marketing_Analytics\Utils\Logger::redact( $_POST );

		$this->assertSame( '[redacted]', $redacted['api_token'] );
		$this->assertSame( 'bogus', $redacted['platform'] );

		// And the callback itself still behaves.
		$this->handler->test_connection();
		$response = $this->single_response();
		$this->assertFalse( $response['success'] );
	}
}
