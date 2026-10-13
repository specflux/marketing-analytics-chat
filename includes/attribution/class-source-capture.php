<?php
/**
 * First-party source capture
 *
 * Reads the anonymous source cookie written by assets/js/source-capture.js and
 * enqueues that script on the front end. The cookie holds no identifier: only
 * source_type, source, medium, campaign and landing_page (path only).
 *
 * source_type uses WooCommerce's attribution vocabulary (utm, organic,
 * referral, typein) so Woo_Sales_Reader::channel_for() labels it unchanged.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Attribution;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Cookie parsing, consent and front-end script.
 */
class Source_Capture {

	/**
	 * Meta key written on every record. Fixed contract: do not rename.
	 */
	const META_KEY = '_specflux_mac_attribution';

	/**
	 * Cookie name.
	 */
	const COOKIE = 'specflux_mac_src';

	/**
	 * Cookie lifetime in seconds. Sliding: refreshed on every page view, so it
	 * ends 30 minutes after the last page view (a visit), never longer.
	 */
	const COOKIE_TTL = 1800;

	/**
	 * Settings switch (default on).
	 */
	const OPTION_ENABLED = 'specflux_mac_source_capture_enabled';

	/**
	 * Allowed keys, in storage order.
	 */
	const KEYS = array( 'source_type', 'source', 'medium', 'campaign', 'landing_page' );

	/**
	 * Allowed source types (WooCommerce vocabulary).
	 */
	const SOURCE_TYPES = array( 'utm', 'organic', 'referral', 'typein' );

	/**
	 * Per-field length caps.
	 */
	const MAX_LENGTH = 100;

	/**
	 * Landing page path length cap.
	 */
	const MAX_PATH_LENGTH = 200;

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		( new Attribution_Hooks() )->register_hooks();
	}

	/**
	 * Whether capture is on: switch enabled and, when the WP Consent API is
	 * active, statistics consent given.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( ! (bool) get_option( self::OPTION_ENABLED, 1 ) ) {
			return false;
		}

		if ( function_exists( 'wp_has_consent' ) ) {
			return (bool) wp_has_consent( 'statistics' );
		}

		return true;
	}

	/**
	 * Parsed, sanitized cookie for this request, or null.
	 *
	 * @return array|null Array with exactly the KEYS, or null.
	 */
	public static function current(): ?array {
		if ( ! self::is_enabled() ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed and sanitized in parse().
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';

		return self::parse( $raw );
	}

	/**
	 * Parse and sanitize a raw cookie value (JSON, possibly URL-encoded).
	 *
	 * @param string $raw Raw cookie value.
	 * @return array|null
	 */
	public static function parse( string $raw ): ?array {
		if ( '' === $raw || strlen( $raw ) > 2000 ) {
			return null;
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = json_decode( rawurldecode( $raw ), true );
		}
		if ( ! is_array( $data ) ) {
			return null;
		}

		$type = isset( $data['source_type'] ) && is_string( $data['source_type'] ) ? strtolower( trim( $data['source_type'] ) ) : '';
		if ( ! in_array( $type, self::SOURCE_TYPES, true ) ) {
			return null;
		}

		$out = array( 'source_type' => $type );
		foreach ( array( 'source', 'medium', 'campaign' ) as $key ) {
			$out[ $key ] = self::clean_text( $data[ $key ] ?? '', self::MAX_LENGTH );
		}
		$out['landing_page'] = self::clean_path( $data['landing_page'] ?? '' );

		return $out;
	}

	/**
	 * Sanitize a text value. Values that look like an email address are dropped.
	 *
	 * @param mixed $value  Raw value.
	 * @param int   $length Max length.
	 * @return string
	 */
	private static function clean_text( $value, int $length ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = sanitize_text_field( $value );
		if ( false !== strpos( $value, '@' ) ) {
			return '';
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Reduce a value to a path only (no host, query or fragment).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean_path( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$path = (string) wp_parse_url( $value, PHP_URL_PATH );
		if ( '' === $path || '/' !== $path[0] ) {
			return '';
		}

		return self::clean_text( $path, self::MAX_PATH_LENGTH );
	}

	/**
	 * Enqueue the capture script on the front end.
	 *
	 * @return void
	 */
	public function enqueue_script() {
		if ( is_admin() || ! (bool) get_option( self::OPTION_ENABLED, 1 ) ) {
			return;
		}

		wp_enqueue_script(
			'specflux-mac-source-capture',
			SPECFLUX_MAC_URL . 'assets/js/source-capture.js',
			array(),
			SPECFLUX_MAC_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'specflux-mac-source-capture',
			'specfluxMacSource',
			array(
				'cookie' => self::COOKIE,
				'ttl'    => self::COOKIE_TTL,
			)
		);
	}

	/**
	 * Suggested privacy policy text.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<h2>' . esc_html__( 'Visit source cookie', 'specflux-marketing-analytics-chat' ) . '</h2><p>' .
			esc_html__( 'When you visit this site we may set a first-party cookie named specflux_mac_src. It holds how you arrived (for example the campaign tag in the link or the referring site), the type and medium of that source, and the page path you landed on. It contains no identifier, name, email address or IP address, and it expires 30 minutes after your last page view. If you place an order, make a donation or submit a form, the same details are saved with that record so the site owner can see which channel it came from. No data is sent to third parties.', 'specflux-marketing-analytics-chat' ) . '</p>';

		wp_add_privacy_policy_content( 'Specflux Marketing Analytics Chat', wp_kses_post( wpautop( $text, false ) ) );
	}
}
