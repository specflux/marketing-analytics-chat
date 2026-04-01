<?php
/**
 * Plugin Deactivation Handler
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics;

use Specflux_Marketing_Analytics\Utils\Permission_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Fired during plugin deactivation
 */
class Deactivator {

	/**
	 * Deactivate the plugin
	 *
	 * - Clear scheduled cron jobs
	 * - Clear transient caches
	 * - Flush rewrite rules
	 */
	public static function deactivate() {
		// Clear all plugin transients.
		self::clear_all_caches();

		// Clear any scheduled cron jobs.
		self::clear_scheduled_events();

		// Remove custom capabilities for role-based access control.
		Permission_Manager::remove_capabilities();

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Clear all plugin caches
	 */
	private static function clear_all_caches() {
		global $wpdb;

		// Delete all transients with our prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation during deactivation, caching not applicable.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_specflux_mac_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_specflux_mac_' ) . '%'
			)
		);
	}

	/**
	 * Clear scheduled cron events
	 */
	private static function clear_scheduled_events() {
		// Clear any scheduled events (if we add cron jobs in the future).
		$scheduled_hooks = array(
			'specflux_mac_daily_cleanup',
			'specflux_mac_refresh_tokens',
		);

		foreach ( $scheduled_hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}
