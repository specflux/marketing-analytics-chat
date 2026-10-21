<?php
/**
 * Tests for the Connections and Settings form helpers and the markup contract
 * of the two screens (field names, nonces, extension hooks).
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Admin\Form_Ui;

/**
 * Form_Ui test class.
 */
class FormUiTest extends TestCase {

	/**
	 * Reset the recorded actions.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['specflux_mac_test_actions'] = array();
	}

	/**
	 * Capture the output of a callable.
	 *
	 * @param callable $fn Callback that prints markup.
	 * @return string
	 */
	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	/**
	 * Read a view source file.
	 *
	 * @param string $relative Path under admin/views/.
	 * @return string
	 */
	private function view( string $relative ): string {
		return (string) file_get_contents( SPECFLUX_MAC_PATH . 'admin/views/' . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
	}

	/**
	 * Core tabs keep their URLs and only the active one is aria-current.
	 */
	public function test_sub_nav_keeps_urls_and_marks_the_active_tab(): void {
		$items = array(
			array(
				'slug'  => 'general',
				'label' => 'General',
				'url'   => '?page=specflux-mac-settings&tab=general',
			),
			array(
				'slug'  => 'cache',
				'label' => 'Cache',
				'url'   => '?page=specflux-mac-settings&tab=cache',
			),
		);

		$html = $this->capture( static fn() => Form_Ui::sub_nav( $items, 'cache', 'specflux_mac_settings_tabs', 'Settings' ) );

		$this->assertStringContainsString( '<nav class="aui-seg smac-subnav" aria-label="Settings">', $html );
		$this->assertStringContainsString( '<a href="?page=specflux-mac-settings&amp;tab=general">General</a>', $html );
		$this->assertStringContainsString( '<a href="?page=specflux-mac-settings&amp;tab=cache" aria-current="page">Cache</a>', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
	}

	/**
	 * The extension action fires once, with the active tab, and its links land inside the nav after the core tabs.
	 */
	public function test_sub_nav_fires_the_extension_hook_with_the_active_tab(): void {
		$items = array(
			array(
				'slug'  => 'clarity',
				'label' => 'Clarity',
				'url'   => '?page=specflux-mac-connections&tab=clarity',
			),
		);

		$html = $this->capture( static fn() => Form_Ui::sub_nav( $items, 'meta', 'specflux_mac_connections_tabs', 'Connections' ) );

		$fired = array_values(
			array_filter(
				$GLOBALS['specflux_mac_test_actions'],
				static fn( $a ) => 'specflux_mac_connections_tabs' === $a[0]
			)
		);
		$this->assertCount( 1, $fired );
		$this->assertSame( array( 'meta' ), $fired[0][1] );
		$this->assertLessThan( strpos( $html, '</nav>' ), strpos( $html, 'Clarity' ) );
	}

	/**
	 * Core and add-on platforms land in their groups, in order, with the active one marked and connection state spoken.
	 */
	public function test_platform_nav_groups_core_and_add_on_links(): void {
		$items = array(
			array(
				'slug'  => 'clarity',
				'label' => 'Microsoft Clarity',
				'url'   => '?page=specflux-mac-connections&tab=clarity',
			),
			array(
				'slug'  => 'gsc',
				'label' => 'Google Search Console',
				'url'   => '?page=specflux-mac-connections&tab=gsc',
			),
			array(
				'slug'  => 'acme',
				'label' => 'Acme',
				'url'   => '?page=specflux-mac-connections&tab=acme',
			),
		);
		$extra = '<a href="?page=specflux-mac-connections&tab=meta" class="nav-tab nav-tab-active">Meta Business</a>'
			. '<a href="?page=specflux-mac-connections&amp;tab=dataforseo" class="nav-tab ">DataForSEO</a>'
			. '<a href="?page=specflux-mac-connections&tab=clarity" class="nav-tab ">Duplicate</a>'
			. '<span class="pro-note">Upgrade</span>';

		$html = Form_Ui::platform_nav_markup(
			$items,
			$extra,
			'meta',
			'Connections',
			array(
				'clarity'    => true,
				'gsc'        => false,
				'dataforseo' => false,
			)
		);

		$this->assertStringStartsWith( '<nav class="smac-pnav" aria-label="Connections">', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$this->assertStringContainsString( '<a href="?page=specflux-mac-connections&amp;tab=meta" aria-current="page">', $html );
		$this->assertStringNotContainsString( 'Duplicate', $html );
		$this->assertStringNotContainsString( 'nav-tab', $html );

		$analytics = strpos( $html, 'id="smac-pnav-analytics"' );
		$search    = strpos( $html, 'id="smac-pnav-search"' );
		$ads       = strpos( $html, 'id="smac-pnav-ads"' );
		$more      = strpos( $html, 'id="smac-pnav-more"' );
		$this->assertLessThan( $search, $analytics );
		$this->assertLessThan( strpos( $html, 'Google Search Console' ), $search );
		$this->assertLessThan( strpos( $html, 'DataForSEO' ), strpos( $html, 'Google Search Console' ) );
		$this->assertLessThan( $ads, strpos( $html, 'DataForSEO' ) );
		$this->assertLessThan( strpos( $html, 'Meta Business' ), $ads );
		$this->assertLessThan( strpos( $html, '>Acme<' ), $more );
		$this->assertStringNotContainsString( 'id="smac-pnav-email"', $html, 'empty groups are left out' );

		$this->assertStringContainsString( '<span class="smac-pnav-dot is-on" aria-hidden="true"></span><span class="smac-pnav-label">Microsoft Clarity</span><span class="aui-sr"> (connected)</span>', $html );
		$this->assertStringContainsString( '<span class="smac-pnav-label">DataForSEO</span><span class="aui-sr"> (not connected)</span>', $html );
		$this->assertStringContainsString( '<span class="smac-pnav-dot is-unknown" aria-hidden="true"></span><span class="smac-pnav-label">Meta Business</span></a>', $html );
		$this->assertStringContainsString( '<div class="smac-pnav-extra"><span class="pro-note">Upgrade</span></div>', $html );
	}

	/**
	 * The platform list fires the Connections extension action once, with the active tab.
	 */
	public function test_platform_nav_fires_the_extension_hook_with_the_active_tab(): void {
		$items = array(
			array(
				'slug'  => 'clarity',
				'label' => 'Clarity',
				'url'   => '?page=specflux-mac-connections&tab=clarity',
			),
		);

		$html = $this->capture( static fn() => Form_Ui::platform_nav( $items, 'ads', 'specflux_mac_connections_tabs', 'Connections' ) );

		$fired = array_values(
			array_filter(
				$GLOBALS['specflux_mac_test_actions'],
				static fn( $a ) => 'specflux_mac_connections_tabs' === $a[0]
			)
		);
		$this->assertCount( 1, $fired );
		$this->assertSame( array( 'ads' ), $fired[0][1] );
		$this->assertStringContainsString( '>Clarity</span>', $html );
	}

	/**
	 * Legacy `nav-tab-active` links printed by an add-on become aria-current links.
	 */
	public function test_mark_active_links_flags_only_the_active_add_on_tab(): void {
		$in  = '<a href="?tab=license" class="nav-tab nav-tab-active">License</a><a href="?tab=x" class="nav-tab ">X</a>';
		$out = Form_Ui::mark_active_links( $in );

		$this->assertStringContainsString( '<a href="?tab=license" class="nav-tab nav-tab-active" aria-current="page">', $out );
		$this->assertStringContainsString( '<a href="?tab=x" class="nav-tab ">', $out );
		$this->assertSame( 1, substr_count( $out, 'aria-current' ) );
		$this->assertSame( $out, Form_Ui::mark_active_links( $out ), 'idempotent' );
	}

	/**
	 * Connected, not connected, and a saved credential whose test failed (error).
	 */
	public function test_connection_state(): void {
		$this->assertSame( 'connected', Form_Ui::connection_state( true )['state'] );
		$this->assertSame( 'connected', Form_Ui::connection_state( true, array( 'success' => true ) )['state'] );
		$this->assertSame( 'disconnected', Form_Ui::connection_state( false )['state'] );
		$this->assertSame( 'disconnected', Form_Ui::connection_state( false, array( 'success' => false ) )['state'], 'no credentials is never an error' );

		$error = Form_Ui::connection_state(
			true,
			array(
				'success' => false,
				'message' => 'HTTP 403',
			)
		);
		$this->assertSame( 'error', $error['state'] );
		$this->assertSame( 'HTTP 403', $error['message'] );
		$this->assertSame( 'Error', $error['label'] );
	}

	/**
	 * Badge tone follows the state.
	 */
	public function test_status_badge_tones(): void {
		$this->assertStringContainsString( 'aui-badge--ok', Form_Ui::status_badge( 'connected', 'Connected' ) );
		$this->assertStringContainsString( 'aui-badge--bad', Form_Ui::status_badge( 'error', 'Error' ) );
		$this->assertStringNotContainsString( '--ok', Form_Ui::status_badge( 'disconnected', 'Not connected' ) );
		$this->assertStringContainsString( 'data-state="disconnected"', Form_Ui::status_badge( 'nonsense', 'x' ) );
	}

	/**
	 * Notices escape the message and pick the right live-region role.
	 */
	public function test_notice_escapes_and_sets_role(): void {
		$error = Form_Ui::notice( 'error', '<b>x</b>' );
		$this->assertStringContainsString( 'role="alert"', $error );
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $error );
		$this->assertStringContainsString( 'aui-notice--error', $error );
		$this->assertStringContainsString( 'role="status"', Form_Ui::notice( 'success', 'ok' ) );
	}

	/**
	 * The switch is a real checkbox: its name, value and checked state post unchanged.
	 */
	public function test_toggle_is_a_real_checkbox_with_the_given_name(): void {
		$html = Form_Ui::toggle(
			array(
				'id'          => 'debug_mode',
				'name'        => 'debug_mode',
				'value'       => '1',
				'checked'     => true,
				'label'       => 'Enable debug logging',
				'description' => 'Log requests.',
			)
		);

		$this->assertStringContainsString( '<input type="checkbox" class="smac-toggle-input" id="debug_mode" name="debug_mode" value="1" aria-describedby="debug_mode-desc" checked="checked" />', $html );
		$this->assertStringContainsString( '<label class="smac-toggle">', $html );
		$this->assertStringContainsString( 'data-on="On" data-off="Off"', $html );
		$this->assertStringNotContainsString( 'disabled', $html );

		$array_name = Form_Ui::toggle(
			array(
				'name'  => 'enabled_tool_categories[]',
				'value' => 'all',
				'label' => 'All',
			)
		);
		$this->assertStringContainsString( 'name="enabled_tool_categories[]" value="all"', $array_name );
		$this->assertStringNotContainsString( 'checked', $array_name );
	}

	/**
	 * The save bar's button is a real submit button with the handler's name.
	 */
	public function test_savebar_posts_with_the_handlers_name(): void {
		$html = $this->capture( static fn() => Form_Ui::savebar( 'save_settings', 'Save Settings' ) );

		$this->assertStringContainsString( '<button type="submit" name="save_settings" value="1" class="aui-btn aui-btn--primary" data-aui-save>Save Settings</button>', $html );
		$this->assertStringContainsString( 'data-aui-discard', $html );
	}

	/**
	 * Every field name, submit name and nonce the Settings handlers read is still on the screen.
	 */
	public function test_settings_view_keeps_field_names_and_nonces(): void {
		$src = $this->view( 'settings.php' );

		foreach ( array(
			'name="ai_temperature"',
			'name="ai_max_tokens"',
			"'name'        => 'enabled_tool_categories[]'",
			'name="enabled_tool_categories[]" value="clarity"',
			'name="enabled_tool_categories[]" value="ga4"',
			'name="enabled_tool_categories[]" value="gsc"',
			'name="chat_providers_submitted"',
			"'name'    => 'chat_providers_enabled[]'",
			'name="cache_ttl_clarity"',
			'name="cache_ttl_ga4"',
			'name="cache_ttl_gsc"',
			"'name'        => 'debug_mode'",
			'name="debug_mode"',
			'name="source_capture_submitted"',
			"'name'        => 'source_capture_enabled'",
			'name="allowed_roles[]"',
			"'name'    => 'weekly_summary_enabled'",
			'name="weekly_summary_recipients"',
			'name="google_client_id"',
			'name="google_client_secret"',
			'name="save_google_oauth"',
			'name="remove_google_oauth"',
			"Form_Ui::savebar( 'save_settings'",
			"Form_Ui::savebar( 'save_access_control'",
			"Form_Ui::savebar( 'save_email_settings'",
			"wp_nonce_field( 'specflux_mac_save_settings', 'settings_nonce' )",
			"wp_nonce_field( 'specflux_mac_save_access_control', 'access_control_nonce' )",
			"wp_nonce_field( 'specflux_mac_save_email_settings', 'email_settings_nonce' )",
			"wp_nonce_field( 'specflux_mac_save_google_oauth', 'google_oauth_nonce' )",
			"wp_nonce_field( 'specflux_mac_remove_google_oauth', 'remove_google_oauth_nonce' )",
		) as $needle ) {
			$this->assertStringContainsString( $needle, $src, $needle );
		}
	}

	/**
	 * Settings and Connections still fire the add-on hooks, tabs before content, with the active tab.
	 */
	public function test_extension_hooks_still_fire_in_order(): void {
		$settings = $this->view( 'settings.php' );
		$this->assertStringContainsString( "'specflux_mac_settings_tabs', __(", $settings );
		$this->assertStringContainsString( "do_action( 'specflux_mac_settings_tab_content', \$active_tab )", $settings );
		$this->assertLessThan( strpos( $settings, 'specflux_mac_settings_tab_content' ), strpos( $settings, 'specflux_mac_settings_tabs' ) );

		$connections = $this->view( 'connections.php' );
		$this->assertStringContainsString( "'specflux_mac_connections_tabs', __(", $connections );
		$this->assertStringContainsString( "do_action( 'specflux_mac_connections_tab_content', \$active_tab )", $connections );
		$this->assertStringContainsString( "do_action( 'specflux_mac_render_connection_panel_' . \$active_tab, \$panel_definition )", $connections );
		$this->assertLessThan( strpos( $connections, 'specflux_mac_connections_tab_content' ), strpos( $connections, 'specflux_mac_connections_tabs' ) );
	}

	/**
	 * Connection panels keep their field names, nonces, and use a confirm dialog instead of confirm().
	 */
	public function test_connection_panels_keep_names_and_use_dialogs(): void {
		$clarity = $this->view( 'connections/clarity.php' );
		foreach ( array( 'name="clarity_api_token"', 'name="clarity_project_id"', 'name="save_clarity"', "wp_nonce_field( 'specflux_mac_save_clarity', 'clarity_nonce' )" ) as $needle ) {
			$this->assertStringContainsString( $needle, $clarity, $needle );
		}

		foreach ( array( 'ga4', 'gsc' ) as $slug ) {
			$src = $this->view( "connections/{$slug}.php" );
			$this->assertStringContainsString( "wp_nonce_field( 'specflux_mac_disconnect_oauth', 'disconnect_oauth_nonce' )", $src, $slug );
			$this->assertStringContainsString( 'name="disconnect_oauth"', $src, $slug );
			$this->assertStringContainsString( "name=\"service\" value=\"{$slug}\"", $src, $slug );
			$this->assertStringContainsString( 'data-aui-open="smac-disconnect-' . $slug . '"', $src, $slug );
			$this->assertStringNotContainsString( 'onclick="return confirm', $src, $slug );
		}

		$js = (string) file_get_contents( SPECFLUX_MAC_PATH . 'admin/js/admin-scripts.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
		$this->assertStringNotContainsString( 'confirm(', $js );
	}
}
