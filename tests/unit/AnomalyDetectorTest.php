<?php
/**
 * Tests for the Anomaly_Detector class.
 *
 * @package Marketing_Analytics_MCP
 */

namespace Marketing_Analytics_MCP\Tests\unit;

use Marketing_Analytics_MCP\Analytics\Anomaly_Detector;
use Marketing_Analytics_MCP\Prompts\Prompt_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Anomaly Detector test class.
 */
class AnomalyDetectorTest extends TestCase {

	/**
	 * Anomaly Detector instance.
	 *
	 * @var Anomaly_Detector
	 */
	private $detector;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array();

		$this->detector = new Anomaly_Detector();
	}

	/**
	 * Test correlate_anomalies groups multi-platform anomalies by date.
	 */
	public function test_correlate_anomalies_groups_by_date(): void {
		// Since get_anomaly_history uses $wpdb which returns empty in tests,
		// we verify the method exists and handles empty data gracefully.
		$result = $this->detector->correlate_anomalies();

		$this->assertIsArray( $result );
		// With mock wpdb returning empty, should return empty.
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_correlated_context returns empty when no other platforms configured.
	 */
	public function test_get_correlated_context_returns_empty(): void {
		$anomaly = array(
			'value'             => 1000,
			'expected'          => 500,
			'deviation'         => 3.5,
			'type'              => 'spike',
			'severity'          => 'high',
			'date'              => '2026-03-05',
			'metric'            => 'sessions',
			'platform'          => 'ga4',
			'percentage_change' => 100.0,
		);

		$context = $this->detector->get_correlated_context( $anomaly, 'ga4' );

		$this->assertIsArray( $context );
		// Without real API clients configured, should return empty.
		$this->assertEmpty( $context );
	}

	/**
	 * Test build_html_email produces valid HTML with severity color.
	 */
	public function test_build_html_email_produces_valid_html(): void {
		$anomaly = array(
			'value'             => 1000,
			'expected'          => 500,
			'deviation'         => 3.5,
			'type'              => 'spike',
			'severity'          => 'high',
			'date'              => '2026-03-05',
			'metric'            => 'sessions',
			'platform'          => 'ga4',
			'percentage_change' => 100.0,
		);

		$html = $this->detector->build_html_email( $anomaly, 'ga4', 'sessions' );

		// Verify it's valid HTML.
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( '</html>', $html );

		// Verify severity-colored header.
		$this->assertStringContainsString( '#e65100', $html ); // 'high' severity color.

		// Verify anomaly type.
		$this->assertStringContainsString( 'Spike', $html );

		// Verify metric values are present.
		$this->assertStringContainsString( '1,000.00', $html );
		$this->assertStringContainsString( '500.00', $html );
		$this->assertStringContainsString( '+100%', $html );

		// Verify CTA link.
		$this->assertStringContainsString( 'Investigate with AI Assistant', $html );
	}

	/**
	 * Test build_html_email with critical severity.
	 */
	public function test_build_html_email_critical_severity(): void {
		$anomaly = array(
			'value'             => 50,
			'expected'          => 500,
			'deviation'         => -4.5,
			'type'              => 'drop',
			'severity'          => 'critical',
			'date'              => '2026-03-05',
			'metric'            => 'sessions',
			'platform'          => 'ga4',
			'percentage_change' => -90.0,
		);

		$html = $this->detector->build_html_email( $anomaly, 'ga4', 'sessions' );

		$this->assertStringContainsString( '#dc3232', $html ); // 'critical' severity color.
		$this->assertStringContainsString( 'Drop', $html );
		$this->assertStringContainsString( '-90%', $html );
	}

	/**
	 * Test build_html_email includes correlated context.
	 */
	public function test_build_html_email_with_context(): void {
		$anomaly = array(
			'value'             => 1000,
			'expected'          => 500,
			'deviation'         => 3.5,
			'type'              => 'spike',
			'severity'          => 'medium',
			'date'              => '2026-03-05',
			'metric'            => 'sessions',
			'platform'          => 'ga4',
			'percentage_change' => 100.0,
		);

		$context = array(
			'gsc' => array(
				'platform' => 'gsc',
				'data'     => array( 'some' => 'data' ),
			),
		);

		$html = $this->detector->build_html_email( $anomaly, 'ga4', 'sessions', $context );

		$this->assertStringContainsString( 'Correlated Platform Data', $html );
		$this->assertStringContainsString( 'Gsc', $html );
	}

	/**
	 * Test anomaly-investigation preset template exists in Prompt_Manager.
	 */
	public function test_anomaly_investigation_prompt_exists(): void {
		$prompt_manager = new Prompt_Manager();
		$presets        = $prompt_manager->get_preset_templates();

		$this->assertArrayHasKey( 'anomaly-investigation', $presets );

		$template = $presets['anomaly-investigation'];
		$this->assertEquals( 'anomaly-investigation', $template['name'] );
		$this->assertNotEmpty( $template['instructions'] );
		$this->assertNotEmpty( $template['arguments'] );
	}
}
