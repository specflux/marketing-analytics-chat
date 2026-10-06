<?php
/**
 * Tests for the SEO issue detector.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Content\Seo_Issue_Detector;

/**
 * Seo_Issue_Detector tests.
 */
class SeoIssueDetectorTest extends TestCase {

	/**
	 * Build a row.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $seo     SEO overrides.
	 * @param array $metrics Metrics overrides (null for none).
	 * @param mixed $queries Top queries.
	 * @return array
	 */
	private function row( $post_id, array $seo = array(), $metrics = null, $queries = null ) {
		$seo = array_merge(
			array(
				'post_id'       => $post_id,
				'plugin'        => 'yoast',
				'title'         => 'A good title for post ' . $post_id,
				'description'   => str_repeat( 'd', 120 ),
				'uses_template' => false,
				'focus_keyword' => 'widgets',
				'noindex'       => false,
			),
			$seo
		);

		return array(
			'item'    => array(
				'post_id' => $post_id,
				'url'     => 'https://example.com/p' . $post_id . '/',
				'title'   => 'Post ' . $post_id,
			),
			'seo'     => $seo,
			'metrics' => $metrics,
			'queries' => $queries,
		);
	}

	/**
	 * Find the issue types for a post.
	 *
	 * @param array $result  Detector result.
	 * @param int   $post_id Post ID.
	 * @return string[]
	 */
	private function types( array $result, $post_id ) {
		$types = array();
		foreach ( $result['issues'] as $issue ) {
			if ( $issue['post_id'] === $post_id ) {
				$types[] = $issue['issue'];
			}
		}
		sort( $types );
		return $types;
	}

	/**
	 * A clean page produces no issues.
	 */
	public function test_clean_page_has_no_issues(): void {
		$result = ( new Seo_Issue_Detector() )->detect( array( $this->row( 1 ) ) );
		$this->assertSame( 0, $result['total'] );
	}

	/**
	 * Length and missing-field rules.
	 */
	public function test_length_and_missing_rules(): void {
		$rows = array(
			$this->row( 1, array( 'description' => '' ) ),
			$this->row( 2, array( 'title' => '' ) ),
			$this->row( 3, array( 'title' => str_repeat( 't', 61 ) ) ),
			$this->row( 4, array( 'description' => str_repeat( 'd', 161 ) ) ),
			$this->row( 5, array( 'description' => str_repeat( 'd', 69 ) ) ),
			$this->row( 6, array( 'focus_keyword' => '' ) ),
			$this->row( 7, array( 'title' => str_repeat( 't', 60 ), 'description' => str_repeat( 'd', 160 ) ) ),
		);

		$result = ( new Seo_Issue_Detector() )->detect( $rows );

		$this->assertSame( array( 'missing_meta_description' ), $this->types( $result, 1 ) );
		$this->assertSame( array( 'missing_seo_title' ), $this->types( $result, 2 ) );
		$this->assertSame( array( 'title_too_long' ), $this->types( $result, 3 ) );
		$this->assertSame( array( 'description_too_long' ), $this->types( $result, 4 ) );
		$this->assertSame( array( 'description_too_short' ), $this->types( $result, 5 ) );
		$this->assertSame( array( 'missing_focus_keyword' ), $this->types( $result, 6 ) );
		$this->assertSame( array(), $this->types( $result, 7 ), 'Boundary lengths are allowed.' );
	}

	/**
	 * Without an SEO plugin, empty fields are unknown rather than missing.
	 */
	public function test_no_seo_plugin_skips_field_rules(): void {
		$none = array(
			'plugin'        => '',
			'title'         => '',
			'description'   => '',
			'focus_keyword' => '',
		);
		$rows = array(
			$this->row( 1, $none ),
			$this->row( 2, $none ),
		);

		$result = ( new Seo_Issue_Detector() )->detect( $rows );

		$this->assertSame( 0, $result['total'] );
	}

	/**
	 * Templated values are not length-checked.
	 */
	public function test_templates_skip_length_checks(): void {
		$long   = '%%title%% ' . str_repeat( 'x', 100 );
		$result = ( new Seo_Issue_Detector() )->detect( array( $this->row( 1, array( 'title' => $long, 'description' => $long, 'uses_template' => true ) ) ) );
		$this->assertSame( 0, $result['total'] );
	}

	/**
	 * Duplicate titles are matched case-insensitively and name the other posts.
	 */
	public function test_duplicate_titles(): void {
		$rows = array(
			$this->row( 1, array( 'title' => 'Same Title' ) ),
			$this->row( 2, array( 'title' => 'same title' ) ),
			$this->row( 3, array( 'title' => 'Unique' ) ),
		);

		$result = ( new Seo_Issue_Detector() )->detect( $rows );

		$this->assertSame( array( 'duplicate_seo_title' ), $this->types( $result, 1 ) );
		$this->assertSame( array( 'duplicate_seo_title' ), $this->types( $result, 2 ) );
		$this->assertSame( array(), $this->types( $result, 3 ) );
		$issue = $result['issues'][0];
		$this->assertSame( 2, $issue['evidence']['duplicate_count'] );
		$this->assertCount( 1, $issue['evidence']['also_used_by'] );
	}

	/**
	 * Noindex matters only when the page has traffic.
	 */
	public function test_noindex_with_traffic(): void {
		$traffic = array(
			'views'       => 10,
			'clicks'      => 0,
			'impressions' => 0,
			'position'    => null,
		);
		$none    = array(
			'views'       => 0,
			'clicks'      => 0,
			'impressions' => 0,
			'position'    => null,
		);
		$rows    = array(
			$this->row( 1, array( 'noindex' => true ), $traffic ),
			$this->row( 2, array( 'noindex' => true ), $none ),
			$this->row( 3, array( 'noindex' => null ), $traffic ),
		);

		$result = ( new Seo_Issue_Detector() )->detect( $rows );

		$this->assertSame( array( 'noindex_with_traffic' ), $this->types( $result, 1 ) );
		$this->assertSame( 'high', $result['issues'][0]['severity'] );
		$this->assertSame( array(), $this->types( $result, 2 ) );
		$this->assertSame( array(), $this->types( $result, 3 ) );
	}

	/**
	 * Focus keyword vs queries runs only when query data exists.
	 */
	public function test_focus_keyword_vs_top_queries(): void {
		$rows = array(
			$this->row( 1, array(), null, array( 'blue gadgets', 'gadget reviews' ) ),
			$this->row( 2, array(), null, array( 'best widgets 2026' ) ),
			$this->row( 3, array(), null, null ),
		);

		$result = ( new Seo_Issue_Detector() )->detect( $rows );

		$this->assertSame( array( 'focus_keyword_not_in_top_queries' ), $this->types( $result, 1 ) );
		$this->assertSame( array(), $this->types( $result, 2 ) );
		$this->assertSame( array(), $this->types( $result, 3 ), 'No GSC query data means the check is skipped.' );
		$this->assertSame( 'widgets', $result['issues'][0]['evidence']['focus_keyword'] );
	}

	/**
	 * Low CTR needs 100+ impressions and a CTR below half the site average.
	 */
	public function test_high_impressions_low_ctr(): void {
		$m    = static function ( $clicks, $impressions ) {
			return array(
				'views'       => 0,
				'clicks'      => $clicks,
				'impressions' => $impressions,
				'position'    => 8.2,
			);
		};
		$rows = array(
			$this->row( 1, array( 'title' => 'Current title one' ), $m( 1, 2000 ) ), // 0.05%: low.
			$this->row( 2, array(), $m( 200, 2000 ) ), // 10%: fine.
			$this->row( 3, array(), $m( 0, 50 ) ), // Too few impressions.
		);

		$result = ( new Seo_Issue_Detector() )->detect( $rows );

		$this->assertSame( array( 'high_impressions_low_ctr' ), $this->types( $result, 1 ) );
		$this->assertSame( array(), $this->types( $result, 2 ) );
		$this->assertSame( array(), $this->types( $result, 3 ) );
		$issue = $result['issues'][0];
		$this->assertSame( 'high', $issue['severity'] );
		$this->assertSame( 'Current title one', $issue['evidence']['current_seo_title'] );
		$this->assertArrayHasKey( 'current_description', $issue['evidence'] );
		$this->assertArrayHasKey( 'site_average_ctr', $issue['evidence'] );
	}

	/**
	 * Issues are ranked by severity then impressions, capped, and counted in full.
	 */
	public function test_ranking_cap_and_counts(): void {
		$rows = array(
			$this->row( 1, array( 'description' => '' ), array( 'views' => 0, 'clicks' => 50, 'impressions' => 500, 'position' => 3.0 ) ), // High (popular).
			$this->row( 2, array( 'description' => '' ) ), // Medium.
			$this->row( 3, array( 'title' => '' ) ), // Low.
			$this->row( 4, array( 'title' => '' ) ), // Low.
		);

		$result = ( new Seo_Issue_Detector() )->detect( $rows, 2 );

		$this->assertSame( 4, $result['total'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertCount( 2, $result['issues'] );
		$this->assertSame( 1, $result['issues'][0]['post_id'] );
		$this->assertSame( 'high', $result['issues'][0]['severity'] );
		$this->assertSame( 2, $result['issues'][1]['post_id'] );
		$this->assertSame( 2, $result['counts']['missing_seo_title'] );
		$this->assertArrayNotHasKey( 'impressions', $result['issues'][0] );
	}
}
