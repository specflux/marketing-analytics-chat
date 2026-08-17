<?php
/**
 * Tests for the hosted (Specflux proxy) Google OAuth flow in OAuth_Handler.
 *
 * @package Specflux_Marketing_Analytics
 */

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Credentials\OAuth_Handler;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

/**
 * Hosted OAuth flow tests.
 */
class OAuthHandlerHostedTest extends TestCase {

	/**
	 * Handler under test.
	 *
	 * @var OAuth_Handler
	 */
	private $handler;

	/**
	 * Reset mock option store and HTTP mocks.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options                   = array();
		$GLOBALS['mock_http_requests']  = array();
		$GLOBALS['mock_http_handler']   = null;
		$GLOBALS['mock_filters']        = array();
		$this->handler                  = new OAuth_Handler();
	}

	/**
	 * Build a mock HTTP response.
	 *
	 * @param int   $code HTTP status.
	 * @param array $body JSON body.
	 * @return array
	 */
	private function response( $code, array $body ) {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $body ),
		);
	}

	public function test_hosted_mode_is_default_when_no_client_configured(): void {
		$this->assertTrue( $this->handler->uses_hosted_auth() );
		$this->assertTrue( $this->handler->can_connect_google() );
		$this->assertSame( OAuth_Handler::DEFAULT_PROXY_URL, $this->handler->get_proxy_url() );
	}

	public function test_custom_client_takes_precedence_over_hosted(): void {
		$this->handler->set_oauth_credentials( 'abc.apps.googleusercontent.com', 'GOCSPX-secret' );
		$this->assertFalse( $this->handler->uses_hosted_auth() );
		$this->assertTrue( $this->handler->can_connect_google() );
	}

	public function test_get_auth_url_hosted_stores_nonce_and_returns_proxy_url(): void {
		$GLOBALS['mock_http_handler'] = function ( $url, $args ) {
			$this->assertSame( OAuth_Handler::DEFAULT_PROXY_URL . '/oauth/google/start', $url );
			$body = json_decode( $args['body'], true );
			$this->assertSame( 'ga4', $body['service'] );
			$this->assertStringContainsString( 'oauth_callback=1', $body['return_url'] );
			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $body['nonce'] );
			return $this->response( 200, array( 'success' => true, 'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth?state=x' ) );
		};

		$url = $this->handler->get_auth_url( 'ga4' );

		$this->assertSame( 'https://accounts.google.com/o/oauth2/v2/auth?state=x', $url );
		$pending = get_option( OAuth_Handler::OPTION_HOSTED_NONCE );
		$this->assertSame( 'ga4', $pending['service'] );
		$this->assertNotEmpty( $pending['nonce'] );
	}

	public function test_get_auth_url_hosted_returns_null_when_proxy_unreachable(): void {
		// Default mock handler returns WP_Error.
		$this->assertNull( $this->handler->get_auth_url( 'gsc' ) );
	}

	public function test_hosted_callback_rejects_mismatched_nonce(): void {
		update_option( OAuth_Handler::OPTION_HOSTED_NONCE, array( 'nonce' => 'expectedNonceValue1', 'service' => 'ga4', 'created' => time() ), false );

		$result = $this->handler->handle_hosted_callback( str_repeat( 'a', 48 ), 'wrongNonceValue0000', 'ga4' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( array(), $GLOBALS['mock_http_requests'], 'No exchange request must be made on nonce mismatch' );
		$this->assertFalse( get_option( OAuth_Handler::OPTION_HOSTED_NONCE ), 'Pending nonce is single-use' );
	}

	public function test_hosted_callback_rejects_service_mismatch_and_stale_nonce(): void {
		update_option( OAuth_Handler::OPTION_HOSTED_NONCE, array( 'nonce' => 'n0000000000000001', 'service' => 'ga4', 'created' => time() ), false );
		$this->assertFalse( $this->handler->handle_hosted_callback( str_repeat( 'a', 48 ), 'n0000000000000001', 'gsc' )['success'] );

		update_option( OAuth_Handler::OPTION_HOSTED_NONCE, array( 'nonce' => 'n0000000000000002', 'service' => 'ga4', 'created' => time() - 3600 ), false );
		$this->assertFalse( $this->handler->handle_hosted_callback( str_repeat( 'a', 48 ), 'n0000000000000002', 'ga4' )['success'] );
		$this->assertSame( array(), $GLOBALS['mock_http_requests'] );
	}

	public function test_hosted_callback_exchanges_handoff_and_saves_tokens(): void {
		update_option( OAuth_Handler::OPTION_HOSTED_NONCE, array( 'nonce' => 'goodNonceValue00001', 'service' => 'gsc', 'created' => time() ), false );

		$GLOBALS['mock_http_handler'] = function ( $url, $args ) {
			$this->assertSame( OAuth_Handler::DEFAULT_PROXY_URL . '/oauth/google/exchange', $url );
			$body = json_decode( $args['body'], true );
			$this->assertSame( 'goodNonceValue00001', $body['nonce'] );
			return $this->response(
				200,
				array(
					'success' => true,
					'service' => 'gsc',
					'token'   => array(
						'access_token'  => 'ya29.ACCESS',
						'refresh_token' => '1//REFRESH',
						'expires_in'    => 3599,
						'token_type'    => 'Bearer',
					),
				)
			);
		};

		$result = $this->handler->handle_hosted_callback( str_repeat( 'b', 48 ), 'goodNonceValue00001', 'gsc' );

		$this->assertTrue( $result['success'], $result['message'] );
		$this->assertSame( 'gsc', $result['service'] );

		$stored = ( new Credential_Manager() )->get_credentials( 'gsc' );
		$this->assertSame( 'ya29.ACCESS', $stored['access_token'] );
		$this->assertSame( '1//REFRESH', $stored['refresh_token'] );
		$this->assertSame( 'hosted', $stored['auth_mode'] );
		$this->assertGreaterThan( time(), $stored['expires_at'] );
		$this->assertSame( 'ya29.ACCESS', $this->handler->get_access_token( 'gsc' ) );
	}

	public function test_hosted_callback_surfaces_proxy_error(): void {
		update_option( OAuth_Handler::OPTION_HOSTED_NONCE, array( 'nonce' => 'goodNonceValue00002', 'service' => 'ga4', 'created' => time() ), false );
		$GLOBALS['mock_http_handler'] = function () {
			return $this->response( 404, array( 'success' => false, 'error' => 'Handoff not found, already used, or expired.' ) );
		};

		$result = $this->handler->handle_hosted_callback( str_repeat( 'c', 48 ), 'goodNonceValue00002', 'ga4' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Handoff not found', $result['message'] );
		$this->assertFalse( ( new Credential_Manager() )->has_credentials( 'ga4' ) );
	}

	public function test_expired_hosted_token_is_refreshed_via_proxy(): void {
		( new Credential_Manager() )->save_credentials(
			'ga4',
			array(
				'access_token'  => 'old',
				'refresh_token' => '1//REFRESH',
				'expires_at'    => time() - 10,
				'auth_mode'     => 'hosted',
			)
		);
		$GLOBALS['mock_http_handler'] = function ( $url, $args ) {
			$this->assertSame( OAuth_Handler::DEFAULT_PROXY_URL . '/oauth/google/refresh', $url );
			$this->assertSame( '1//REFRESH', json_decode( $args['body'], true )['refresh_token'] );
			return $this->response( 200, array( 'success' => true, 'token' => array( 'access_token' => 'fresh', 'expires_in' => 3599 ) ) );
		};

		$this->assertSame( 'fresh', $this->handler->get_access_token( 'ga4' ) );

		$stored = ( new Credential_Manager() )->get_credentials( 'ga4' );
		$this->assertSame( '1//REFRESH', $stored['refresh_token'], 'Refresh token must be preserved' );
		$this->assertSame( 'hosted', $stored['auth_mode'] );
	}

	public function test_hosted_refresh_failure_returns_null_token(): void {
		( new Credential_Manager() )->save_credentials(
			'ga4',
			array( 'access_token' => 'old', 'refresh_token' => 'REVOKED', 'expires_at' => time() - 10, 'auth_mode' => 'hosted' )
		);
		$GLOBALS['mock_http_handler'] = function () {
			return $this->response( 401, array( 'success' => false, 'error' => 'invalid_grant' ) );
		};

		$this->assertNull( $this->handler->get_access_token( 'ga4' ) );
	}

	public function test_hosted_revoke_hits_google_directly_and_deletes_credentials(): void {
		( new Credential_Manager() )->save_credentials(
			'gsc',
			array( 'access_token' => 'tok', 'refresh_token' => '1//REFRESH', 'expires_at' => time() + 3600, 'auth_mode' => 'hosted' )
		);
		$GLOBALS['mock_http_handler'] = function () {
			return $this->response( 200, array() );
		};

		$this->assertTrue( $this->handler->revoke_access( 'gsc' ) );
		$this->assertFalse( ( new Credential_Manager() )->has_credentials( 'gsc' ) );

		$urls = array_column( $GLOBALS['mock_http_requests'], 'url' );
		$this->assertContains( OAuth_Handler::GOOGLE_REVOKE_URL, $urls );
		$this->assertNotContains( OAuth_Handler::DEFAULT_PROXY_URL . '/oauth/google/refresh', $urls );
	}

	public function test_describe_hosted_error_messages(): void {
		$this->assertStringContainsString( 'cancelled', $this->handler->describe_hosted_error( 'access_denied' ) );
		$this->assertStringContainsString( 'invalid_grant', $this->handler->describe_hosted_error( 'invalid_grant' ) );
	}
}
