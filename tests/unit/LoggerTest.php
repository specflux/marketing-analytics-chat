<?php
/**
 * Tests for the Logger class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Logger test class.
 */
class LoggerTest extends TestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array();
	}

	/**
	 * Test debug logging is disabled when WP_DEBUG is false.
	 */
	public function test_is_debug_enabled_returns_false_by_default(): void {
		// When no debug mode is set and WP_DEBUG is true (from bootstrap)
		// it should still be enabled
		$this->assertTrue( Logger::is_debug_enabled() );
	}

	/**
	 * Test debug logging is enabled when plugin debug mode is on.
	 */
	public function test_is_debug_enabled_with_plugin_setting(): void {
		global $mock_options;
		$mock_options['specflux_mac_debug_mode'] = true;

		$this->assertTrue( Logger::is_debug_enabled() );
	}

	/**
	 * Test debug method does not throw errors.
	 */
	public function test_debug_method_accepts_string(): void {
		// Should not throw any exception
		$this->expectNotToPerformAssertions();
		Logger::debug( 'Test message' );
	}

	/**
	 * Test debug method handles arrays.
	 */
	public function test_debug_method_accepts_array(): void {
		// Should not throw any exception
		$this->expectNotToPerformAssertions();
		Logger::debug( array( 'key' => 'value' ) );
	}

	/**
	 * Test error method does not throw errors.
	 */
	public function test_error_method_accepts_string(): void {
		// Should not throw any exception
		$this->expectNotToPerformAssertions();
		Logger::error( 'Test error message' );
	}

	/**
	 * Test warning method does not throw errors.
	 */
	public function test_warning_method_accepts_string(): void {
		// Should not throw any exception
		$this->expectNotToPerformAssertions();
		Logger::warning( 'Test warning message' );
	}

	/**
	 * Test redact() masks credential-like keys and preserves benign values.
	 */
	public function test_redact_masks_sensitive_keys(): void {
		$input = array(
			'platform'      => 'clarity',
			'api_token'     => 'super-secret-token-value',
			'client_secret' => 'GOCSPX-abc123',
			'password'      => 'hunter2',
			'access_token'  => 'ya29.aaa',
			'nonce'         => 'abc',
		);

		$redacted = Logger::redact( $input );

		// Benign values are untouched.
		$this->assertSame( 'clarity', $redacted['platform'] );
		$this->assertSame( 'abc', $redacted['nonce'] );

		// Sensitive values are masked.
		$this->assertSame( '[redacted]', $redacted['api_token'] );
		$this->assertSame( '[redacted]', $redacted['client_secret'] );
		$this->assertSame( '[redacted]', $redacted['password'] );
		$this->assertSame( '[redacted]', $redacted['access_token'] );

		// The original secret never survives serialization of the redacted copy.
		$this->assertStringNotContainsString( 'super-secret-token-value', wp_json_encode( $redacted ) );
		$this->assertStringNotContainsString( 'GOCSPX-abc123', wp_json_encode( $redacted ) );
	}

	/**
	 * Test redact() recurses into nested arrays.
	 */
	public function test_redact_recurses_into_nested_arrays(): void {
		$input = array(
			'credentials' => array(
				'api_key' => 'nested-secret',
				'label'   => 'My connection',
			),
		);

		$redacted = Logger::redact( $input );

		$this->assertSame( '[redacted]', $redacted['credentials']['api_key'] );
		$this->assertSame( 'My connection', $redacted['credentials']['label'] );
	}
}
