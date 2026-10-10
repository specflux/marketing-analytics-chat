<?php
/**
 * Tests for the MCP Client class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Chat\MCP_Client;
use PHPUnit\Framework\TestCase;

/**
 * MCP Client test class.
 */
class MCPClientTest extends TestCase {

	/**
	 * MCP Client instance.
	 *
	 * @var MCP_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->client = new MCP_Client();
	}

	/**
	 * Test class exists.
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( 'Specflux_Marketing_Analytics\Chat\MCP_Client' ) );
	}

	/**
	 * Building the client and the chat handler must not resolve the current user:
	 * both are constructed at plugins_loaded, before WordPress sets the user up.
	 */
	public function test_construction_does_not_resolve_current_user(): void {
		$GLOBALS['specflux_mac_test_current_user_calls'] = 0;

		new MCP_Client();
		new \Specflux_Marketing_Analytics\Chat\Chat_Ajax_Handler();

		$this->assertSame( 0, $GLOBALS['specflux_mac_test_current_user_calls'] );
	}

	/**
	 * Test format_tool_result with text content.
	 */
	public function test_format_tool_result_with_text_content(): void {
		$result = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'Sessions: 1,234 | Bounce Rate: 45.2%',
				),
			),
		);

		$formatted = $this->client->format_tool_result( 'marketing-analytics/get-ga4-metrics', $result );

		$this->assertStringContainsString( 'Tool: marketing-analytics/get-ga4-metrics', $formatted );
		$this->assertStringContainsString( 'Sessions: 1,234', $formatted );
		$this->assertStringContainsString( 'Bounce Rate: 45.2%', $formatted );
	}

	/**
	 * Test format_tool_result with multiple content items.
	 */
	public function test_format_tool_result_with_multiple_content(): void {
		$result = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'First section',
				),
				array(
					'type' => 'text',
					'text' => 'Second section',
				),
			),
		);

		$formatted = $this->client->format_tool_result( 'test-tool', $result );

		$this->assertStringContainsString( 'First section', $formatted );
		$this->assertStringContainsString( 'Second section', $formatted );
	}

	/**
	 * Test format_tool_result with string result.
	 */
	public function test_format_tool_result_with_string(): void {
		$formatted = $this->client->format_tool_result( 'test-tool', 'Simple string result' );

		$this->assertStringContainsString( 'Tool: test-tool', $formatted );
		$this->assertStringContainsString( 'Simple string result', $formatted );
	}

	/**
	 * Test format_tool_result with array result (no content key).
	 */
	public function test_format_tool_result_with_array(): void {
		$result = array(
			'data' => array(
				'sessions' => 1234,
				'users'    => 567,
			),
		);

		$formatted = $this->client->format_tool_result( 'test-tool', $result );

		$this->assertStringContainsString( 'Tool: test-tool', $formatted );
		// Should JSON encode the result.
		$this->assertStringContainsString( '1234', $formatted );
		$this->assertStringContainsString( '567', $formatted );
	}

	/**
	 * Test format_tool_result with empty content array.
	 */
	public function test_format_tool_result_with_empty_content(): void {
		$result = array(
			'content' => array(),
		);

		$formatted = $this->client->format_tool_result( 'test-tool', $result );

		$this->assertStringContainsString( 'Tool: test-tool', $formatted );
	}

	/**
	 * Test format_tool_result skips non-text content types.
	 */
	public function test_format_tool_result_skips_non_text(): void {
		$result = array(
			'content' => array(
				array(
					'type' => 'image',
					'data' => 'base64data',
				),
				array(
					'type' => 'text',
					'text' => 'Visible text',
				),
			),
		);

		$formatted = $this->client->format_tool_result( 'test-tool', $result );

		$this->assertStringContainsString( 'Visible text', $formatted );
		$this->assertStringNotContainsString( 'base64data', $formatted );
	}

	/**
	 * Test MCP Client has required public methods.
	 */
	public function test_has_required_methods(): void {
		$methods = array( 'list_tools', 'call_tool', 'format_tool_result' );

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( $this->client, $method ),
				"MCP_Client should have '{$method}' method"
			);
		}
	}

	/**
	 * Test format_tool_result includes tool name in output.
	 */
	public function test_format_includes_tool_name(): void {
		$tool_names = array(
			'marketing-analytics/get-clarity-insights',
			'marketing-analytics/get-ga4-metrics',
			'marketing-analytics/get-gsc-performance',
			'marketing-analytics/compare-periods',
		);

		foreach ( $tool_names as $name ) {
			$formatted = $this->client->format_tool_result( $name, 'test result' );
			$this->assertStringContainsString(
				"Tool: {$name}",
				$formatted,
				"Output should include tool name '{$name}'"
			);
		}
	}
}
