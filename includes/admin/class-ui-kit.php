<?php
/**
 * Shared admin UI kit
 *
 * Locates, enqueues and prints the vendored kit under assets/vendor/admin-ui-kit/.
 * Plain CSS that ships inside the plugin: nothing is loaded from a CDN. Every rule
 * in the kit is nested under `.aui`, and it is only enqueued on this plugin's own
 * admin screens (handles prefixed `specflux-mac-ui-`).
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Vendored kit helper: enqueue, icon sprite, icon, shared header.
 */
class Ui_Kit {

	/**
	 * Vendored kit directory, relative to the plugin root.
	 */
	const DIR = 'assets/vendor/admin-ui-kit/';

	/**
	 * Style handle for the kit tokens.
	 */
	const HANDLE_TOKENS = 'specflux-mac-ui-tokens';

	/**
	 * Style handle for the kit components.
	 */
	const HANDLE_STYLE = 'specflux-mac-ui-kit';

	/**
	 * Script handle for the kit behaviour (tabs, dialogs, copy, save bar).
	 */
	const HANDLE_SCRIPT = 'specflux-mac-ui-kit-js';

	/**
	 * Absolute path of a vendored file.
	 *
	 * @param string $file File name inside the vendored kit directory.
	 * @return string
	 */
	public static function path( $file ) {
		return SPECFLUX_MAC_PATH . self::DIR . $file;
	}

	/**
	 * Contents of a vendored file, or an empty string when it is missing.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function read( $path ) {
		$contents = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local plugin file, not a remote URL.

		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * Cache-busting version: the kit's own version plus a short hash of its manifest.
	 *
	 * @return string
	 */
	public static function version() {
		$kit = trim( self::read( self::path( 'VERSION' ) ) );

		return ( '' === $kit ? '0' : $kit ) . '-' . substr( md5( self::read( self::path( 'MANIFEST.sha256' ) ) ), 0, 8 );
	}

	/**
	 * Whether an admin page hook belongs to this plugin's own screens.
	 *
	 * The top-level page is `toplevel_page_specflux-mac`; submenu pages are
	 * `<sanitized menu title>_page_specflux-mac-*`. The WordPress Dashboard
	 * (`index.php`) and every other screen are excluded.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return bool
	 */
	public static function is_plugin_screen( $hook ) {
		$hook = (string) $hook;

		return 'toplevel_page_specflux-mac' === $hook || false !== strpos( $hook, '_page_specflux-mac' );
	}

	/**
	 * Enqueue the kit stylesheets. Call only on this plugin's screens.
	 */
	public static function enqueue() {
		$base    = SPECFLUX_MAC_URL . self::DIR;
		$version = self::version();

		wp_enqueue_style( self::HANDLE_TOKENS, $base . 'tokens.css', array(), $version );
		wp_enqueue_style( self::HANDLE_STYLE, $base . 'components.css', array( self::HANDLE_TOKENS ), $version );
		wp_enqueue_script( self::HANDLE_SCRIPT, $base . 'aui.js', array(), $version, true );
		wp_add_inline_script( self::HANDLE_SCRIPT, 'window.AUI && window.AUI.init(document);' );
	}

	/**
	 * The icon sprite as inline markup (hidden), printed once per page so
	 * `<use href="#aui-i-x">` resolves.
	 *
	 * @return string
	 */
	public static function sprite() {
		return self::read( self::path( 'icons.svg' ) );
	}

	/**
	 * One icon from the sprite, as escaped markup.
	 *
	 * @param string $name  Sprite id without the `aui-i-` prefix.
	 * @param string $extra Extra classes (for example `aui-i--lg`).
	 * @return string
	 */
	public static function icon( $name, $extra = '' ) {
		$class = trim( 'aui-i ' . $extra );

		return '<svg class="' . esc_attr( $class ) . '" aria-hidden="true" focusable="false"><use href="#aui-i-' . esc_attr( $name ) . '"/></svg>';
	}

	/**
	 * The six screens, in nav order: page slug => nav label.
	 *
	 * @return array<string,string>
	 */
	public static function pages() {
		return array(
			'specflux-mac'              => __( 'Dashboard', 'specflux-marketing-analytics-chat' ),
			'specflux-mac-ai-assistant' => __( 'AI Assistant', 'specflux-marketing-analytics-chat' ),
			'specflux-mac-connections'  => __( 'Connections', 'specflux-marketing-analytics-chat' ),
			'specflux-mac-prompts'      => __( 'Prompts', 'specflux-marketing-analytics-chat' ),
			'specflux-mac-abilities'    => __( 'Abilities', 'specflux-marketing-analytics-chat' ),
			'specflux-mac-settings'     => __( 'Settings', 'specflux-marketing-analytics-chat' ),
		);
	}

	/**
	 * Print the shared app header (and the icon sprite) at the top of a screen.
	 *
	 * @param string $current Page slug of the screen being rendered.
	 */
	public static function render_header( $current ) {
		$specflux_mac_current = (string) $current;

		require SPECFLUX_MAC_PATH . 'admin/views/partials/app-header.php';
	}
}
