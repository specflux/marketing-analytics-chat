<?php
/**
 * Tests for the UI kit helper and the shared app header.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Admin\Admin;
use Specflux_Marketing_Analytics\Admin\Ui_Kit;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

/**
 * Ui_Kit test class.
 */
class UiKitTest extends TestCase {

	/**
	 * Reset mocks.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options                     = array();
		$GLOBALS['mock_enqueued_styles']  = array();
		$GLOBALS['mock_enqueued_scripts'] = array();
		$GLOBALS['mock_denied_caps']      = array();
	}

	/**
	 * Restore mocks.
	 */
	protected function tearDown(): void {
		$GLOBALS['mock_denied_caps'] = array();
		parent::tearDown();
	}

	/**
	 * Render the header for a page and return the markup.
	 *
	 * @param string $current Page slug.
	 * @return string
	 */
	private function header( string $current ): string {
		ob_start();
		Ui_Kit::render_header( $current );
		return (string) ob_get_clean();
	}

	/**
	 * Six links, aria-current only on the current one.
	 */
	public function test_header_has_six_links_with_aria_current_on_the_right_one(): void {
		foreach ( array_keys( Ui_Kit::pages() ) as $slug ) {
			$html = $this->header( $slug );

			$this->assertSame( 6, preg_match_all( '/<a href="[^"]*page=specflux-mac[^"]*"/', $html ), $slug );
			$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ), $slug );
			$this->assertMatchesRegularExpression( '/<a href="[^"]*page=' . preg_quote( $slug, '/' ) . '" aria-current="page">/', $html, $slug );
		}
	}

	/**
	 * Labels match the spec and follow the nav order.
	 */
	public function test_header_labels(): void {
		$html = $this->header( 'specflux-mac' );

		preg_match_all( '#<a href="[^"]*"[^>]*>([^<]+)</a>#', $html, $m );
		$this->assertSame( array( 'Dashboard', 'AI Assistant', 'Connections', 'Prompts', 'Abilities', 'Settings' ), $m[1] );
		$this->assertStringContainsString( '<h1>Marketing Analytics Chat</h1>', $html );
		$this->assertStringContainsString( 'v' . SPECFLUX_MAC_VERSION, $html );
		$this->assertStringContainsString( '<hr class="wp-header-end">', $html );
		$this->assertStringContainsString( '#aui-i-chart', $html );
	}

	/**
	 * Settings is not linked for users who cannot open it.
	 */
	public function test_settings_link_hidden_without_manage_options(): void {
		$GLOBALS['mock_denied_caps'] = array( 'manage_options' );

		$html = $this->header( 'specflux-mac' );

		$this->assertSame( 5, preg_match_all( '/<a href=/', $html ) );
		$this->assertStringNotContainsString( 'page=specflux-mac-settings', $html );
	}

	/**
	 * Nothing connected: neutral pill.
	 */
	public function test_pill_is_neutral_when_nothing_is_connected(): void {
		$html = $this->header( 'specflux-mac' );

		$this->assertStringContainsString( '0 of 3 connected', $html );
		$this->assertStringContainsString( '<span class="aui-pill">', $html );
		$this->assertStringNotContainsString( 'aui-pill--ok', $html );
	}

	/**
	 * Some connected: counted from the real credential state, still neutral.
	 */
	public function test_pill_counts_real_connections(): void {
		$manager = new Credential_Manager();
		$manager->save_credentials(
			'clarity',
			array(
				'api_token'  => 'x',
				'project_id' => 'y',
			)
		);

		$html = $this->header( 'specflux-mac-connections' );

		$this->assertStringContainsString( '1 of 3 connected', $html );
		$this->assertStringNotContainsString( 'aui-pill--ok', $html );
	}

	/**
	 * All connected: ok tone.
	 */
	public function test_pill_is_ok_when_everything_is_connected(): void {
		$manager = new Credential_Manager();
		$manager->save_credentials(
			'clarity',
			array(
				'api_token'  => 'x',
				'project_id' => 'y',
			)
		);
		$manager->save_credentials(
			'ga4',
			array(
				'client_id'     => 'x',
				'client_secret' => 'y',
				'refresh_token' => 'z',
			)
		);
		$manager->save_credentials(
			'gsc',
			array(
				'client_id'     => 'x',
				'client_secret' => 'y',
				'refresh_token' => 'z',
			)
		);

		$html = $this->header( 'specflux-mac' );

		$this->assertStringContainsString( '3 of 3 connected', $html );
		$this->assertStringContainsString( 'aui-pill aui-pill--ok', $html );
	}

	/**
	 * Hook classification.
	 */
	public function test_is_plugin_screen(): void {
		$this->assertTrue( Ui_Kit::is_plugin_screen( 'toplevel_page_specflux-mac' ) );
		$this->assertTrue( Ui_Kit::is_plugin_screen( 'marketing-analytics_page_specflux-mac-settings' ) );
		$this->assertFalse( Ui_Kit::is_plugin_screen( 'index.php' ) );
		$this->assertFalse( Ui_Kit::is_plugin_screen( 'edit.php' ) );
		$this->assertFalse( Ui_Kit::is_plugin_screen( '' ) );
	}

	/**
	 * The kit is enqueued on a MAC screen, with plugin-prefixed handles.
	 */
	public function test_kit_enqueued_on_a_mac_screen(): void {
		( new Admin() )->enqueue_styles( 'toplevel_page_specflux-mac' );

		$this->assertArrayHasKey( 'specflux-mac-ui-tokens', $GLOBALS['mock_enqueued_styles'] );
		$this->assertArrayHasKey( 'specflux-mac-ui-kit', $GLOBALS['mock_enqueued_styles'] );
		$this->assertSame( array( 'specflux-mac-ui-kit' ), $GLOBALS['mock_enqueued_styles']['specflux-mac-admin'] );
	}

	/**
	 * The WordPress Dashboard and other screens do not get the kit.
	 */
	public function test_kit_not_enqueued_on_the_wordpress_dashboard(): void {
		( new Admin() )->enqueue_styles( 'index.php' );

		$this->assertArrayNotHasKey( 'specflux-mac-ui-tokens', $GLOBALS['mock_enqueued_styles'] );
		$this->assertArrayNotHasKey( 'specflux-mac-ui-kit', $GLOBALS['mock_enqueued_styles'] );
		$this->assertArrayNotHasKey( 'specflux-mac-admin', $GLOBALS['mock_enqueued_styles'] );
	}
}
