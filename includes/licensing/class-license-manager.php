<?php
/**
 * License Manager
 *
 * Handles LemonSqueezy license key validation for pro features.
 *
 * @package Marketing_Analytics_MCP
 */

namespace Marketing_Analytics_MCP\Licensing;

use Marketing_Analytics_MCP\Utils\Logger;

/**
 * Manages LemonSqueezy license activation, validation, and deactivation
 */
class License_Manager {

	/**
	 * LemonSqueezy API endpoints
	 */
	private const ACTIVATE_URL   = 'https://api.lemonsqueezy.com/v1/licenses/activate';
	private const VALIDATE_URL   = 'https://api.lemonsqueezy.com/v1/licenses/validate';
	private const DEACTIVATE_URL = 'https://api.lemonsqueezy.com/v1/licenses/deactivate';

	/**
	 * WordPress option keys
	 */
	private const OPTION_KEY = 'marketing_analytics_mcp_license';
	private const CACHE_KEY  = 'marketing_analytics_mcp_license_valid';

	/**
	 * Cache TTL in seconds (12 hours)
	 */
	private const CACHE_TTL = 43200;

	/**
	 * Activate a license key
	 *
	 * @param string $license_key The LemonSqueezy license key.
	 * @return array{success: bool, message: string, data?: array<string, mixed>}
	 */
	public function activate( $license_key ) {
		if ( empty( $license_key ) ) {
			return array(
				'success' => false,
				'message' => __( 'License key is required.', 'marketing-analytics-chat' ),
			);
		}

		$response = wp_remote_post(
			self::ACTIVATE_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'license_key'   => $license_key,
					'instance_name' => $this->get_instance_name(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'License activation API error: ' . $response->get_error_message() );
			return array(
				'success' => false,
				'message' => __( 'Could not connect to license server. Please try again.', 'marketing-analytics-chat' ),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code || empty( $body['activated'] ) ) {
			$error = isset( $body['error'] ) ? $body['error'] : __( 'Activation failed.', 'marketing-analytics-chat' );
			Logger::warning( 'License activation failed: ' . $error );
			return array(
				'success' => false,
				'message' => $error,
			);
		}

		// Store license data.
		$license_data = array(
			'license_key'   => $license_key,
			'instance_id'   => isset( $body['instance']['id'] ) ? $body['instance']['id'] : '',
			'status'        => isset( $body['license_key']['status'] ) ? $body['license_key']['status'] : 'active',
			'customer_name' => isset( $body['meta']['customer_name'] ) ? $body['meta']['customer_name'] : '',
			'customer_email' => isset( $body['meta']['customer_email'] ) ? $body['meta']['customer_email'] : '',
			'product_name'  => isset( $body['meta']['product_name'] ) ? $body['meta']['product_name'] : '',
			'variant_name'  => isset( $body['meta']['variant_name'] ) ? $body['meta']['variant_name'] : '',
			'activated_at'  => current_time( 'mysql' ),
		);

		update_option( self::OPTION_KEY, $license_data, false );
		set_transient( self::CACHE_KEY, true, self::CACHE_TTL );

		Logger::debug( 'License activated successfully.' );

		return array(
			'success' => true,
			'message' => __( 'License activated successfully!', 'marketing-analytics-chat' ),
			'data'    => $license_data,
		);
	}

	/**
	 * Validate the stored license key
	 *
	 * @return array{success: bool, message: string, data?: array<string, mixed>}
	 */
	public function validate() {
		$license_data = $this->get_license_data();

		if ( empty( $license_data['license_key'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'No license key found.', 'marketing-analytics-chat' ),
			);
		}

		$response = wp_remote_post(
			self::VALIDATE_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'license_key'   => $license_data['license_key'],
					'instance_id'   => isset( $license_data['instance_id'] ) ? $license_data['instance_id'] : '',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'License validation API error: ' . $response->get_error_message() );
			// On API error, use cached status if available.
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return array(
					'success' => true,
					'message' => __( 'License valid (cached).', 'marketing-analytics-chat' ),
				);
			}
			return array(
				'success' => false,
				'message' => __( 'Could not validate license. Please try again.', 'marketing-analytics-chat' ),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$valid = isset( $body['valid'] ) && true === $body['valid'];

		if ( $valid ) {
			// Update stored status.
			$license_data['status'] = isset( $body['license_key']['status'] ) ? $body['license_key']['status'] : 'active';
			update_option( self::OPTION_KEY, $license_data, false );
			set_transient( self::CACHE_KEY, true, self::CACHE_TTL );

			return array(
				'success' => true,
				'message' => __( 'License is valid.', 'marketing-analytics-chat' ),
				'data'    => $license_data,
			);
		}

		// License is invalid — clear cache.
		delete_transient( self::CACHE_KEY );
		$license_data['status'] = isset( $body['license_key']['status'] ) ? $body['license_key']['status'] : 'invalid';
		update_option( self::OPTION_KEY, $license_data, false );

		$error = isset( $body['error'] ) ? $body['error'] : __( 'License is not valid.', 'marketing-analytics-chat' );

		return array(
			'success' => false,
			'message' => $error,
		);
	}

	/**
	 * Deactivate the current license
	 *
	 * @return array{success: bool, message: string}
	 */
	public function deactivate() {
		$license_data = $this->get_license_data();

		if ( empty( $license_data['license_key'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'No license key found.', 'marketing-analytics-chat' ),
			);
		}

		$response = wp_remote_post(
			self::DEACTIVATE_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'license_key' => $license_data['license_key'],
					'instance_id' => isset( $license_data['instance_id'] ) ? $license_data['instance_id'] : '',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'License deactivation API error: ' . $response->get_error_message() );
			return array(
				'success' => false,
				'message' => __( 'Could not connect to license server. Please try again.', 'marketing-analytics-chat' ),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$deactivated = isset( $body['deactivated'] ) && true === $body['deactivated'];

		// Clear stored data regardless of API response.
		delete_option( self::OPTION_KEY );
		delete_transient( self::CACHE_KEY );

		if ( $deactivated ) {
			Logger::debug( 'License deactivated successfully.' );
			return array(
				'success' => true,
				'message' => __( 'License deactivated successfully.', 'marketing-analytics-chat' ),
			);
		}

		// Even if API says not deactivated, we've cleared local data.
		Logger::warning( 'License deactivation API returned unexpected response, local data cleared.' );
		return array(
			'success' => true,
			'message' => __( 'License removed from this site.', 'marketing-analytics-chat' ),
		);
	}

	/**
	 * Check if pro features are active
	 *
	 * Uses cached transient to avoid API calls on every page load.
	 *
	 * @return bool
	 */
	public function is_pro() {
		// Check cache first.
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return true;
		}

		// No cache — check stored license data.
		$license_data = $this->get_license_data();
		if ( empty( $license_data['license_key'] ) || empty( $license_data['status'] ) ) {
			return false;
		}

		// Only re-validate if we have a license key with an active status.
		if ( 'active' !== $license_data['status'] ) {
			return false;
		}

		// Re-validate in the background.
		$result = $this->validate();
		return $result['success'];
	}

	/**
	 * Static convenience method for pro feature gating
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		$instance = new self();
		return $instance->is_pro();
	}

	/**
	 * Get stored license data
	 *
	 * @return array<string, mixed>
	 */
	public function get_license_data() {
		return get_option( self::OPTION_KEY, array() );
	}

	/**
	 * Get the stored instance ID (needed for deactivation)
	 *
	 * @return string
	 */
	public function get_instance_id() {
		$license_data = $this->get_license_data();
		return isset( $license_data['instance_id'] ) ? $license_data['instance_id'] : '';
	}

	/**
	 * Get the instance name for this site
	 *
	 * @return string
	 */
	private function get_instance_name() {
		return wp_parse_url( get_site_url(), PHP_URL_HOST );
	}
}
