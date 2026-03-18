<?php
/**
 * Plugin Activation Handler
 *
 * @package Marketing_Analytics_MCP
 */

namespace Marketing_Analytics_MCP;

use Marketing_Analytics_MCP\Prompts\Prompt_Manager;
use Marketing_Analytics_MCP\Utils\Permission_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Fired during plugin activation
 */
class Activator {

	/**
	 * Activate the plugin
	 *
	 * - Check WordPress and PHP version requirements
	 * - Create database tables if needed
	 * - Set default options
	 * - Generate encryption key
	 */
	public static function activate() {
		// Check minimum WordPress version
		if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
			wp_die(
				esc_html__( 'Marketing Analytics Chat requires WordPress 6.90 or higher.', 'marketing-analytics-chat' ),
				esc_html__( 'Plugin Activation Error', 'marketing-analytics-chat' ),
				array( 'back_link' => true )
			);
		}

		// Check minimum PHP version
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			wp_die(
				esc_html__( 'Marketing Analytics Chat requires PHP 8.1 or higher.', 'marketing-analytics-chat' ),
				esc_html__( 'Plugin Activation Error', 'marketing-analytics-chat' ),
				array( 'back_link' => true )
			);
		}

		// Check for required PHP extensions
		$required_extensions = array( 'json', 'curl', 'openssl', 'sodium' );
		$missing_extensions  = array();

		foreach ( $required_extensions as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$missing_extensions[] = $extension;
			}
		}

		if ( ! empty( $missing_extensions ) ) {
			wp_die(
				sprintf(
					/* translators: %s: comma-separated list of PHP extensions */
					esc_html__( 'Marketing Analytics Chat requires the following PHP extensions: %s', 'marketing-analytics-chat' ),
					esc_html( implode( ', ', $missing_extensions ) )
				),
				esc_html__( 'Plugin Activation Error', 'marketing-analytics-chat' ),
				array( 'back_link' => true )
			);
		}

		// Set default options
		self::set_default_options();

		// Generate encryption key if it doesn't exist
		self::generate_encryption_key();

		// Register custom capabilities for role-based access control
		Permission_Manager::register_capabilities();

		// Install default smart prompts on first activation
		self::install_default_prompts();

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Set default plugin options
	 */
	private static function set_default_options() {
		$defaults = array(
			'version'           => MARKETING_ANALYTICS_MCP_VERSION,
			'cache_ttl_clarity' => HOUR_IN_SECONDS,
			'cache_ttl_ga4'     => 30 * MINUTE_IN_SECONDS,
			'cache_ttl_gsc'     => DAY_IN_SECONDS,
			'debug_mode'        => false,
			'platforms'         => array(
				'clarity' => array(
					'enabled'   => false,
					'connected' => false,
				),
				'ga4'     => array(
					'enabled'   => false,
					'connected' => false,
				),
				'gsc'     => array(
					'enabled'   => false,
					'connected' => false,
				),
			),
		);

		// Only add if option doesn't exist
		add_option( 'marketing_analytics_mcp_settings', $defaults );
	}

	/**
	 * Install default smart prompts on first activation
	 *
	 * Imports preset prompt templates so new users have useful
	 * prompts available immediately after activation.
	 */
	private static function install_default_prompts() {
		// Only install if prompts option doesn't already exist (first activation).
		if ( false !== get_option( Prompt_Manager::OPTION_NAME ) ) {
			return;
		}

		$prompt_manager = new Prompt_Manager();

		$default_presets = array(
			'weekly-report',
			'seo-health-check',
			'anomaly-investigation',
		);

		foreach ( $default_presets as $preset_key ) {
			$prompt_manager->import_preset( $preset_key );
		}
	}

	/**
	 * Generate encryption key for credentials
	 */
	private static function generate_encryption_key() {
		$key_option = 'marketing_analytics_mcp_encryption_key';

		// Only generate if key doesn't exist
		if ( ! get_option( $key_option ) ) {
			$key = base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
			add_option( $key_option, $key, '', false ); // Don't autoload
		}
	}
}
