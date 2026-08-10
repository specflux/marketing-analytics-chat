<?php
/**
 * AJAX Handler for Admin Operations
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Admin;

use Specflux_Marketing_Analytics\API_Clients\Clarity_Client;
use Specflux_Marketing_Analytics\API_Clients\GA4_Client;
use Specflux_Marketing_Analytics\API_Clients\GSC_Client;
use Specflux_Marketing_Analytics\Credentials\Connection_Tester;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Credentials\OAuth_Handler;
use Specflux_Marketing_Analytics\Utils\Logger;
use Specflux_Marketing_Analytics\Utils\Permission_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Handles AJAX requests from admin interface
 *
 * Every public callback follows the same shape: verify the request with
 * verify_request(), then delegate the real work to a private do_* method that
 * either returns the success payload or throws with the user-facing message.
 * Only the public callbacks emit JSON.
 */
class Ajax_Handler {

	/**
	 * Register AJAX hooks
	 *
	 * @return void
	 */
	public function register_hooks() {
		Logger::debug( 'Registering AJAX hooks' );

		add_action( 'wp_ajax_specflux_mac_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_specflux_mac_save_credentials', array( $this, 'save_credentials' ) );
		add_action( 'wp_ajax_specflux_mac_clear_caches', array( $this, 'clear_caches' ) );
		add_action( 'wp_ajax_specflux_mac_list_ga4_properties', array( $this, 'list_ga4_properties' ) );
		add_action( 'wp_ajax_specflux_mac_save_ga4_property', array( $this, 'save_ga4_property' ) );
		add_action( 'wp_ajax_specflux_mac_list_gsc_sites', array( $this, 'list_gsc_sites' ) );
		add_action( 'wp_ajax_specflux_mac_save_gsc_site', array( $this, 'save_gsc_site' ) );

		// Dashboard widget refresh.
		add_action( 'wp_ajax_specflux_mac_refresh_widget', array( $this, 'handle_refresh_widget_data' ) );

		// Dashboard insights panel refresh (transient reads only).
		add_action( 'wp_ajax_specflux_mac_refresh_dashboard_metrics', array( $this, 'handle_refresh_dashboard_metrics' ) );

		// Onboarding wizard dismissal.
		add_action( 'wp_ajax_specflux_mac_dismiss_wizard', array( $this, 'dismiss_onboarding_wizard' ) );

		/**
		 * Allow pro add-on to register additional AJAX handlers.
		 */
		do_action( 'specflux_mac_register_ajax_handlers' );
	}

	/**
	 * Verify an incoming AJAX request.
	 *
	 * Runs the nonce check followed by the plugin capability check. On either
	 * failure a JSON error response is emitted and false is returned, so every
	 * caller can bail with a plain `return`.
	 *
	 * @param string $nonce_action Nonce action the request must be signed with.
	 * @return bool True when the request is authorised, false otherwise.
	 */
	private function verify_request( $nonce_action = 'specflux_mac_admin' ) {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), $nonce_action ) ) {
			Logger::error( 'Nonce verification failed' );
			wp_send_json_error(
				array(
					'message' => 'Security check failed. Please refresh the page and try again.',
				)
			);
			return false;
		}

		if ( ! Permission_Manager::can_access_plugin() ) {
			Logger::error( 'User lacks permissions' );
			wp_send_json_error(
				array(
					'message' => 'You do not have permission to perform this action.',
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Test platform connection
	 *
	 * @return void
	 */
	public function test_connection() {
		Logger::debug( '===== AJAX TEST CONNECTION REQUEST =====' );

		if ( ! $this->verify_request() ) {
			return;
		}

		try {
			wp_send_json_success( $this->do_test_connection() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Dispatch a connection test to the platform-specific tester.
	 *
	 * @return array Success payload for the JSON response.
	 */
	private function do_test_connection() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$request_data = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		Logger::debug( sprintf( 'Request data: %s', wp_json_encode( Logger::redact( $request_data ) ) ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$platform = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : '';
		Logger::debug( sprintf( 'Testing connection for platform: %s', $platform ) );

		// Use Connection_Tester for OAuth-based platforms (GA4, GSC).
		if ( in_array( $platform, array( 'ga4', 'gsc' ), true ) ) {
			return $this->test_oauth_platform_connection( $platform );
		}

		if ( 'clarity' === $platform ) {
			return $this->test_clarity_connection();
		}

		Logger::debug( sprintf( 'ERROR: Unsupported platform: %s', $platform ) );
		throw new \RuntimeException( 'Unsupported platform: ' . esc_html( $platform ) );
	}

	/**
	 * Test Clarity connection
	 *
	 * @return array Success payload for the JSON response.
	 */
	private function test_clarity_connection() {
		Logger::debug( 'Testing Clarity connection' );

		// Get credentials from POST.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$api_token = isset( $_POST['api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$project_id = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';

		// The token field renders a masked value (first/last chars joined by "...")
		// once a token is stored, so a Test click after saving posts the mask, not
		// the real token. Fall back to the stored token when the field is empty or
		// still showing the mask, mirroring the save form's behavior.
		$credential_manager   = new Credential_Manager();
		$existing_credentials = $credential_manager->get_credentials( 'clarity' );
		if ( $existing_credentials && ! empty( $existing_credentials['api_token'] )
			&& ( empty( $api_token ) || false !== strpos( $api_token, '...' ) ) ) {
			$api_token = $existing_credentials['api_token'];
		}
		if ( empty( $project_id ) && $existing_credentials && ! empty( $existing_credentials['project_id'] ) ) {
			$project_id = $existing_credentials['project_id'];
		}

		Logger::debug( sprintf( 'API Token provided: %s', $api_token ? 'yes (length: ' . strlen( $api_token ) . ')' : 'NO' ) );
		Logger::debug( sprintf( 'Project ID: %s', $project_id ? $project_id : 'EMPTY' ) );

		// Validate inputs.
		if ( empty( $api_token ) ) {
			Logger::error( 'API token is empty' );
			throw new \RuntimeException( 'API Token is required. Please enter your Clarity API token.' );
		}

		if ( empty( $project_id ) ) {
			Logger::error( 'Project ID is empty' );
			throw new \RuntimeException( 'Project ID is required. Please enter your Clarity project ID.' );
		}

		// Validate token format (should be a non-empty string).
		if ( strlen( $api_token ) < 10 ) {
			Logger::debug( sprintf( 'ERROR: API token too short (length: %d)', strlen( $api_token ) ) );
			throw new \RuntimeException( 'API Token appears to be invalid (too short). Please check your token.' );
		}

		// Create client and test connection.
		try {
			Logger::debug( 'Creating Clarity client instance' );
			$client = new Clarity_Client( $api_token, $project_id );

			Logger::debug( 'Calling test_connection()' );
			$result = $client->test_connection();
		} catch ( \Exception $e ) {
			Logger::debug( '===== CONNECTION TEST EXCEPTION =====' );
			Logger::debug( sprintf( 'Exception class: %s', get_class( $e ) ) );
			Logger::debug( sprintf( 'Exception message: %s', $e->getMessage() ) );
			Logger::debug( sprintf( 'Exception trace: %s', $e->getTraceAsString() ) );

			throw new \RuntimeException( 'Connection test failed: ' . esc_html( $e->getMessage() ) );
		}

		Logger::debug( sprintf( 'Connection test result: %s', wp_json_encode( $result ) ) );

		if ( ! $result['success'] ) {
			Logger::debug( sprintf( '===== CONNECTION TEST FAILED: %s =====', $result['message'] ) );
			throw new \RuntimeException( esc_html( $result['message'] ) );
		}

		Logger::debug( '===== CONNECTION TEST SUCCESSFUL =====' );

		return array(
			'message' => $result['message'],
			'data'    => $result['data'] ?? null,
		);
	}

	/**
	 * Test OAuth platform connection (GA4 or GSC)
	 *
	 * @param string $platform Platform key.
	 * @return array Success payload for the JSON response.
	 */
	private function test_oauth_platform_connection( $platform ) {
		Logger::debug( sprintf( 'Testing OAuth connection for: %s', $platform ) );

		try {
			$connection_tester = new Connection_Tester();

			if ( 'ga4' === $platform ) {
				$result = $connection_tester->test_ga4_connection();
			} elseif ( 'gsc' === $platform ) {
				$result = $connection_tester->test_gsc_connection();
			} else {
				$result = null;
			}
		} catch ( \Exception $e ) {
			Logger::debug( '===== OAUTH CONNECTION TEST EXCEPTION =====' );
			Logger::debug( sprintf( 'Exception: %s', $e->getMessage() ) );

			throw new \RuntimeException( 'Connection test failed: ' . esc_html( $e->getMessage() ) );
		}

		if ( null === $result ) {
			throw new \RuntimeException( 'Invalid platform for OAuth testing' );
		}

		Logger::debug( sprintf( 'OAuth connection test result: %s', wp_json_encode( $result ) ) );

		if ( ! $result['success'] ) {
			Logger::debug( sprintf( '===== OAUTH CONNECTION TEST FAILED: %s =====', $result['message'] ) );
			throw new \RuntimeException( esc_html( $result['message'] ) );
		}

		Logger::debug( '===== OAUTH CONNECTION TEST SUCCESSFUL =====' );

		return array(
			'message' => $result['message'],
			'data'    => $result['data'] ?? null,
		);
	}

	/**
	 * Save platform credentials
	 *
	 * @return void
	 */
	public function save_credentials() {
		Logger::debug( '===== AJAX SAVE CREDENTIALS REQUEST =====' );

		if ( ! $this->verify_request() ) {
			return;
		}

		try {
			wp_send_json_success( $this->do_save_credentials() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Persist the posted platform credentials.
	 *
	 * @return array Success payload for the JSON response.
	 */
	private function do_save_credentials() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$platform = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : '';
		Logger::debug( sprintf( 'Saving credentials for platform: %s', $platform ) );

		if ( 'clarity' !== $platform ) {
			Logger::debug( sprintf( 'ERROR: Unsupported platform: %s', $platform ) );
			throw new \RuntimeException( 'Unsupported platform.' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$api_token = isset( $_POST['api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$project_id = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';

		$credentials = array(
			'api_token'  => $api_token,
			'project_id' => $project_id,
		);

		$credential_manager = new Credential_Manager();
		$result             = $credential_manager->save_credentials( $platform, $credentials );

		if ( ! $result ) {
			Logger::error( 'Failed to save credentials' );
			throw new \RuntimeException( 'Failed to save credentials.' );
		}

		Logger::debug( 'Credentials saved successfully' );

		/**
		 * Fires when a platform connection is saved.
		 *
		 * @param string $platform The platform that was connected (e.g., 'clarity').
		 */
		do_action( 'specflux_mac_platform_connected', $platform );

		return array(
			'message' => 'Credentials saved successfully!',
		);
	}

	/**
	 * Clear all caches
	 *
	 * @return void
	 */
	public function clear_caches() {
		Logger::debug( '===== AJAX CLEAR CACHES REQUEST =====' );

		if ( ! $this->verify_request() ) {
			return;
		}

		try {
			wp_send_json_success( $this->do_clear_caches() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Purge every plugin transient from the options table.
	 *
	 * @return array Success payload for the JSON response.
	 */
	private function do_clear_caches() {
		global $wpdb;
		// Use proper escaping for LIKE patterns with wpdb.
		$pattern = $wpdb->esc_like( '_transient_specflux_mac_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cache purge.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		Logger::debug( sprintf( 'Cleared %d cache entries', $deleted ) );

		return array(
			'message' => sprintf( 'Cleared %d cache entries', $deleted ),
		);
	}

	/**
	 * List GA4 properties
	 *
	 * @return void
	 */
	public function list_ga4_properties() {
		Logger::debug( '===== AJAX LIST GA4 PROPERTIES REQUEST =====' );

		if ( ! $this->verify_request() ) {
			return;
		}

		try {
			wp_send_json_success( $this->do_list_ga4_properties() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Fetch the GA4 properties available to the connected account.
	 *
	 * @return array Success payload for the JSON response.
	 */
	private function do_list_ga4_properties() {
		try {
			$client     = new GA4_Client();
			$properties = $client->list_properties();
		} catch ( \Exception $e ) {
			Logger::error( '===== LIST PROPERTIES EXCEPTION =====' );
			Logger::error( sprintf( 'Exception: %s', $e->getMessage() ) );

			throw new \RuntimeException( esc_html( $e->getMessage() ) );
		}

		if ( null === $properties ) {
			Logger::error( 'Failed to retrieve properties' );
			throw new \RuntimeException( 'Failed to retrieve properties. Please ensure you are connected to Google Analytics.' );
		}

		if ( empty( $properties ) ) {
			Logger::debug( 'No properties found' );
			throw new \RuntimeException( 'No GA4 properties found for your account.' );
		}

		Logger::debug( sprintf( 'Found %d properties', count( $properties ) ) );

		return array(
			'properties' => $properties,
		);
	}

	/**
	 * Save GA4 property ID
	 *
	 * @return void
	 */
	public function save_ga4_property() {
		Logger::debug( '===== AJAX SAVE GA4 PROPERTY REQUEST =====' );

		if ( ! $this->verify_request() ) {
			return;
		}

		try {
			wp_send_json_success( $this->do_save_ga4_property() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Persist the selected GA4 property ID.
	 *
	 * @return array Success payload for the JSON response.
	 */
	private function do_save_ga4_property() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$property_id = isset( $_POST['property_id'] ) ? sanitize_text_field( wp_unslash( $_POST['property_id'] ) ) : '';

		if ( empty( $property_id ) ) {
			Logger::error( 'Property ID is empty' );
			throw new \RuntimeException( 'Please select a property.' );
		}

		try {
			$client = new GA4_Client();
			$result = $client->set_property_id( $property_id );
		} catch ( \Exception $e ) {
			Logger::debug( '===== SAVE PROPERTY EXCEPTION =====' );
			Logger::debug( sprintf( 'Exception: %s', $e->getMessage() ) );

			throw new \RuntimeException( 'Error saving property: ' . esc_html( $e->getMessage() ) );
		}

		if ( ! $result ) {
			Logger::error( 'Failed to save property ID' );
			throw new \RuntimeException( 'Failed to save property.' );
		}

		Logger::debug( sprintf( 'Property ID saved: %s', $property_id ) );

		/** This action is documented in class-ajax-handler.php */
		do_action( 'specflux_mac_platform_connected', 'ga4' );

		return array(
			'message'     => 'Property saved successfully!',
			'property_id' => $property_id,
		);
	}

	/**
	 * List GSC sites
	 *
	 * @return void
	 */
	public function list_gsc_sites() {
		Logger::debug( '===== AJAX LIST GSC SITES REQUEST =====' );

		if ( ! $this->verify_request() ) {
			return;
		}

		try {
			wp_send_json_success( $this->do_list_gsc_sites() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Fetch the Search Console sites available to the connected account.
	 *
	 * @return array Success payload for the JSON response.
	 */
	private function do_list_gsc_sites() {
		try {
			$client = new GSC_Client();
			$sites  = $client->list_sites();
		} catch ( \Exception $e ) {
			Logger::debug( '===== LIST SITES EXCEPTION =====' );
			Logger::debug( sprintf( 'Exception: %s', $e->getMessage() ) );

			throw new \RuntimeException( 'Error fetching sites: ' . esc_html( $e->getMessage() ) );
		}

		if ( null === $sites ) {
			Logger::error( 'Failed to retrieve sites' );
			throw new \RuntimeException( 'Failed to retrieve sites. Please ensure you are connected to Google Search Console.' );
		}

		if ( empty( $sites ) ) {
			Logger::debug( 'No sites found' );
			throw new \RuntimeException( 'No Search Console sites found for your account.' );
		}

		Logger::debug( sprintf( 'Found %d sites', count( $sites ) ) );

		return array(
			'sites' => $sites,
		);
	}

	/**
	 * Save GSC site URL
	 *
	 * @return void
	 */
	public function save_gsc_site() {
		Logger::debug( '===== AJAX SAVE GSC SITE REQUEST =====' );

		if ( ! $this->verify_request() ) {
			return;
		}

		try {
			wp_send_json_success( $this->do_save_gsc_site() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Persist the selected Search Console site URL.
	 *
	 * @return array Success payload for the JSON response.
	 */
	private function do_save_gsc_site() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request().
		$site_url = isset( $_POST['site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['site_url'] ) ) : '';

		if ( empty( $site_url ) ) {
			Logger::error( 'Site URL is empty' );
			throw new \RuntimeException( 'Please select a site.' );
		}

		try {
			$client = new GSC_Client();
			$result = $client->set_site_url( $site_url );
		} catch ( \Exception $e ) {
			Logger::debug( '===== SAVE SITE EXCEPTION =====' );
			Logger::debug( sprintf( 'Exception: %s', $e->getMessage() ) );

			throw new \RuntimeException( 'Error saving site: ' . esc_html( $e->getMessage() ) );
		}

		if ( ! $result ) {
			Logger::error( 'Failed to save site URL' );
			throw new \RuntimeException( 'Failed to save site.' );
		}

		Logger::debug( sprintf( 'Site URL saved: %s', $site_url ) );

		/** This action is documented in class-ajax-handler.php */
		do_action( 'specflux_mac_platform_connected', 'gsc' );

		return array(
			'message'  => 'Site saved successfully!',
			'site_url' => $site_url,
		);
	}

	/**
	 * Handle dashboard widget data refresh
	 *
	 * @return void
	 */
	public function handle_refresh_widget_data() {
		if ( ! $this->verify_request() ) {
			return;
		}

		try {
			wp_send_json_success( $this->do_refresh_widget_data() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Refresh the dashboard widget transient from the connected platforms.
	 *
	 * @return array Success payload for the JSON response.
	 */
	private function do_refresh_widget_data() {
		$widget_data        = array();
		$credential_manager = new Credential_Manager();

		// Fetch GA4 metrics if connected.
		// Signature: run_report( $metrics, $dimensions = array(), $date_range = '7daysAgo', $options = array() ).
		if ( $credential_manager->has_credentials( 'ga4' ) ) {
			try {
				$ga4_client         = new GA4_Client();
				$widget_data['ga4'] = $ga4_client->run_report(
					array( 'sessions', 'activeUsers', 'screenPageViews' ),
					array( 'date' ),
					'7daysAgo'
				);
			} catch ( \Throwable $e ) {
				$widget_data['ga4_error'] = $e->getMessage();
			}
		}

		// Fetch GSC metrics if connected.
		// Signature: query_search_analytics( $date_range = '7daysAgo', $dimensions = array(), ... ).
		if ( $credential_manager->has_credentials( 'gsc' ) ) {
			try {
				$gsc_client         = new GSC_Client();
				$widget_data['gsc'] = $gsc_client->query_search_analytics(
					'7daysAgo',
					array( 'date' )
				);
			} catch ( \Throwable $e ) {
				$widget_data['gsc_error'] = $e->getMessage();
			}
		}

		// Fetch Clarity metrics if connected. Clarity's data export API caps the
		// window at 3 days, so request 3 (not 7) to get a live response.
		if ( $credential_manager->has_credentials( 'clarity' ) ) {
			try {
				$clarity_client         = new Clarity_Client();
				$widget_data['clarity'] = $clarity_client->get_insights( 3 );
			} catch ( \Throwable $e ) {
				$widget_data['clarity_error'] = $e->getMessage();
			}
		}

		// Store in transient with 30 minute TTL.
		set_transient( 'specflux_mac_widget_data', $widget_data, 30 * MINUTE_IN_SECONDS );

		return array(
			'message' => 'Widget data refreshed successfully.',
			'data'    => $widget_data,
		);
	}

	/**
	 * Handle dashboard insights panel metrics refresh.
	 *
	 * Reads from existing transients ONLY — no live API calls.
	 *
	 * @return void
	 */
	public function handle_refresh_dashboard_metrics() {
		if ( ! $this->verify_request( 'specflux_mac_dashboard_insights' ) ) {
			return;
		}

		try {
			wp_send_json_success( $this->do_refresh_dashboard_metrics() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Build the dashboard insights metrics from cached transients.
	 *
	 * @return array Success payload for the JSON response.
	 */
	private function do_refresh_dashboard_metrics() {
		$credential_manager = new Credential_Manager();
		$metrics            = array();

		// GA4 metrics from transient.
		if ( $credential_manager->has_credentials( 'ga4' ) ) {
			$ga4_data = get_transient( 'specflux_mac_ga4_day_summary' );
			if ( false !== $ga4_data ) {
				$metrics['ga4'] = $this->extract_ga4_metrics( $ga4_data );
			}
		}

		// Clarity metrics from transient.
		if ( $credential_manager->has_credentials( 'clarity' ) ) {
			$clarity_data = get_transient( 'specflux_mac_clarity_day_summary' );
			if ( false !== $clarity_data ) {
				$metrics['clarity'] = $this->extract_clarity_metrics( $clarity_data );
			}
		}

		// GSC metrics from transient.
		if ( $credential_manager->has_credentials( 'gsc' ) ) {
			$gsc_data = get_transient( 'specflux_mac_gsc_day_summary' );
			if ( false !== $gsc_data ) {
				$metrics['gsc'] = $this->extract_gsc_metrics( $gsc_data );
			}
		}

		return array(
			'message' => 'Metrics refreshed from cache.',
			'metrics' => $metrics,
		);
	}

	/**
	 * Extract GA4 metrics from transient data.
	 *
	 * @param array $data GA4 day summary transient data.
	 * @return array Formatted metrics.
	 */
	private function extract_ga4_metrics( $data ) {
		$metrics = array();

		if ( ! isset( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
			return $metrics;
		}

		$sessions  = 0;
		$users     = 0;
		$pageviews = 0;

		foreach ( $data['rows'] as $row ) {
			if ( isset( $row['metricValues'] ) && is_array( $row['metricValues'] ) ) {
				$sessions  += isset( $row['metricValues'][0]['value'] ) ? (int) $row['metricValues'][0]['value'] : 0;
				$users     += isset( $row['metricValues'][1]['value'] ) ? (int) $row['metricValues'][1]['value'] : 0;
				$pageviews += isset( $row['metricValues'][2]['value'] ) ? (int) $row['metricValues'][2]['value'] : 0;
			}
		}

		// Build sparkline arrays from daily rows.
		$sessions_spark  = array();
		$users_spark     = array();
		$pageviews_spark = array();

		foreach ( $data['rows'] as $row ) {
			if ( isset( $row['metricValues'] ) && is_array( $row['metricValues'] ) ) {
				$sessions_spark[]  = isset( $row['metricValues'][0]['value'] ) ? (int) $row['metricValues'][0]['value'] : 0;
				$users_spark[]     = isset( $row['metricValues'][1]['value'] ) ? (int) $row['metricValues'][1]['value'] : 0;
				$pageviews_spark[] = isset( $row['metricValues'][2]['value'] ) ? (int) $row['metricValues'][2]['value'] : 0;
			}
		}

		$metrics[] = array(
			'key'       => 'sessions',
			'label'     => __( 'Sessions', 'specflux-marketing-analytics-chat' ),
			'formatted' => number_format_i18n( $sessions ),
			'direction' => $this->calc_direction( $sessions_spark ),
			'change'    => $this->calc_change( $sessions_spark ),
			'sparkline' => $sessions_spark,
		);

		$metrics[] = array(
			'key'       => 'users',
			'label'     => __( 'Users', 'specflux-marketing-analytics-chat' ),
			'formatted' => number_format_i18n( $users ),
			'direction' => $this->calc_direction( $users_spark ),
			'change'    => $this->calc_change( $users_spark ),
			'sparkline' => $users_spark,
		);

		$metrics[] = array(
			'key'       => 'pageviews',
			'label'     => __( 'Pageviews', 'specflux-marketing-analytics-chat' ),
			'formatted' => number_format_i18n( $pageviews ),
			'direction' => $this->calc_direction( $pageviews_spark ),
			'change'    => $this->calc_change( $pageviews_spark ),
			'sparkline' => $pageviews_spark,
		);

		return $metrics;
	}

	/**
	 * Extract Clarity metrics from transient data.
	 *
	 * @param array $data Clarity day summary transient data.
	 * @return array Formatted metrics.
	 */
	private function extract_clarity_metrics( $data ) {
		$metrics = array();

		$total_sessions    = isset( $data['totalSessions'] ) ? (int) $data['totalSessions'] : 0;
		$pages_per_session = isset( $data['pagesPerSession'] ) ? (float) $data['pagesPerSession'] : 0;

		$metrics[] = array(
			'key'       => 'sessions',
			'label'     => __( 'Sessions', 'specflux-marketing-analytics-chat' ),
			'formatted' => number_format_i18n( $total_sessions ),
			'direction' => 'neutral',
			'change'    => '',
			'sparkline' => array(),
		);

		$metrics[] = array(
			'key'       => 'pages_per_session',
			'label'     => __( 'Pages / Session', 'specflux-marketing-analytics-chat' ),
			'formatted' => number_format( $pages_per_session, 1 ),
			'direction' => 'neutral',
			'change'    => '',
			'sparkline' => array(),
		);

		return $metrics;
	}

	/**
	 * Extract GSC metrics from transient data.
	 *
	 * @param array $data GSC day summary transient data.
	 * @return array Formatted metrics.
	 */
	private function extract_gsc_metrics( $data ) {
		$metrics = array();

		if ( ! isset( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
			return $metrics;
		}

		$total_clicks      = 0;
		$total_impressions = 0;
		$total_position    = 0;
		$row_count         = count( $data['rows'] );

		$clicks_spark      = array();
		$impressions_spark = array();

		foreach ( $data['rows'] as $row ) {
			$clicks      = isset( $row['clicks'] ) ? (int) $row['clicks'] : 0;
			$impressions = isset( $row['impressions'] ) ? (int) $row['impressions'] : 0;
			$position    = isset( $row['position'] ) ? (float) $row['position'] : 0;

			$total_clicks      += $clicks;
			$total_impressions += $impressions;
			$total_position    += $position;

			$clicks_spark[]      = $clicks;
			$impressions_spark[] = $impressions;
		}

		$avg_position = $row_count > 0 ? $total_position / $row_count : 0;

		$metrics[] = array(
			'key'       => 'clicks',
			'label'     => __( 'Clicks', 'specflux-marketing-analytics-chat' ),
			'formatted' => number_format_i18n( $total_clicks ),
			'direction' => $this->calc_direction( $clicks_spark ),
			'change'    => $this->calc_change( $clicks_spark ),
			'sparkline' => $clicks_spark,
		);

		$metrics[] = array(
			'key'       => 'impressions',
			'label'     => __( 'Impressions', 'specflux-marketing-analytics-chat' ),
			'formatted' => number_format_i18n( $total_impressions ),
			'direction' => $this->calc_direction( $impressions_spark ),
			'change'    => $this->calc_change( $impressions_spark ),
			'sparkline' => $impressions_spark,
		);

		$metrics[] = array(
			'key'       => 'avg_position',
			'label'     => __( 'Avg Position', 'specflux-marketing-analytics-chat' ),
			'formatted' => number_format( $avg_position, 1 ),
			'direction' => 'neutral',
			'change'    => '',
			'sparkline' => array(),
		);

		return $metrics;
	}

	/**
	 * Calculate trend direction by comparing first half to second half of data.
	 *
	 * @param array $data Array of numeric values.
	 * @return string 'positive', 'negative', or 'neutral'.
	 */
	private function calc_direction( $data ) {
		if ( count( $data ) < 2 ) {
			return 'neutral';
		}

		$mid         = (int) floor( count( $data ) / 2 );
		$first_half  = array_slice( $data, 0, $mid );
		$second_half = array_slice( $data, $mid );

		$first_avg  = array_sum( $first_half ) / count( $first_half );
		$second_avg = array_sum( $second_half ) / count( $second_half );

		if ( 0.0 === (float) $first_avg ) {
			return $second_avg > 0 ? 'positive' : 'neutral';
		}

		$pct = ( ( $second_avg - $first_avg ) / $first_avg ) * 100;

		if ( $pct > 1 ) {
			return 'positive';
		} elseif ( $pct < -1 ) {
			return 'negative';
		}

		return 'neutral';
	}

	/**
	 * Calculate percentage change string comparing first half to second half.
	 *
	 * @param array $data Array of numeric values.
	 * @return string Formatted percentage string, e.g. '12.3%'.
	 */
	private function calc_change( $data ) {
		if ( count( $data ) < 2 ) {
			return '';
		}

		$mid         = (int) floor( count( $data ) / 2 );
		$first_half  = array_slice( $data, 0, $mid );
		$second_half = array_slice( $data, $mid );

		$first_avg  = array_sum( $first_half ) / count( $first_half );
		$second_avg = array_sum( $second_half ) / count( $second_half );

		if ( 0.0 === (float) $first_avg ) {
			return '';
		}

		$pct = abs( ( ( $second_avg - $first_avg ) / $first_avg ) * 100 );

		return number_format( $pct, 1 ) . '%';
	}

	/**
	 * Dismiss the onboarding wizard
	 *
	 * @return void
	 */
	public function dismiss_onboarding_wizard() {
		if ( ! $this->verify_request( 'specflux_mac_dismiss_wizard' ) ) {
			return;
		}

		try {
			wp_send_json_success( $this->do_dismiss_onboarding_wizard() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Record the onboarding wizard as dismissed.
	 *
	 * @return array Success payload for the JSON response.
	 */
	private function do_dismiss_onboarding_wizard() {
		update_option( 'specflux_mac_onboarding_complete', true );

		return array(
			'message' => 'Onboarding wizard dismissed.',
		);
	}
}
