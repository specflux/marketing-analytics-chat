<?php
/**
 * Tests for the Connection Promoter class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Admin\Connection_Promoter;
use PHPUnit\Framework\TestCase;

/**
 * Connection Promoter test class.
 */
class ConnectionPromoterTest extends TestCase {

	/**
	 * Connection Promoter instance.
	 *
	 * @var Connection_Promoter
	 */
	private $promoter;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options   = array();
		$this->promoter = new Connection_Promoter();
	}

	/**
	 * Test class exists.
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( 'Specflux_Marketing_Analytics\Admin\Connection_Promoter' ) );
	}

	/**
	 * Test get_connected_platforms returns empty array when nothing is connected.
	 */
	public function test_no_platforms_connected(): void {
		$connected = $this->promoter->get_connected_platforms();

		$this->assertIsArray( $connected );
		$this->assertEmpty( $connected );
	}

	/**
	 * Test get_next_recommendation returns clarity first when nothing connected.
	 */
	public function test_recommends_clarity_first(): void {
		$next = $this->promoter->get_next_recommendation( array() );

		$this->assertNotEmpty( $next );
		$this->assertEquals( 'clarity', $next['key'] );
		$this->assertEquals( 'Microsoft Clarity', $next['name'] );
	}

	/**
	 * Test recommendation after clarity is connected.
	 */
	public function test_recommends_ga4_after_clarity(): void {
		$next = $this->promoter->get_next_recommendation( array( 'clarity' ) );

		$this->assertNotEmpty( $next );
		$this->assertEquals( 'ga4', $next['key'] );
		$this->assertEquals( 'Google Analytics 4', $next['name'] );
	}

	/**
	 * Test recommendation after clarity and ga4 are connected.
	 */
	public function test_recommends_gsc_last(): void {
		$next = $this->promoter->get_next_recommendation( array( 'clarity', 'ga4' ) );

		$this->assertNotEmpty( $next );
		$this->assertEquals( 'gsc', $next['key'] );
		$this->assertEquals( 'Google Search Console', $next['name'] );
	}

	/**
	 * Test no recommendation when all connected.
	 */
	public function test_no_recommendation_when_all_connected(): void {
		$next = $this->promoter->get_next_recommendation( array( 'clarity', 'ga4', 'gsc' ) );

		$this->assertEmpty( $next );
	}

	/**
	 * Test get_connection_prompt with no connections.
	 */
	public function test_connection_prompt_no_connections(): void {
		$prompt = $this->promoter->get_connection_prompt();

		$this->assertIsArray( $prompt );
		$this->assertEquals( 0, $prompt['connected_count'] );
		$this->assertNotEmpty( $prompt['message'] );
		$this->assertNotEmpty( $prompt['next'] );
		$this->assertNotEmpty( $prompt['cta'] );
	}

	/**
	 * Test connection prompt has required keys.
	 */
	public function test_connection_prompt_structure(): void {
		$prompt = $this->promoter->get_connection_prompt();

		$this->assertArrayHasKey( 'connected_count', $prompt );
		$this->assertArrayHasKey( 'message', $prompt );
		$this->assertArrayHasKey( 'next', $prompt );
		$this->assertArrayHasKey( 'cta', $prompt );
	}

	/**
	 * Test platform definitions have required fields.
	 */
	public function test_platform_definitions(): void {
		$reflection = new \ReflectionClass( $this->promoter );
		$constant   = $reflection->getReflectionConstant( 'PLATFORMS' );
		$platforms  = $constant->getValue();

		$this->assertArrayHasKey( 'clarity', $platforms );
		$this->assertArrayHasKey( 'ga4', $platforms );
		$this->assertArrayHasKey( 'gsc', $platforms );

		$required_keys = array( 'name', 'icon', 'benefit', 'tab' );

		foreach ( $platforms as $key => $platform ) {
			foreach ( $required_keys as $field ) {
				$this->assertArrayHasKey(
					$field,
					$platform,
					"Platform '{$key}' should have '{$field}' field"
				);
			}
		}
	}

	/**
	 * Test platform tab values are valid (used in admin URLs).
	 */
	public function test_platform_tab_values(): void {
		$reflection = new \ReflectionClass( $this->promoter );
		$constant   = $reflection->getReflectionConstant( 'PLATFORMS' );
		$platforms  = $constant->getValue();

		$expected_tabs = array(
			'clarity' => 'clarity',
			'ga4'     => 'ga4',
			'gsc'     => 'gsc',
		);

		foreach ( $expected_tabs as $key => $tab ) {
			$this->assertEquals( $tab, $platforms[ $key ]['tab'] );
		}
	}

	/**
	 * Test recommendation skips already-connected platforms.
	 */
	public function test_recommendation_skips_connected(): void {
		// Only ga4 connected - should recommend clarity first (priority order).
		$next = $this->promoter->get_next_recommendation( array( 'ga4' ) );

		$this->assertEquals( 'clarity', $next['key'] );
	}

	/**
	 * Test render_connection_prompt does not throw when no connections.
	 */
	public function test_render_connection_prompt_outputs_html(): void {
		ob_start();
		$this->promoter->render_connection_prompt();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'smac-connection-prompt', $output );
		$this->assertStringContainsString( 'specflux-marketing-analytics-chat-connections', $output );
	}

	/**
	 * Test next recommendation includes benefit text.
	 */
	public function test_recommendation_includes_benefit(): void {
		$next = $this->promoter->get_next_recommendation( array() );

		$this->assertArrayHasKey( 'benefit', $next );
		$this->assertNotEmpty( $next['benefit'] );
	}
}
