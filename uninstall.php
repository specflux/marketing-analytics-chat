<?php
/**
 * Plugin Uninstall Handler
 *
 * Fired when the plugin is uninstalled.
 *
 * @package Specflux_Marketing_Analytics
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete all plugin data
 *
 * This includes:
 * - Plugin options
 * - Encryption key (irreversibly destroys encrypted credentials)
 * - All transient caches
 * - API rate limit counters
 */
function specflux_mac_uninstall() {
	global $wpdb;

	// Delete main plugin options.
	delete_option( 'specflux_mac_settings' );
	delete_option( 'specflux_mac_encryption_key' );

	// Delete encrypted credentials.
	delete_option( 'specflux_mac_credentials_clarity' );
	delete_option( 'specflux_mac_credentials_ga4' );
	delete_option( 'specflux_mac_credentials_gsc' );

	// Delete OAuth tokens.
	delete_option( 'specflux_mac_oauth_tokens' );

	// Delete Google OAuth credentials.
	delete_option( 'specflux_mac_google_client_id' );
	delete_option( 'specflux_mac_google_client_secret' );
	delete_option( 'specflux_mac_oauth_state' );

	// Delete onboarding and prompt options.
	delete_option( 'specflux_mac_onboarding_complete' );
	delete_option( 'specflux_mac_custom_prompts' );

	// Delete connection and access control options.
	delete_option( 'specflux_mac_ga4_property_id' );
	delete_option( 'specflux_mac_gsc_site_url' );
	delete_option( 'specflux_mac_allowed_roles' );

	// Delete anomaly data.
	delete_option( 'specflux_mac_recent_anomalies' );

	// Delete rate limit counters.
	delete_option( 'specflux_mac_rate_limits' );

	// Delete all transients (properly escape LIKE patterns).
	$transient_pattern = $wpdb->esc_like( '_transient_specflux_mac_' ) . '%';
	$timeout_pattern   = $wpdb->esc_like( '_transient_timeout_specflux_mac_' ) . '%';

	// Fetch matching transients and delete via API to clear caches.
	$cache_group       = 'specflux_mac_uninstall';
	$cache_key         = 'transient_options';
	$transient_options = wp_cache_get( $cache_key, $cache_group );

	if ( false === $transient_options ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Needed to locate all matching transients for cleanup with transient cache.
		$transient_options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$transient_pattern,
				$timeout_pattern
			)
		);
		wp_cache_set( $cache_key, $transient_options, $cache_group, MINUTE_IN_SECONDS );
	}

	if ( ! empty( $transient_options ) ) {
		foreach ( $transient_options as $option_name ) {
			delete_option( $option_name );
		}
	}

	// Clear any scheduled cron jobs.
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

	// Drop chat tables.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}specflux_mac_messages" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}specflux_mac_conversations" );
}

specflux_mac_uninstall();
