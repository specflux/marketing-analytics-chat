<?php
/**
 * Static checks for the AI Assistant chat surface.
 *
 * The chat JS is written against server-rendered markup and a localized string
 * table. These tests keep the three in step without a browser.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Chat surface contract tests.
 */
class ChatSurfaceTest extends TestCase {

	/**
	 * Read a plugin file.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private function read( string $relative ): string {
		$contents = file_get_contents( SPECFLUX_MAC_PATH . $relative );
		$this->assertIsString( $contents, $relative );
		return $contents;
	}

	/**
	 * Every string key the JS asks for must be localized by PHP.
	 */
	public function test_every_js_string_key_is_localized(): void {
		$js    = $this->read( 'admin/js/chat-interface.js' );
		$admin = $this->read( 'includes/admin/class-admin.php' );

		preg_match_all( "/\\bt\\('([A-Za-z]+)'/", $js, $matches );
		$keys = array_unique( $matches[1] );
		$this->assertNotEmpty( $keys );

		foreach ( $keys as $key ) {
			$this->assertMatchesRegularExpression(
				"/'" . preg_quote( $key, '/' ) . "'\\s*=>\\s*__\\(/",
				$admin,
				"Chat JS uses t('$key') but class-admin.php does not localize it."
			);
		}
	}

	/**
	 * The view keeps the ids and classes the chat JS binds to.
	 */
	public function test_view_keeps_js_hooks(): void {
		$view = $this->read( 'admin/views/chat.php' );

		foreach ( array( 'id="new-conversation"', 'id="new-conversation-main"', 'id="chat-messages"', 'id="chat-form"', 'id="message-input"', 'id="send-button"', 'id="suggested-prompts"', 'delete-conversation', 'conversation-item', 'conversation-title', 'smac-action-apply', 'smac-action-dismiss', 'data-action-id', 'data-md=', 'specflux-marketing-analytics-chat' ) as $hook ) {
			$this->assertStringContainsString( $hook, $view, "Chat view lost JS hook: $hook" );
		}
	}

	/**
	 * The chat view is built on the kit, not WordPress default button/notice classes.
	 */
	public function test_view_uses_kit_components(): void {
		$view = $this->read( 'admin/views/chat.php' );

		foreach ( array( 'aui-chat', 'aui-msg--user', 'aui-bubble', 'aui-msg--agent', 'aui-avatar', 'aui-result', 'aui-composer', 'aui-btn' ) as $class ) {
			$this->assertStringContainsString( $class, $view, "Chat view should use .$class" );
		}
		$this->assertStringNotContainsString( 'class="button', $view );
		$this->assertStringNotContainsString( 'dashicons', $view );
	}

	/**
	 * The stylesheet carries no hard-coded colours; all colour comes from kit tokens.
	 */
	public function test_stylesheet_uses_tokens_only(): void {
		$css = $this->read( 'admin/css/chat-interface.css' );

		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $css );
		$this->assertDoesNotMatchRegularExpression( '/\brgba?\(/', $css );
		$this->assertStringContainsString( 'prefers-reduced-motion', $this->read( 'assets/vendor/admin-ui-kit/components.css' ) );
	}
}
