<?php
/**
 * Plugin Activation Handler
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics;

use Specflux_Marketing_Analytics\Prompts\Prompt_Manager;
use Specflux_Marketing_Analytics\Utils\Permission_Manager;

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
		// Check minimum WordPress version.
		if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
			wp_die(
				esc_html__( 'Specflux Marketing Analytics Chat requires WordPress 6.9 or higher.', 'specflux-marketing-analytics-chat' ),
				esc_html__( 'Plugin Activation Error', 'specflux-marketing-analytics-chat' ),
				array( 'back_link' => true )
			);
		}

		// Check minimum PHP version.
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			wp_die(
				esc_html__( 'Specflux Marketing Analytics Chat requires PHP 8.1 or higher.', 'specflux-marketing-analytics-chat' ),
				esc_html__( 'Plugin Activation Error', 'specflux-marketing-analytics-chat' ),
				array( 'back_link' => true )
			);
		}

		// Check for required PHP extensions.
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
					esc_html__( 'Specflux Marketing Analytics Chat requires the following PHP extensions: %s', 'specflux-marketing-analytics-chat' ),
					esc_html( implode( ', ', $missing_extensions ) )
				),
				esc_html__( 'Plugin Activation Error', 'specflux-marketing-analytics-chat' ),
				array( 'back_link' => true )
			);
		}

		// Set default options.
		self::set_default_options();

		// Create chat tables.
		self::create_chat_tables();

		// Generate encryption key if it doesn't exist.
		self::generate_encryption_key();

		// Register custom capabilities for role-based access control.
		Permission_Manager::register_capabilities();

		// Install default smart prompts on first activation.
		self::install_default_prompts();

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Set default plugin options
	 */
	private static function set_default_options() {
		$defaults = array(
			'version'           => SPECFLUX_MAC_VERSION,
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

		// Only add if option doesn't exist.
		add_option( 'specflux_mac_settings', $defaults );
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
	 * Create database tables for AI chat conversations and messages
	 */
	private static function create_chat_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$conversations_table = $wpdb->prefix . 'specflux_mac_conversations';
		$messages_table      = $wpdb->prefix . 'specflux_mac_messages';

		$sql = "CREATE TABLE {$conversations_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY updated_at (updated_at)
		) {$charset_collate};

		CREATE TABLE {$messages_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL,
			role varchar(20) NOT NULL DEFAULT 'user',
			content longtext NOT NULL,
			tool_calls longtext DEFAULT NULL,
			tool_call_id varchar(255) DEFAULT NULL,
			tool_name varchar(255) DEFAULT NULL,
			metadata longtext DEFAULT NULL,
			tokens_used int(11) unsigned DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		if ( file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Generate encryption key for credentials
	 */
	private static function generate_encryption_key() {
		$key_option = 'specflux_mac_encryption_key';

		// Only generate if key doesn't exist.
		if ( ! get_option( $key_option ) ) {
			$key = base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
			add_option( $key_option, $key, '', false ); // Don't autoload.
		}
	}
}
