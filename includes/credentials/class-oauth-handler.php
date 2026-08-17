<?php
/**
 * OAuth Handler
 *
 * Handles OAuth 2.0 authentication flow for Google services (GA4 and GSC).
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Credentials;

use Specflux_Marketing_Analytics\Utils\Logger;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Manages OAuth 2.0 authentication for Google services
 */
class OAuth_Handler {

	/**
	 * Google OAuth Client ID option name
	 */
	const OPTION_CLIENT_ID = 'specflux_mac_google_client_id';

	/**
	 * Google OAuth Client Secret option name
	 */
	const OPTION_CLIENT_SECRET = 'specflux_mac_google_client_secret';

	/**
	 * OAuth state option name (for CSRF protection)
	 */
	const OPTION_OAUTH_STATE = 'specflux_mac_oauth_state';

	/**
	 * Pending hosted-OAuth nonce option name (for CSRF protection)
	 */
	const OPTION_HOSTED_NONCE = 'specflux_mac_oauth_hosted_nonce';

	/**
	 * Default hosted OAuth proxy base URL (Specflux-run Google OAuth client)
	 */
	const DEFAULT_PROXY_URL = 'https://api.specflux.com';

	/**
	 * Google token revocation endpoint (needs no client secret)
	 */
	const GOOGLE_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	/**
	 * OAuth scopes for Google Analytics 4
	 */
	const SCOPES_GA4 = array(
		'https://www.googleapis.com/auth/analytics.readonly',
	);

	/**
	 * OAuth scopes for Google Search Console
	 */
	const SCOPES_GSC = array(
		'https://www.googleapis.com/auth/webmasters.readonly',
	);

	/**
	 * Credential Manager instance
	 *
	 * @var Credential_Manager
	 */
	private $credential_manager;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->credential_manager = new Credential_Manager();
	}

	/**
	 * Initialize Google Client
	 *
	 * @param array $scopes OAuth scopes to request.
	 * @return \Google\Client|null Google Client instance or null on failure.
	 */
	private function init_google_client( $scopes = array() ) {
		$client_id     = get_option( self::OPTION_CLIENT_ID );
		$client_secret = get_option( self::OPTION_CLIENT_SECRET );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return null;
		}

		try {
			$client = new \Google\Client();
			$client->setClientId( $client_id );
			$client->setClientSecret( $client_secret );
			$client->setRedirectUri( $this->get_redirect_uri() );
			$client->setAccessType( 'offline' );
			$client->setPrompt( 'consent' );

			if ( ! empty( $scopes ) ) {
				$client->setScopes( $scopes );
			}

			return $client;
		} catch ( \Exception $e ) {
			Logger::debug( 'Failed to initialize Google Client: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Get OAuth authorization URL
	 *
	 * @param string $service Service identifier ('ga4' or 'gsc').
	 * @return string|null Authorization URL or null on failure.
	 */
	public function get_auth_url( $service ) {
		$scopes = $this->get_scopes_for_service( $service );

		if ( empty( $scopes ) ) {
			return null;
		}

		if ( $this->uses_hosted_auth() ) {
			return $this->get_hosted_auth_url( $service );
		}

		$client = $this->init_google_client( $scopes );

		if ( null === $client ) {
			return null;
		}

		// Generate and store state parameter for CSRF protection.
		$state = wp_generate_password( 32, false );
		update_option( self::OPTION_OAUTH_STATE, $state, false );

		$client->setState( $state . '|' . $service );

		return $client->createAuthUrl();
	}

	/**
	 * Handle OAuth callback
	 *
	 * @param string $code Authorization code from Google.
	 * @param string $state State parameter for CSRF validation.
	 * @return array|false Array with success status and message, or false on failure.
	 */
	public function handle_callback( $code, $state ) {
		// Validate state parameter.
		if ( ! $this->validate_state( $state ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid state parameter. Possible CSRF attack.', 'specflux-marketing-analytics-chat' ),
			);
		}

		// Extract service from state.
		$state_parts = explode( '|', $state );
		if ( count( $state_parts ) !== 2 ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid state format.', 'specflux-marketing-analytics-chat' ),
			);
		}

		$service = $state_parts[1];
		$scopes  = $this->get_scopes_for_service( $service );

		if ( empty( $scopes ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid service identifier.', 'specflux-marketing-analytics-chat' ),
			);
		}

		// Exchange code for tokens.
		$client = $this->init_google_client( $scopes );

		if ( null === $client ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to initialize Google Client.', 'specflux-marketing-analytics-chat' ),
			);
		}

		try {
			$token = $client->fetchAccessTokenWithAuthCode( $code );

			if ( isset( $token['error'] ) ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'OAuth error: %s', 'specflux-marketing-analytics-chat' ),
						$token['error']
					),
				);
			}

			// Save tokens.
			$this->save_tokens( $service, $token );

			// Clear state.
			delete_option( self::OPTION_OAUTH_STATE );

			return array(
				'success' => true,
				'message' => __( 'Successfully connected to Google services.', 'specflux-marketing-analytics-chat' ),
				'service' => $service,
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to exchange authorization code: %s', 'specflux-marketing-analytics-chat' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Get the hosted OAuth proxy base URL
	 *
	 * Empty string disables hosted mode. Override with the
	 * SPECFLUX_MAC_OAUTH_PROXY_URL constant or the filter below.
	 *
	 * @return string Proxy base URL without trailing slash.
	 */
	public function get_proxy_url() {
		$url = defined( 'SPECFLUX_MAC_OAUTH_PROXY_URL' ) ? SPECFLUX_MAC_OAUTH_PROXY_URL : self::DEFAULT_PROXY_URL;

		/**
		 * Filters the hosted Google OAuth proxy base URL.
		 *
		 * @param string $url Proxy base URL. Return an empty string to disable hosted mode.
		 */
		$url = apply_filters( 'specflux_mac_oauth_proxy_url', $url );

		return is_string( $url ) ? untrailingslashit( $url ) : '';
	}

	/**
	 * Whether new connections go through the hosted proxy
	 *
	 * A site that has entered its own Google Cloud OAuth client always uses
	 * that client; otherwise the hosted proxy is used when available.
	 *
	 * @return bool
	 */
	public function uses_hosted_auth() {
		return ! $this->has_oauth_credentials() && '' !== $this->get_proxy_url();
	}

	/**
	 * Whether the site can start a Google connection at all
	 *
	 * @return bool
	 */
	public function can_connect_google() {
		return $this->has_oauth_credentials() || '' !== $this->get_proxy_url();
	}

	/**
	 * Ask the hosted proxy for a Google consent URL
	 *
	 * @param string $service Service identifier ('ga4' or 'gsc').
	 * @return string|null Authorization URL or null on failure.
	 */
	private function get_hosted_auth_url( $service ) {
		$nonce = wp_generate_password( 32, false );

		update_option(
			self::OPTION_HOSTED_NONCE,
			array(
				'nonce'   => $nonce,
				'service' => $service,
				'created' => time(),
			),
			false
		);

		$response = $this->proxy_request(
			'/oauth/google/start',
			array(
				'service'    => $service,
				'return_url' => $this->get_redirect_uri(),
				'nonce'      => $nonce,
			)
		);

		if ( is_wp_error( $response ) || empty( $response['auth_url'] ) ) {
			Logger::debug( 'Hosted OAuth start failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_json_encode( $response ) ) );
			return null;
		}

		return esc_url_raw( $response['auth_url'] );
	}

	/**
	 * Handle the redirect back from the hosted proxy
	 *
	 * @param string $handoff Single-use handoff code issued by the proxy.
	 * @param string $nonce   Nonce echoed back by the proxy.
	 * @param string $service Service identifier echoed back by the proxy.
	 * @return array Array with success status and message.
	 */
	public function handle_hosted_callback( $handoff, $nonce, $service ) {
		$pending = get_option( self::OPTION_HOSTED_NONCE );
		delete_option( self::OPTION_HOSTED_NONCE );

		if ( empty( $pending['nonce'] ) || ! is_string( $nonce ) || ! hash_equals( $pending['nonce'], $nonce )
			|| ( $pending['service'] ?? '' ) !== $service
			|| time() - (int) ( $pending['created'] ?? 0 ) > 15 * MINUTE_IN_SECONDS
		) {
			return array(
				'success' => false,
				'message' => __( 'Invalid state parameter. Possible CSRF attack.', 'specflux-marketing-analytics-chat' ),
			);
		}

		if ( empty( $this->get_scopes_for_service( $service ) ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid service identifier.', 'specflux-marketing-analytics-chat' ),
			);
		}

		$response = $this->proxy_request(
			'/oauth/google/exchange',
			array(
				'handoff' => $handoff,
				'nonce'   => $nonce,
			)
		);

		if ( is_wp_error( $response ) || empty( $response['token']['refresh_token'] ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to complete Google sign-in: %s', 'specflux-marketing-analytics-chat' ),
					is_wp_error( $response ) ? $response->get_error_message() : ( $response['error'] ?? 'unknown' )
				),
			);
		}

		$token              = $response['token'];
		$token['auth_mode'] = 'hosted';
		$this->save_tokens( $service, $token );

		return array(
			'success' => true,
			'message' => __( 'Successfully connected to Google services.', 'specflux-marketing-analytics-chat' ),
			'service' => $service,
		);
	}

	/**
	 * Translate an error code passed back by the proxy into a message
	 *
	 * @param string $error_code Error code from the smac_oauth_error query arg.
	 * @return string Human-readable message.
	 */
	public function describe_hosted_error( $error_code ) {
		switch ( $error_code ) {
			case 'access_denied':
				return __( 'Google sign-in was cancelled. No changes were made.', 'specflux-marketing-analytics-chat' );
			case 'no_refresh_token':
				return __( 'Google did not issue a refresh token. Remove this app from your Google account permissions and try again.', 'specflux-marketing-analytics-chat' );
			default:
				return sprintf(
					/* translators: %s: error code */
					__( 'Google sign-in failed (%s). Please try again.', 'specflux-marketing-analytics-chat' ),
					$error_code
				);
		}
	}

	/**
	 * POST JSON to the hosted proxy
	 *
	 * @param string $path Endpoint path, e.g. '/oauth/google/start'.
	 * @param array  $body Request body.
	 * @return array|\WP_Error Decoded response, or WP_Error on transport / non-success.
	 */
	private function proxy_request( $path, $body ) {
		$proxy_url = $this->get_proxy_url();

		if ( '' === $proxy_url ) {
			return new \WP_Error( 'no_proxy', __( 'Hosted Google sign-in is not available.', 'specflux-marketing-analytics-chat' ) );
		}

		$response = wp_remote_post(
			$proxy_url . $path,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bad_response', __( 'Unexpected response from the sign-in service.', 'specflux-marketing-analytics-chat' ) );
		}

		if ( $code >= 400 || empty( $data['success'] ) ) {
			return new \WP_Error(
				'proxy_error',
				isset( $data['error'] ) ? sanitize_text_field( $data['error'] ) : sprintf( 'HTTP %d', $code ),
				array( 'status' => $code )
			);
		}

		return $data;
	}

	/**
	 * Get access token for a service
	 *
	 * @param string $service Service identifier ('ga4' or 'gsc').
	 * @return string|null Access token or null if not available.
	 */
	public function get_access_token( $service ) {
		$credentials = $this->credential_manager->get_credentials( $service );

		if ( empty( $credentials ) || ! isset( $credentials['access_token'] ) ) {
			return null;
		}

		// Check if token is expired.
		if ( isset( $credentials['expires_at'] ) && time() >= $credentials['expires_at'] ) {
			// Token expired, try to refresh.
			if ( $this->refresh_token( $service ) ) {
				$credentials = $this->credential_manager->get_credentials( $service );
				return $credentials['access_token'] ?? null;
			}

			return null;
		}

		return $credentials['access_token'];
	}

	/**
	 * Refresh access token
	 *
	 * @param string $service Service identifier ('ga4' or 'gsc').
	 * @return bool True on success, false on failure.
	 */
	public function refresh_token( $service ) {
		$credentials = $this->credential_manager->get_credentials( $service );

		if ( empty( $credentials ) || ! isset( $credentials['refresh_token'] ) ) {
			return false;
		}

		if ( 'hosted' === ( $credentials['auth_mode'] ?? '' ) ) {
			return $this->refresh_hosted_token( $service, $credentials );
		}

		$scopes = $this->get_scopes_for_service( $service );
		$client = $this->init_google_client( $scopes );

		if ( null === $client ) {
			return false;
		}

		try {
			$client->setAccessToken( $credentials );
			$new_token = $client->fetchAccessTokenWithRefreshToken( $credentials['refresh_token'] );

			if ( isset( $new_token['error'] ) ) {
				Logger::debug( 'Token refresh error for ' . $service . ': ' . $new_token['error'] );
				return false;
			}

			// Merge new token with existing credentials (preserve refresh_token).
			$updated_credentials = array_merge( $credentials, $new_token );

			$this->save_tokens( $service, $updated_credentials );

			return true;
		} catch ( \Exception $e ) {
			Logger::debug( 'Failed to refresh token for ' . $service . ': ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Refresh an access token through the hosted proxy
	 *
	 * @param string $service     Service identifier.
	 * @param array  $credentials Stored credentials including refresh_token.
	 * @return bool True on success, false on failure.
	 */
	private function refresh_hosted_token( $service, $credentials ) {
		$response = $this->proxy_request(
			'/oauth/google/refresh',
			array( 'refresh_token' => $credentials['refresh_token'] )
		);

		if ( is_wp_error( $response ) || empty( $response['token']['access_token'] ) ) {
			Logger::debug( 'Hosted token refresh error for ' . $service . ': ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'no access token' ) );
			return false;
		}

		$this->save_tokens( $service, array_merge( $credentials, $response['token'] ) );

		return true;
	}

	/**
	 * Revoke OAuth access
	 *
	 * @param string $service Service identifier ('ga4' or 'gsc').
	 * @return bool True on success, false on failure.
	 */
	public function revoke_access( $service ) {
		$credentials  = $this->credential_manager->get_credentials( $service );
		$access_token = $this->get_access_token( $service );

		if ( ! $access_token ) {
			// No token to revoke, just delete credentials.
			return $this->credential_manager->delete_credentials( $service );
		}

		$scopes = $this->get_scopes_for_service( $service );
		$client = 'hosted' === ( $credentials['auth_mode'] ?? '' ) ? null : $this->init_google_client( $scopes );

		if ( null !== $client ) {
			try {
				$client->revokeToken( $access_token );
			} catch ( \Exception $e ) {
				Logger::debug( 'Failed to revoke token for ' . $service . ': ' . $e->getMessage() );
			}
		} else {
			// Revocation needs no client secret, so hosted tokens are revoked directly.
			wp_remote_post(
				self::GOOGLE_REVOKE_URL,
				array(
					'timeout' => 10,
					'body'    => array( 'token' => $credentials['refresh_token'] ?? $access_token ),
				)
			);
		}

		// Delete stored credentials.
		return $this->credential_manager->delete_credentials( $service );
	}

	/**
	 * Save OAuth tokens
	 *
	 * @param string $service Service identifier.
	 * @param array  $token Token data from Google.
	 * @return bool True on success.
	 */
	private function save_tokens( $service, $token ) {
		// Calculate token expiration time.
		if ( isset( $token['expires_in'] ) ) {
			$token['expires_at'] = time() + $token['expires_in'];
		}

		return $this->credential_manager->save_credentials( $service, $token );
	}

	/**
	 * Validate OAuth state parameter
	 *
	 * @param string $state State parameter to validate.
	 * @return bool True if valid, false otherwise.
	 */
	private function validate_state( $state ) {
		$stored_state = get_option( self::OPTION_OAUTH_STATE );

		if ( empty( $stored_state ) ) {
			return false;
		}

		$state_parts = explode( '|', $state );
		if ( empty( $state_parts ) ) {
			return false;
		}

		return hash_equals( $stored_state, $state_parts[0] );
	}

	/**
	 * Get scopes for a service
	 *
	 * @param string $service Service identifier.
	 * @return array OAuth scopes.
	 */
	private function get_scopes_for_service( $service ) {
		switch ( $service ) {
			case 'ga4':
				return self::SCOPES_GA4;
			case 'gsc':
				return self::SCOPES_GSC;
			default:
				return array();
		}
	}

	/**
	 * Get OAuth redirect URI
	 *
	 * @return string Redirect URI.
	 */
	public function get_redirect_uri() {
		return admin_url( 'admin.php?page=specflux-mac-connections&oauth_callback=1' );
	}

	/**
	 * Set Google OAuth credentials
	 *
	 * @param string $client_id OAuth client ID.
	 * @param string $client_secret OAuth client secret (empty to keep existing).
	 * @return bool True on success.
	 */
	public function set_oauth_credentials( $client_id, $client_secret ) {
		// Always update client ID if provided.
		$id_updated = update_option( self::OPTION_CLIENT_ID, sanitize_text_field( $client_id ), false );

		// Only update secret if provided (allows keeping existing secret).
		if ( ! empty( $client_secret ) ) {
			$secret_updated = update_option( self::OPTION_CLIENT_SECRET, sanitize_text_field( $client_secret ), false );
		} else {
			// Keep existing secret - check if one exists.
			$existing_secret = get_option( self::OPTION_CLIENT_SECRET );
			$secret_updated  = ! empty( $existing_secret );
		}

		return $id_updated || $secret_updated;
	}

	/**
	 * Check if OAuth credentials are configured
	 *
	 * @return bool True if configured, false otherwise.
	 */
	public function has_oauth_credentials() {
		$client_id     = get_option( self::OPTION_CLIENT_ID );
		$client_secret = get_option( self::OPTION_CLIENT_SECRET );

		return ! empty( $client_id ) && ! empty( $client_secret );
	}

	/**
	 * Get configured OAuth client ID (for display purposes)
	 *
	 * @return string|null Client ID or null if not set.
	 */
	public function get_client_id() {
		return get_option( self::OPTION_CLIENT_ID );
	}

	/**
	 * Check if service has valid access token
	 *
	 * @param string $service Service name ('ga4' or 'gsc').
	 *
	 * @return bool True if has valid token, false otherwise.
	 */
	public function has_access_token( $service ) {
		$credentials = $this->credential_manager->get_credentials( $service );
		return ! empty( $credentials['access_token'] );
	}
}
