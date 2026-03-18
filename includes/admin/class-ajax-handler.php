<?php
/**
 * AJAX Handler for Admin Operations
 *
 * @package Marketing_Analytics_MCP
 */

namespace Marketing_Analytics_MCP\Admin;

use Marketing_Analytics_MCP\API_Clients\Clarity_Client;
use Marketing_Analytics_MCP\API_Clients\GA4_Client;
use Marketing_Analytics_MCP\API_Clients\GSC_Client;
use Marketing_Analytics_MCP\Credentials\Encryption;
use Marketing_Analytics_MCP\Credentials\Connection_Tester;
use Marketing_Analytics_MCP\Credentials\Credential_Manager;
use Marketing_Analytics_MCP\Credentials\OAuth_Handler;
use Marketing_Analytics_MCP\Utils\Logger;
use Marketing_Analytics_MCP\Utils\Permission_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Handles AJAX requests from admin interface
 */
class Ajax_Handler {

	/**
	 * Register AJAX hooks
	 */
	public function register_hooks() {
		Logger::debug( 'Registering AJAX hooks' );

		add_action( 'wp_ajax_marketing_analytics_mcp_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_marketing_analytics_mcp_save_credentials', array( $this, 'save_credentials' ) );
		add_action( 'wp_ajax_marketing_analytics_mcp_clear_caches', array( $this, 'clear_caches' ) );
		add_action( 'wp_ajax_marketing_analytics_mcp_list_ga4_properties', array( $this, 'list_ga4_properties' ) );
		add_action( 'wp_ajax_marketing_analytics_mcp_save_ga4_property', array( $this, 'save_ga4_property' ) );
		add_action( 'wp_ajax_marketing_analytics_mcp_list_gsc_sites', array( $this, 'list_gsc_sites' ) );
		add_action( 'wp_ajax_marketing_analytics_mcp_save_gsc_site', array( $this, 'save_gsc_site' ) );

		// Dashboard widget refresh.
		add_action( 'wp_ajax_marketing_analytics_mcp_refresh_widget', array( $this, 'handle_refresh_widget_data' ) );

		// Dashboard insights panel refresh (transient reads only).
		add_action( 'wp_ajax_marketing_analytics_mcp_refresh_dashboard_metrics', array( $this, 'handle_refresh_dashboard_metrics' ) );

		// Onboarding wizard dismissal.
		add_action( 'wp_ajax_marketing_analytics_mcp_dismiss_wizard', array( $this, 'dismiss_onboarding_wizard' ) );

		/**
		 * Allow pro add-on to register additional AJAX handlers.
		 */
		do_action( 'marketing_analytics_mcp_register_ajax_handlers' );
	}

	/**
	 * Test platform connection
	 */
	public function test_connection() {
		Logger::debug( '===== AJAX TEST CONNECTION REQUEST =====' );

		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'marketing-analytics-chat-admin' ) ) {
			Logger::error( 'Nonce verification failed' );
			wp_send_json_error(
				array(
					'message' => 'Security check failed. Please refresh the page and try again.',
				)
			);
			return;
		}

		// Check permissions
		if ( ! Permission_Manager::can_access_plugin() ) {
			Logger::error( 'User lacks manage_options capability' );
			wp_send_json_error(
				array(
					'message' => 'You do not have permission to perform this action.',
				)
			);
			return;
		}

		$request_data = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		Logger::debug( sprintf( 'Request data: %s', wp_json_encode( $request_data ) ) );

		$platform = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : '';
		Logger::debug( sprintf( 'Testing connection for platform: %s', $platform ) );

		// Use Connection_Tester for OAuth-based platforms (GA4, GSC)
		if ( in_array( $platform, array( 'ga4', 'gsc' ), true ) ) {
			$this->test_oauth_platform_connection( $platform );
		} elseif ( $platform === 'clarity' ) {
			$this->test_clarity_connection();
		} else {
			Logger::debug( sprintf( 'ERROR: Unsupported platform: %s', $platform ) );
			wp_send_json_error(
				array(
					'message' => 'Unsupported platform: ' . $platform,
				)
			);
			return;
		}
	}

	/**
	 * Test Clarity connection
	 */
	private function test_clarity_connection() {
		Logger::debug( 'Testing Clarity connection' );

		// Get credentials from POST
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in test_connection().
		$api_token  = isset( $_POST['api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in test_connection().
		$project_id = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';

		Logger::debug( sprintf( 'API Token provided: %s', $api_token ? 'yes (length: ' . strlen( $api_token ) . ')' : 'NO' ) );
		Logger::debug( sprintf( 'Project ID: %s', $project_id ? $project_id : 'EMPTY' ) );

		// Validate inputs
		if ( empty( $api_token ) ) {
			Logger::error( 'API token is empty' );
			wp_send_json_error(
				array(
					'message' => 'API Token is required. Please enter your Clarity API token.',
				)
			);
			return;
		}

		if ( empty( $project_id ) ) {
			Logger::error( 'Project ID is empty' );
			wp_send_json_error(
				array(
					'message' => 'Project ID is required. Please enter your Clarity project ID.',
				)
			);
			return;
		}

		// Validate token format (should be a non-empty string)
		if ( strlen( $api_token ) < 10 ) {
			Logger::debug( sprintf( 'ERROR: API token too short (length: %d)', strlen( $api_token ) ) );
			wp_send_json_error(
				array(
					'message' => 'API Token appears to be invalid (too short). Please check your token.',
				)
			);
			return;
		}

		// Create client and test connection
		try {
			Logger::debug( 'Creating Clarity client instance' );
			$client = new Clarity_Client( $api_token, $project_id );

			Logger::debug( 'Calling test_connection()' );
			$result = $client->test_connection();

			Logger::debug( sprintf( 'Connection test result: %s', wp_json_encode( $result ) ) );

			if ( $result['success'] ) {
				Logger::debug( '===== CONNECTION TEST SUCCESSFUL =====' );
				wp_send_json_success(
					array(
						'message' => $result['message'],
						'data'    => $result['data'] ?? null,
					)
				);
			} else {
				Logger::debug( sprintf( '===== CONNECTION TEST FAILED: %s =====', $result['message'] ) );
				wp_send_json_error(
					array(
						'message' => $result['message'],
					)
				);
			}
		} catch ( \Exception $e ) {
			Logger::debug( '===== CONNECTION TEST EXCEPTION =====' );
			Logger::debug( sprintf( 'Exception class: %s', get_class( $e ) ) );
			Logger::debug( sprintf( 'Exception message: %s', $e->getMessage() ) );
			Logger::debug( sprintf( 'Exception trace: %s', $e->getTraceAsString() ) );

			wp_send_json_error(
				array(
					'message' => 'Connection test failed: ' . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Test OAuth platform connection (GA4 or GSC)
	 *
	 * @param string $platform Platform key.
	 */
	private function test_oauth_platform_connection( $platform ) {
		Logger::debug( sprintf( 'Testing OAuth connection for: %s', $platform ) );

		try {
			$connection_tester = new Connection_Tester();

			if ( $platform === 'ga4' ) {
				$result = $connection_tester->test_ga4_connection();
			} elseif ( $platform === 'gsc' ) {
				$result = $connection_tester->test_gsc_connection();
			} else {
				wp_send_json_error(
					array(
						'message' => 'Invalid platform for OAuth testing',
					)
				);
				return;
			}

			Logger::debug( sprintf( 'OAuth connection test result: %s', wp_json_encode( $result ) ) );

			if ( $result['success'] ) {
				Logger::debug( '===== OAUTH CONNECTION TEST SUCCESSFUL =====' );
				wp_send_json_success(
					array(
						'message' => $result['message'],
						'data'    => $result['data'] ?? null,
					)
				);
			} else {
				Logger::debug( sprintf( '===== OAUTH CONNECTION TEST FAILED: %s =====', $result['message'] ) );
				wp_send_json_error(
					array(
						'message' => $result['message'],
					)
				);
			}
		} catch ( \Exception $e ) {
			Logger::debug( '===== OAUTH CONNECTION TEST EXCEPTION =====' );
			Logger::debug( sprintf( 'Exception: %s', $e->getMessage() ) );

			wp_send_json_error(
				array(
					'message' => 'Connection test failed: ' . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Save platform credentials
	 */
	public function save_credentials() {
		Logger::debug( '===== AJAX SAVE CREDENTIALS REQUEST =====' );

		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'marketing-analytics-chat-admin' ) ) {
			Logger::error( 'Nonce verification failed' );
			wp_send_json_error(
				array(
					'message' => 'Security check failed.',
				)
			);
		}

		// Check permissions
		if ( ! Permission_Manager::can_access_plugin() ) {
			Logger::error( 'User lacks permissions' );
			wp_send_json_error(
				array(
					'message' => 'Insufficient permissions.',
				)
			);
		}

		$platform = isset( $_POST['platform'] ) ? sanitize_text_field( wp_unslash( $_POST['platform'] ) ) : '';
		Logger::debug( sprintf( 'Saving credentials for platform: %s', $platform ) );

		if ( $platform === 'clarity' ) {
			$api_token  = isset( $_POST['api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) : '';
			$project_id = isset( $_POST['project_id'] ) ? sanitize_text_field( wp_unslash( $_POST['project_id'] ) ) : '';

			$credentials = array(
				'api_token'  => $api_token,
				'project_id' => $project_id,
			);

			$result = Encryption::save_credentials( $platform, $credentials );

			if ( $result ) {
				Logger::debug( 'Credentials saved successfully' );

				/**
				 * Fires when a platform connection is saved.
				 *
				 * @param string $platform The platform that was connected (e.g., 'clarity').
				 */
				do_action( 'marketing_analytics_mcp_platform_connected', $platform );

				wp_send_json_success(
					array(
						'message' => 'Credentials saved successfully!',
					)
				);
			} else {
				Logger::error( 'Failed to save credentials' );
				wp_send_json_error(
					array(
						'message' => 'Failed to save credentials.',
					)
				);
			}
		} else {
			Logger::debug( sprintf( 'ERROR: Unsupported platform: %s', $platform ) );
			wp_send_json_error(
				array(
					'message' => 'Unsupported platform.',
				)
			);
		}
	}

	/**
	 * Clear all caches
	 */
	public function clear_caches() {
		Logger::debug( '===== AJAX CLEAR CACHES REQUEST =====' );

		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'marketing-analytics-chat-admin' ) ) {
			Logger::error( 'Nonce verification failed' );
			wp_send_json_error(
				array(
					'message' => 'Security check failed.',
				)
			);
		}

		// Check permissions
		if ( ! Permission_Manager::can_access_plugin() ) {
			Logger::error( 'User lacks permissions' );
			wp_send_json_error(
				array(
					'message' => 'Insufficient permissions.',
				)
			);
		}

		global $wpdb;
		// Use proper escaping for LIKE patterns with wpdb
		$pattern = $wpdb->esc_like( '_transient_marketing_analytics_mcp_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk cache purge.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		Logger::debug( sprintf( 'Cleared %d cache entries', $deleted ) );

		wp_send_json_success(
			array(
				'message' => sprintf( 'Cleared %d cache entries', $deleted ),
			)
		);
	}

	/**
	 * List GA4 properties
	 */
	public function list_ga4_properties() {
		Logger::debug( '===== AJAX LIST GA4 PROPERTIES REQUEST =====' );

		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'marketing-analytics-chat-admin' ) ) {
			Logger::error( 'Nonce verification failed' );
			wp_send_json_error(
				array(
					'message' => 'Security check failed.',
				)
			);
		}

		// Check permissions
		if ( ! Permission_Manager::can_access_plugin() ) {
			Logger::error( 'User lacks permissions' );
			wp_send_json_error(
				array(
					'message' => 'Insufficient permissions.',
				)
			);
		}

		try {
			$client     = new GA4_Client();
			$properties = $client->list_properties();

			if ( $properties === null ) {
				Logger::error( 'Failed to retrieve properties' );
				wp_send_json_error(
					array(
						'message' => 'Failed to retrieve properties. Please ensure you are connected to Google Analytics.',
					)
				);
			}

			if ( empty( $properties ) ) {
				Logger::debug( 'No properties found' );
				wp_send_json_error(
					array(
						'message' => 'No GA4 properties found for your account.',
					)
				);
			}

			Logger::debug( sprintf( 'Found %d properties', count( $properties ) ) );
			wp_send_json_success(
				array(
					'properties' => $properties,
				)
			);
		} catch ( \Exception $e ) {
			Logger::error( '===== LIST PROPERTIES EXCEPTION =====' );
			Logger::error( sprintf( 'Exception: %s', $e->getMessage() ) );

			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Save GA4 property ID
	 */
	public function save_ga4_property() {
		Logger::debug( '===== AJAX SAVE GA4 PROPERTY REQUEST =====' );

		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'marketing-analytics-chat-admin' ) ) {
			Logger::error( 'Nonce verification failed' );
			wp_send_json_error(
				array(
					'message' => 'Security check failed.',
				)
			);
		}

		// Check permissions
		if ( ! Permission_Manager::can_access_plugin() ) {
			Logger::error( 'User lacks permissions' );
			wp_send_json_error(
				array(
					'message' => 'Insufficient permissions.',
				)
			);
		}

		$property_id = isset( $_POST['property_id'] ) ? sanitize_text_field( wp_unslash( $_POST['property_id'] ) ) : '';

		if ( empty( $property_id ) ) {
			Logger::error( 'Property ID is empty' );
			wp_send_json_error(
				array(
					'message' => 'Please select a property.',
				)
			);
		}

		try {
			$client = new GA4_Client();
			$result = $client->set_property_id( $property_id );

			if ( $result ) {
				Logger::debug( sprintf( 'Property ID saved: %s', $property_id ) );

				/** This action is documented in class-ajax-handler.php */
				do_action( 'marketing_analytics_mcp_platform_connected', 'ga4' );

				wp_send_json_success(
					array(
						'message'     => 'Property saved successfully!',
						'property_id' => $property_id,
					)
				);
			} else {
				Logger::error( 'Failed to save property ID' );
				wp_send_json_error(
					array(
						'message' => 'Failed to save property.',
					)
				);
			}
		} catch ( \Exception $e ) {
			Logger::debug( '===== SAVE PROPERTY EXCEPTION =====' );
			Logger::debug( sprintf( 'Exception: %s', $e->getMessage() ) );

			wp_send_json_error(
				array(
					'message' => 'Error saving property: ' . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * List GSC sites
	 */
	public function list_gsc_sites() {
		Logger::debug( '===== AJAX LIST GSC SITES REQUEST =====' );

		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'marketing-analytics-chat-admin' ) ) {
			Logger::error( 'Nonce verification failed' );
			wp_send_json_error(
				array(
					'message' => 'Security check failed.',
				)
			);
		}

		// Check permissions
		if ( ! Permission_Manager::can_access_plugin() ) {
			Logger::error( 'User lacks permissions' );
			wp_send_json_error(
				array(
					'message' => 'Insufficient permissions.',
				)
			);
		}

		try {
			$client = new GSC_Client();
			$sites  = $client->list_sites();

			if ( $sites === null ) {
				Logger::error( 'Failed to retrieve sites' );
				wp_send_json_error(
					array(
						'message' => 'Failed to retrieve sites. Please ensure you are connected to Google Search Console.',
					)
				);
			}

			if ( empty( $sites ) ) {
				Logger::debug( 'No sites found' );
				wp_send_json_error(
					array(
						'message' => 'No Search Console sites found for your account.',
					)
				);
			}

			Logger::debug( sprintf( 'Found %d sites', count( $sites ) ) );
			wp_send_json_success(
				array(
					'sites' => $sites,
				)
			);
		} catch ( \Exception $e ) {
			Logger::debug( '===== LIST SITES EXCEPTION =====' );
			Logger::debug( sprintf( 'Exception: %s', $e->getMessage() ) );

			wp_send_json_error(
				array(
					'message' => 'Error fetching sites: ' . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Save GSC site URL
	 */
	public function save_gsc_site() {
		Logger::debug( '===== AJAX SAVE GSC SITE REQUEST =====' );

		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'marketing-analytics-chat-admin' ) ) {
			Logger::error( 'Nonce verification failed' );
			wp_send_json_error(
				array(
					'message' => 'Security check failed.',
				)
			);
		}

		// Check permissions
		if ( ! Permission_Manager::can_access_plugin() ) {
			Logger::error( 'User lacks permissions' );
			wp_send_json_error(
				array(
					'message' => 'Insufficient permissions.',
				)
			);
		}

		$site_url = isset( $_POST['site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['site_url'] ) ) : '';

		if ( empty( $site_url ) ) {
			Logger::error( 'Site URL is empty' );
			wp_send_json_error(
				array(
					'message' => 'Please select a site.',
				)
			);
		}

		try {
			$client = new GSC_Client();
			$result = $client->set_site_url( $site_url );

			if ( $result ) {
				Logger::debug( sprintf( 'Site URL saved: %s', $site_url ) );

				/** This action is documented in class-ajax-handler.php */
				do_action( 'marketing_analytics_mcp_platform_connected', 'gsc' );

				wp_send_json_success(
					array(
						'message'  => 'Site saved successfully!',
						'site_url' => $site_url,
					)
				);
			} else {
				Logger::error( 'Failed to save site URL' );
				wp_send_json_error(
					array(
						'message' => 'Failed to save site.',
					)
				);
			}
		} catch ( \Exception $e ) {
			Logger::debug( '===== SAVE SITE EXCEPTION =====' );
			Logger::debug( sprintf( 'Exception: %s', $e->getMessage() ) );

			wp_send_json_error(
				array(
					'message' => 'Error saving site: ' . $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Handle dashboard widget data refresh
	 */
	public function handle_refresh_widget_data() {
		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'marketing-analytics-chat-admin' ) ) {
			wp_send_json_error(
				array(
					'message' => 'Security check failed.',
				)
			);
			return;
		}

		// Check permissions
		if ( ! Permission_Manager::can_access_plugin() ) {
			wp_send_json_error(
				array(
					'message' => 'Insufficient permissions.',
				)
			);
			return;
		}

		$widget_data        = array();
		$credential_manager = new Credential_Manager();

		// Fetch GA4 metrics if connected
		if ( $credential_manager->has_credentials( 'ga4' ) ) {
			try {
				$ga4_client              = new GA4_Client();
				$widget_data['ga4']      = $ga4_client->run_report(
					array( 'date' ),
					array( 'sessions', 'activeUsers', 'screenPageViews' ),
					array( 'date_range' => '7daysAgo' )
				);
			} catch ( \Exception $e ) {
				$widget_data['ga4_error'] = $e->getMessage();
			}
		}

		// Fetch GSC metrics if connected
		if ( $credential_manager->has_credentials( 'gsc' ) ) {
			try {
				$gsc_client              = new GSC_Client();
				$widget_data['gsc']      = $gsc_client->query_search_analytics(
					array(
						'start_date' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
						'end_date'   => gmdate( 'Y-m-d' ),
					)
				);
			} catch ( \Exception $e ) {
				$widget_data['gsc_error'] = $e->getMessage();
			}
		}

		// Fetch Clarity metrics if connected
		if ( $credential_manager->has_credentials( 'clarity' ) ) {
			try {
				$credentials              = $credential_manager->get_credentials( 'clarity' );
				$clarity_client           = new Clarity_Client( $credentials['api_token'], $credentials['project_id'] );
				$widget_data['clarity']   = $clarity_client->get_insights( 7 );
			} catch ( \Exception $e ) {
				$widget_data['clarity_error'] = $e->getMessage();
			}
		}

		// Store in transient with 30 minute TTL
		set_transient( 'marketing_analytics_widget_data', $widget_data, 30 * MINUTE_IN_SECONDS );

		wp_send_json_success(
			array(
				'message' => 'Widget data refreshed successfully.',
				'data'    => $widget_data,
			)
		);
	}

	/**
	 * Handle dashboard insights panel metrics refresh.
	 *
	 * Reads from existing transients ONLY — no live API calls.
	 */
	public function handle_refresh_dashboard_metrics() {
		// Check nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'marketing-analytics-dashboard-insights' ) ) {
			wp_send_json_error(
				array(
					'message' => 'Security check failed.',
				)
			);
			return;
		}

		// Check permissions.
		if ( ! Permission_Manager::can_access_plugin() ) {
			wp_send_json_error(
				array(
					'message' => 'Insufficient permissions.',
				)
			);
			return;
		}

		$credential_manager = new Credential_Manager();
		$metrics            = array();

		// GA4 metrics from transient.
		if ( $credential_manager->has_credentials( 'ga4' ) ) {
			$ga4_data = get_transient( 'marketing_analytics_ga4_day_summary' );
			if ( false !== $ga4_data ) {
				$metrics['ga4'] = $this->extract_ga4_metrics( $ga4_data );
			}
		}

		// Clarity metrics from transient.
		if ( $credential_manager->has_credentials( 'clarity' ) ) {
			$clarity_data = get_transient( 'marketing_analytics_clarity_day_summary' );
			if ( false !== $clarity_data ) {
				$metrics['clarity'] = $this->extract_clarity_metrics( $clarity_data );
			}
		}

		// GSC metrics from transient.
		if ( $credential_manager->has_credentials( 'gsc' ) ) {
			$gsc_data = get_transient( 'marketing_analytics_gsc_day_summary' );
			if ( false !== $gsc_data ) {
				$metrics['gsc'] = $this->extract_gsc_metrics( $gsc_data );
			}
		}

		wp_send_json_success(
			array(
				'message' => 'Metrics refreshed from cache.',
				'metrics' => $metrics,
			)
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
			'label'     => __( 'Sessions', 'marketing-analytics-chat' ),
			'formatted' => number_format_i18n( $sessions ),
			'direction' => $this->calc_direction( $sessions_spark ),
			'change'    => $this->calc_change( $sessions_spark ),
			'sparkline' => $sessions_spark,
		);

		$metrics[] = array(
			'key'       => 'users',
			'label'     => __( 'Users', 'marketing-analytics-chat' ),
			'formatted' => number_format_i18n( $users ),
			'direction' => $this->calc_direction( $users_spark ),
			'change'    => $this->calc_change( $users_spark ),
			'sparkline' => $users_spark,
		);

		$metrics[] = array(
			'key'       => 'pageviews',
			'label'     => __( 'Pageviews', 'marketing-analytics-chat' ),
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

		$total_sessions   = isset( $data['totalSessions'] ) ? (int) $data['totalSessions'] : 0;
		$pages_per_session = isset( $data['pagesPerSession'] ) ? (float) $data['pagesPerSession'] : 0;

		$metrics[] = array(
			'key'       => 'sessions',
			'label'     => __( 'Sessions', 'marketing-analytics-chat' ),
			'formatted' => number_format_i18n( $total_sessions ),
			'direction' => 'neutral',
			'change'    => '',
			'sparkline' => array(),
		);

		$metrics[] = array(
			'key'       => 'pages_per_session',
			'label'     => __( 'Pages / Session', 'marketing-analytics-chat' ),
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
			'label'     => __( 'Clicks', 'marketing-analytics-chat' ),
			'formatted' => number_format_i18n( $total_clicks ),
			'direction' => $this->calc_direction( $clicks_spark ),
			'change'    => $this->calc_change( $clicks_spark ),
			'sparkline' => $clicks_spark,
		);

		$metrics[] = array(
			'key'       => 'impressions',
			'label'     => __( 'Impressions', 'marketing-analytics-chat' ),
			'formatted' => number_format_i18n( $total_impressions ),
			'direction' => $this->calc_direction( $impressions_spark ),
			'change'    => $this->calc_change( $impressions_spark ),
			'sparkline' => $impressions_spark,
		);

		$metrics[] = array(
			'key'       => 'avg_position',
			'label'     => __( 'Avg Position', 'marketing-analytics-chat' ),
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

		$mid        = (int) floor( count( $data ) / 2 );
		$first_half = array_slice( $data, 0, $mid );
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
	 */
	public function dismiss_onboarding_wizard() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'marketing_analytics_mcp_dismiss_wizard' ) ) {
			wp_send_json_error(
				array(
					'message' => 'Security check failed.',
				)
			);
			return;
		}

		if ( ! Permission_Manager::can_access_plugin() ) {
			wp_send_json_error(
				array(
					'message' => 'You do not have permission to perform this action.',
				)
			);
			return;
		}

		update_option( 'marketing_analytics_mcp_onboarding_complete', true );

		wp_send_json_success(
			array(
				'message' => 'Onboarding wizard dismissed.',
			)
		);
	}
}
