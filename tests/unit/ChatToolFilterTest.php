<?php
/**
 * Tests for Chat_Ajax_Handler::filter_tools() tool-category filtering.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Chat\Chat_Ajax_Handler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tool-category filter test class.
 */
class ChatToolFilterTest extends TestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array();
	}

	/**
	 * Invoke the private filter_tools() without running the heavy constructor.
	 *
	 * @param array $tools Tools to filter.
	 * @return array Filtered tools.
	 */
	private function filter( $tools ) {
		$reflection = new ReflectionClass( Chat_Ajax_Handler::class );
		$handler    = $reflection->newInstanceWithoutConstructor();
		$method     = $reflection->getMethod( 'filter_tools' );
		$method->setAccessible( true );

		return $method->invoke( $handler, $tools );
	}

	/**
	 * Sample tool set spanning every category.
	 *
	 * @return array
	 */
	private function sample_tools() {
		return array(
			array( 'name' => 'marketing-analytics/get-ga4-metrics' ),
			array( 'name' => 'marketing-analytics/get-search-performance' ), // GSC, no "gsc" token.
			array( 'name' => 'marketing-analytics/get-clarity-insights' ),
			array( 'name' => 'marketing-analytics/compare-periods' ),        // cross-platform.
			array( 'name' => 'marketing-analytics/premium-only-ability' ),   // unmapped (e.g. pro add-on).
		);
	}

	/**
	 * Helper to pull names out of a filtered set.
	 *
	 * @param array $tools Filtered tools.
	 * @return array
	 */
	private function names( $tools ) {
		return array_map(
			static function ( $tool ) {
				return $tool['name'];
			},
			$tools
		);
	}

	/**
	 * "all" returns every tool unchanged.
	 */
	public function test_all_category_returns_everything(): void {
		global $mock_options;
		$mock_options['specflux_mac_settings'] = array( 'enabled_tool_categories' => array( 'all' ) );

		$result = $this->filter( $this->sample_tools() );

		$this->assertCount( 5, $result );
	}

	/**
	 * Selecting only GA4 keeps GA4 + cross-platform + unmapped, drops GSC/Clarity.
	 */
	public function test_ga4_selection_filters_other_platforms(): void {
		global $mock_options;
		$mock_options['specflux_mac_settings'] = array( 'enabled_tool_categories' => array( 'ga4' ) );

		$names = $this->names( $this->filter( $this->sample_tools() ) );

		$this->assertContains( 'marketing-analytics/get-ga4-metrics', $names );
		$this->assertContains( 'marketing-analytics/compare-periods', $names, 'cross-platform is always available' );
		$this->assertContains( 'marketing-analytics/premium-only-ability', $names, 'unmapped tools pass through' );

		$this->assertNotContains( 'marketing-analytics/get-search-performance', $names );
		$this->assertNotContains( 'marketing-analytics/get-clarity-insights', $names );
	}

	/**
	 * A GSC ability whose short name lacks "gsc" is still matched to the GSC category.
	 */
	public function test_gsc_selection_matches_non_prefixed_ability(): void {
		global $mock_options;
		$mock_options['specflux_mac_settings'] = array( 'enabled_tool_categories' => array( 'gsc' ) );

		$names = $this->names( $this->filter( $this->sample_tools() ) );

		$this->assertContains( 'marketing-analytics/get-search-performance', $names );
		$this->assertNotContains( 'marketing-analytics/get-ga4-metrics', $names );
	}
}
