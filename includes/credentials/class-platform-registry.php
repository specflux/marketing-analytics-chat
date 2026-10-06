<?php
/**
 * Platform Registry
 *
 * Single source of truth for the data platforms the plugin can connect to.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Credentials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registry of connectable platforms.
 *
 * Core platforms are defined here. Add-ons and future platforms register
 * through the `specflux_mac_platforms` filter.
 *
 * Definition keys:
 * - slug            (string)        Unique identifier, lowercase [a-z0-9_].
 * - label           (string)        Human-readable name (used for the Connections tab).
 * - auth            (string)        'api_key', 'google_oauth' or 'none'.
 * - fields          (string[])      Credential field names stored (encrypted) for the platform.
 * - cache_ttl       (int)           Default cache lifetime in seconds.
 * - connection_test (callable|null) Returns array( 'success' => bool, 'message' => string ).
 * - scopes          (string[])      OAuth scopes (google_oauth platforms only).
 * - tab             (bool)          Whether to show a Connections tab. Default true.
 * - optional_fields  (string[])      Subset of `fields` that may be left blank (api_key platforms).
 * - description     (string)        Short intro shown on the default connection panel.
 * - help_url        (string)        Link to where the user obtains credentials.
 * - cleanup_options  (string[])      Option names deleted when the platform is disconnected.
 */
class Platform_Registry {

	/**
	 * Placeholder shown in secret inputs when a value is already stored.
	 */
	const SECRET_MASK = '••••••••';

	/**
	 * Per-request cache of the filtered platform list.
	 *
	 * @var array|null
	 */
	private static $platforms = null;

	/**
	 * Get all registered platforms
	 *
	 * @return array Map of slug => definition.
	 */
	public static function all() {
		if ( null !== self::$platforms ) {
			return self::$platforms;
		}

		/**
		 * Filter the registered platforms.
		 *
		 * @param array $platforms Map of slug => definition array.
		 */
		$filtered = apply_filters( 'specflux_mac_platforms', self::core_platforms() );
		$filtered = is_array( $filtered ) ? $filtered : self::core_platforms();

		$platforms = array();
		foreach ( $filtered as $key => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$slug = isset( $definition['slug'] ) ? $definition['slug'] : $key;
			if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9_]+$/', $slug ) ) {
				continue;
			}

			$platforms[ $slug ] = self::normalize( $slug, $definition );
		}

		self::$platforms = $platforms;
		return self::$platforms;
	}

	/**
	 * Get one platform definition
	 *
	 * @param string $slug Platform slug.
	 * @return array|null Definition or null when not registered.
	 */
	public static function get( $slug ) {
		$platforms = self::all();
		return ( is_string( $slug ) && isset( $platforms[ $slug ] ) ) ? $platforms[ $slug ] : null;
	}

	/**
	 * Get the registered platform slugs
	 *
	 * @return string[] Slugs in registration order.
	 */
	public static function slugs() {
		return array_keys( self::all() );
	}

	/**
	 * Check whether a platform is registered
	 *
	 * @param string $slug Platform slug.
	 * @return bool
	 */
	public static function is_registered( $slug ) {
		return null !== self::get( $slug );
	}

	/**
	 * Whether a credential field holds a secret (rendered as a password input, masked when saved)
	 *
	 * @param string $field Field name.
	 * @return bool
	 */
	public static function is_secret_field( $field ) {
		return (bool) preg_match( '/key|token|secret|password/i', $field );
	}

	/**
	 * Drop the per-request cache (for tests and late registrations).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$platforms = null;
	}

	/**
	 * Fill in defaults for a definition
	 *
	 * @param string $slug       Platform slug.
	 * @param array  $definition Raw definition.
	 * @return array Normalised definition.
	 */
	private static function normalize( $slug, array $definition ) {
		$defaults = array(
			'slug'            => $slug,
			'label'           => $slug,
			'auth'            => 'api_key',
			'fields'          => array(),
			'cache_ttl'       => HOUR_IN_SECONDS,
			'connection_test' => null,
			'scopes'          => array(),
			'tab'             => true,
			'optional_fields' => array(),
			'description'     => '',
			'help_url'        => '',
			'cleanup_options' => array(),
		);

		$normalized         = array_merge( $defaults, $definition );
		$normalized['slug'] = $slug;

		if ( ! in_array( $normalized['auth'], array( 'api_key', 'google_oauth', 'none' ), true ) ) {
			$normalized['auth'] = 'api_key';
		}

		return $normalized;
	}

	/**
	 * Platforms shipped with the free plugin
	 *
	 * @return array Map of slug => definition.
	 */
	private static function core_platforms() {
		return array(
			'clarity' => array(
				'slug'      => 'clarity',
				'label'     => __( 'Microsoft Clarity', 'specflux-marketing-analytics-chat' ),
				'auth'      => 'api_key',
				'fields'    => array( 'api_token', 'project_id' ),
				'cache_ttl' => HOUR_IN_SECONDS, // Rate limit: 10 req/day.
			),
			'ga4'     => array(
				'slug'      => 'ga4',
				'label'     => __( 'Google Analytics 4', 'specflux-marketing-analytics-chat' ),
				'auth'      => 'google_oauth',
				'fields'    => array(),
				'cache_ttl' => 30 * MINUTE_IN_SECONDS, // Near real-time but expensive.
				'scopes'    => OAuth_Handler::SCOPES_GA4,
				'cleanup_options' => array( 'specflux_mac_ga4_property_id' ),
			),
			'gsc'     => array(
				'slug'      => 'gsc',
				'label'     => __( 'Google Search Console', 'specflux-marketing-analytics-chat' ),
				'auth'      => 'google_oauth',
				'fields'    => array(),
				'cache_ttl' => DAY_IN_SECONDS, // Data has a 2-3 day delay.
				'scopes'    => OAuth_Handler::SCOPES_GSC,
				'cleanup_options' => array( 'specflux_mac_gsc_site_url' ),
			),
		);
	}
}
