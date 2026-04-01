<?php
/**
 * Tests for the Cross_Platform_Abilities class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Abilities\Cross_Platform_Abilities;
use PHPUnit\Framework\TestCase;

/**
 * Cross-Platform Abilities test class.
 */
class CrossPlatformAbilitiesTest extends TestCase {

	/**
	 * Cross Platform Abilities instance.
	 *
	 * @var Cross_Platform_Abilities
	 */
	private $abilities;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array();

		$this->abilities = new Cross_Platform_Abilities();
	}

	/**
	 * Test register exits early when no credentials configured.
	 */
	public function test_register_exits_when_no_credentials(): void {
		global $mock_options;
		$mock_options = array();

		$abilities = new Cross_Platform_Abilities();
		// Should not throw - just returns early.
		$abilities->register();
		$this->assertTrue( true );
	}

	/**
	 * Test get_available_platforms returns correct platforms.
	 */
	public function test_get_available_platforms_returns_empty_without_credentials(): void {
		$platforms = $this->abilities->get_available_platforms();
		$this->assertIsArray( $platforms );
		$this->assertEmpty( $platforms );
	}

	/**
	 * Test percentage change calculation with normal values.
	 */
	public function test_percentage_change_normal(): void {
		$result = $this->abilities->calculate_percentage_change( 100, 150 );
		$this->assertEquals( 50.0, $result );
	}

	/**
	 * Test percentage change calculation with decrease.
	 */
	public function test_percentage_change_decrease(): void {
		$result = $this->abilities->calculate_percentage_change( 200, 100 );
		$this->assertEquals( -50.0, $result );
	}

	/**
	 * Test percentage change calculation with zero base.
	 */
	public function test_percentage_change_zero_base(): void {
		$result = $this->abilities->calculate_percentage_change( 0, 100 );
		$this->assertEquals( 100, $result );
	}

	/**
	 * Test percentage change calculation with both zero.
	 */
	public function test_percentage_change_both_zero(): void {
		$result = $this->abilities->calculate_percentage_change( 0, 0 );
		$this->assertEquals( 0, $result );
	}

	/**
	 * Test percentage change calculation with no change.
	 */
	public function test_percentage_change_no_change(): void {
		$result = $this->abilities->calculate_percentage_change( 100, 100 );
		$this->assertEquals( 0.0, $result );
	}

	/**
	 * Test URL merging logic for top-content with GA4 data only.
	 */
	public function test_merge_content_data_ga4_only(): void {
		$ga4_data = array(
			'rows' => array(
				array(
					'pagePath'          => '/blog/post-1',
					'pageTitle'         => 'Post 1',
					'screenPageViews'   => '150',
					'bounceRate'        => '0.45',
					'averageSessionDuration' => '120',
				),
				array(
					'pagePath'          => '/blog/post-2',
					'pageTitle'         => 'Post 2',
					'screenPageViews'   => '80',
					'bounceRate'        => '0.55',
					'averageSessionDuration' => '90',
				),
			),
		);

		$result = $this->abilities->merge_content_data( $ga4_data, array() );

		$this->assertCount( 2, $result );
		$this->assertEquals( '/blog/post-1', $result[0]['path'] );
		$this->assertEquals( 'Post 1', $result[0]['title'] );
		$this->assertEquals( '150', $result[0]['screenPageViews'] );
	}

	/**
	 * Test URL merging logic for top-content with both GA4 and GSC data.
	 */
	public function test_merge_content_data_both_platforms(): void {
		$ga4_data = array(
			'rows' => array(
				array(
					'pagePath'          => '/blog/post-1',
					'pageTitle'         => 'Post 1',
					'screenPageViews'   => '150',
					'bounceRate'        => '0.45',
					'averageSessionDuration' => '120',
				),
			),
		);

		$gsc_data = array(
			'rows' => array(
				array(
					'key'         => '/blog/post-1',
					'clicks'      => 50,
					'impressions' => 500,
					'ctr'         => 0.1,
					'position'    => 5.2,
				),
				array(
					'key'         => '/about',
					'clicks'      => 20,
					'impressions' => 200,
					'ctr'         => 0.1,
					'position'    => 8.5,
				),
			),
		);

		$result = $this->abilities->merge_content_data( $ga4_data, $gsc_data );

		$this->assertCount( 2, $result );

		// Find the merged post-1 entry.
		$post1 = null;
		foreach ( $result as $item ) {
			if ( '/blog/post-1' === $item['path'] ) {
				$post1 = $item;
				break;
			}
		}

		$this->assertNotNull( $post1 );
		$this->assertEquals( '150', $post1['screenPageViews'] );
		$this->assertEquals( 50, $post1['clicks'] );
		$this->assertEquals( 500, $post1['impressions'] );
	}

	/**
	 * Test markdown report generation format.
	 */
	public function test_markdown_report_format(): void {
		$report = array(
			'date_range'    => '7daysAgo',
			'generated_at'  => '2026-03-05 10:00:00',
			'platforms'     => array( 'ga4' ),
			'platform_data' => array(
				'ga4' => array(
					'totals' => array(
						'sessions'    => '1500',
						'activeUsers' => '1200',
					),
					'rows'   => array(),
				),
			),
			'key_takeaways' => array(
				'Total sessions: 1,500',
				'Active users: 1,200',
			),
		);

		$markdown = $this->abilities->format_report_markdown( $report );

		$this->assertStringContainsString( '# Marketing Analytics Summary Report', $markdown );
		$this->assertStringContainsString( '## Google Analytics 4', $markdown );
		$this->assertStringContainsString( '## Key Takeaways', $markdown );
		$this->assertStringContainsString( '| sessions |', $markdown );
	}
}
