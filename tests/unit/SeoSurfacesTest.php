<?php
/**
 * Tests for the SEO issue summary, the dashboard widget SEO row and the weekly email Bing and SEO sections.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Admin\Widget_Headline;
use Specflux_Marketing_Analytics\Content\Seo_Issue_Summary;
use Specflux_Marketing_Analytics\Credentials\Connection_Tester;
use Specflux_Marketing_Analytics\Reports\Weekly_Prompt_Builder;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary_Email;

/**
 * SEO surfaces tests.
 */
class SeoSurfacesTest extends TestCase {

	/**
	 * Reset stored state.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_transients, $mock_options, $mock_posts;
		$mock_transients = array();
		$mock_options    = array();
		$mock_posts      = array(
			1 => array( 'title' => 'Pricing page' ),
			2 => array( 'title' => 'About "us"?' ),
			3 => array( 'title' => 'Blog' ),
			4 => array( 'title' => 'Contact' ),
		);
		unset( $GLOBALS['specflux_mac_test_filters'] );
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['specflux_mac_test_filters'] );
		parent::tearDown();
	}

	/**
	 * A find-seo-issues style scan result.
	 *
	 * @param int         $total  Total issues.
	 * @param string|null $plugin SEO plugin label.
	 * @param int         $pages  Pages scanned.
	 * @return array
	 */
	private function scan( $total = 5, $plugin = 'Yoast SEO', $pages = 40 ) {
		$issues = array();
		$types  = array( 'missing_meta_description', 'high_impressions_low_ctr', 'duplicate_seo_title', 'missing_focus_keyword' );
		for ( $i = 1; $i <= min( $total, 4 ); $i++ ) {
			$issues[] = array(
				'post_id'  => $i,
				'url'      => 'https://example.com/p' . $i . '/',
				'issue'    => $types[ $i - 1 ],
				'severity' => 1 === $i ? 'high' : 'medium',
			);
		}

		return array(
			'seo_plugin'     => $plugin,
			'pages_scanned'  => $pages,
			'total_issues'   => $total,
			'counts_by_type' => array(
				'missing_meta_description' => 3,
				'high_impressions_low_ctr' => 2,
			),
			'issues'         => $issues,
		);
	}

	/**
	 * Summary service whose scans are counted.
	 *
	 * @param array $scan  Scan result.
	 * @param int   $calls Counter (by reference).
	 * @return Seo_Issue_Summary
	 */
	private function service( array $scan, &$calls ) {
		return new Seo_Issue_Summary(
			static function () use ( $scan, &$calls ) {
				++$calls;
				return $scan;
			}
		);
	}

	/**
	 * The digest keeps the total, counts, three top issues and the plugin.
	 */
	public function test_summary_shape(): void {
		$summary = Seo_Issue_Summary::build( $this->scan( 5 ) );

		$this->assertSame( 5, $summary['total'] );
		$this->assertSame( 3, $summary['counts']['missing_meta_description'] );
		$this->assertCount( 3, $summary['top'] );
		$this->assertSame( 'Pricing page', $summary['top'][0]['title'] );
		$this->assertSame( 'Missing meta description', $summary['top'][0]['label'] );
		$this->assertSame( 'high', $summary['top'][0]['severity'] );
		$this->assertSame( 'https://example.com/p1/', $summary['top'][0]['url'] );
		$this->assertSame( 'Yoast SEO', $summary['seo_plugin'] );
		$this->assertSame( 40, $summary['pages_scanned'] );
		$this->assertGreaterThan( 0, $summary['generated_at'] );
		$this->assertNull( Seo_Issue_Summary::build( $this->scan( 1, null ) )['seo_plugin'] );
	}

	/**
	 * A cold cache is only read, never scanned; refresh stores for 12 hours and is then reused.
	 */
	public function test_cache_is_cold_until_refresh_and_reused_after(): void {
		$calls   = 0;
		$service = $this->service( $this->scan(), $calls );

		$this->assertNull( Seo_Issue_Summary::cached() );
		$this->assertSame( 0, $calls, 'Reading the cache never scans' );
		$this->assertSame( 12 * HOUR_IN_SECONDS, Seo_Issue_Summary::ttl() );

		$service->get_or_refresh();
		$service->get_or_refresh();

		$this->assertSame( 1, $calls, 'The second call is served from the transient' );
		$this->assertSame( 5, Seo_Issue_Summary::cached()['total'] );
	}

	/**
	 * A failing scan stores an empty summary instead of retrying on every call.
	 */
	public function test_failed_scan_is_cached_empty(): void {
		$calls   = 0;
		$service = new Seo_Issue_Summary(
			static function () use ( &$calls ) {
				++$calls;
				throw new \Exception( 'boom' );
			}
		);

		$summary = $service->get_or_refresh();
		$service->get_or_refresh();

		$this->assertSame( 0, $summary['pages_scanned'] );
		$this->assertSame( 1, $calls );
		$this->assertSame( '', Widget_Headline::render_seo( Seo_Issue_Summary::cached() ) );
	}

	/**
	 * The widget AJAX refresh carries the SEO row, and a failing summary never fails it.
	 */
	public function test_widget_refresh_includes_seo_row_and_fails_soft(): void {
		$calls = 0;
		$data  = Widget_Headline::refresh( new Weekly_Summary( array(), array() ), $this->service( $this->scan(), $calls ) );

		$this->assertStringContainsString( '5 issues to fix', $data['seo_html'] );
		$this->assertSame( 1, $calls );

		$throwing = new class() extends Seo_Issue_Summary {
			/**
			 * Always fails.
			 *
			 * @throws \Exception Always.
			 */
			public function get_or_refresh() {
				throw new \Exception( 'boom' );
			}
		};
		$data     = Widget_Headline::refresh( new Weekly_Summary( array(), array() ), $throwing );

		$this->assertSame( '', $data['seo_html'] );
		$this->assertArrayHasKey( 'headline_html', $data );
	}

	/**
	 * Row with issues names the top issue and links to the AI Assistant with an encoded prompt.
	 */
	public function test_widget_row_with_issues(): void {
		$html = Widget_Headline::render_seo( Seo_Issue_Summary::build( $this->scan( 5 ) ) );

		$this->assertStringContainsString( 'SEO: 5 issues to fix', $html );
		$this->assertStringContainsString( 'Top issue: Missing meta description on', $html );
		$this->assertStringContainsString( 'Pricing page', $html );
		$this->assertStringContainsString( 'Ask AI what to fix first', $html );
		$this->assertStringContainsString( 'page=specflux-mac-ai-assistant', $html );
		$this->assertStringContainsString( 'prompt=Find%20my%20SEO%20issues', $html );
		$this->assertStringNotContainsString( 'Install Yoast', $html, 'No note when a plugin is active' );
	}

	/**
	 * Singular wording for one issue.
	 */
	public function test_widget_row_singular(): void {
		$this->assertStringContainsString( 'SEO: 1 issue to fix', Widget_Headline::render_seo( Seo_Issue_Summary::build( $this->scan( 1 ) ) ) );
	}

	/**
	 * Row with no issues has no button.
	 */
	public function test_widget_row_without_issues(): void {
		$html = Widget_Headline::render_seo( Seo_Issue_Summary::build( $this->scan( 0 ) ) );

		$this->assertStringContainsString( 'SEO: no issues found', $html );
		$this->assertStringNotContainsString( 'Ask AI', $html );
	}

	/**
	 * Without an SEO plugin the muted note shows and nothing links to a store.
	 */
	public function test_widget_row_without_seo_plugin(): void {
		$html = Widget_Headline::render_seo( Seo_Issue_Summary::build( $this->scan( 2, null ) ) );

		$this->assertStringContainsString( 'SEO: 2 issues to fix', $html );
		$this->assertStringContainsString( 'Install Yoast SEO, Rank Math, All in One SEO or SEOPress for title and description checks', $html );
		$this->assertStringContainsString( 'smac-widget-seo-note', $html );
		$this->assertStringNotContainsString( 'wordpress.org', $html );
		$this->assertStringNotContainsString( 'plugin-install', $html );
	}

	/**
	 * The row is hidden when nothing was scanned or nothing is cached.
	 */
	public function test_widget_row_hidden_without_scanned_pages(): void {
		$this->assertSame( '', Widget_Headline::render_seo( Seo_Issue_Summary::build( $this->scan( 0, 'Yoast SEO', 0 ) ) ) );
		$this->assertSame( '', Widget_Headline::render_seo( null ) );
	}

	/**
	 * Fake Bing client.
	 *
	 * @param bool $fail Whether the traffic report throws.
	 * @return object
	 */
	private function bing( $fail = false ) {
		return new class( $fail ) {
			/**
			 * Whether to fail.
			 *
			 * @var bool
			 */
			private $fail;

			/**
			 * Constructor.
			 *
			 * @param bool $fail Whether to fail.
			 */
			public function __construct( $fail ) {
				$this->fail = $fail;
			}

			/**
			 * Daily rows: the current window is bigger.
			 *
			 * @param string $start Start.
			 * @param string $end   End.
			 * @return array
			 * @throws \Exception When failing.
			 */
			public function get_rank_and_traffic_stats( $start, $end ) {
				if ( $this->fail ) {
					throw new \Exception( 'bing down' );
				}
				$is_current = '2026-09-28' === $start;
				return array(
					array( 'date' => $start, 'clicks' => $is_current ? 30 : 20, 'impressions' => $is_current ? 600 : 500 ),
					array( 'date' => $end, 'clicks' => $is_current ? 30 : 20, 'impressions' => $is_current ? 600 : 500 ),
				);
			}

			/**
			 * Top queries.
			 *
			 * @return array
			 */
			public function get_query_stats() {
				return array(
					array( 'query' => 'blue widgets', 'clicks' => 9 ),
					array( 'query' => 'red <b>gadgets</b>', 'clicks' => 5 ),
					array( 'query' => 'gizmos', 'clicks' => 1 ),
				);
			}
		};
	}

	/**
	 * Collector over Bing and SEO doubles.
	 *
	 * @param bool        $bing_ok  Whether Bing is connected with a passing test.
	 * @param array       $scan     SEO scan result.
	 * @param object|null $bing     Bing client.
	 * @return array Context.
	 */
	private function context( $bing_ok = true, ?array $scan = null, $bing = null ) {
		$calls = 0;
		if ( $bing_ok ) {
			update_option( 'specflux_mac_last_test_bing', array( 'success' => true ) );
		}
		$summary = new Weekly_Summary( array( 'bing' => $bing ?? $this->bing() ), array( 'bing' ), $this->service( $scan ?? $this->scan(), $calls ) );

		return $summary->collect_report( gmmktime( 10, 0, 0, 10, 7, 2026 ) );
	}

	/**
	 * Bing and SEO sections appear when connected, tested and there are issues.
	 */
	public function test_email_has_bing_and_seo_sections(): void {
		$context  = $this->context();
		$sections = ( new Weekly_Summary_Email() )->build_sections( $context );
		$by_id    = array_column( $sections, null, 'id' );

		$this->assertSame( array( 'bing', 'seo' ), array_keys( $by_id ) );

		$bing = $by_id['bing']['html'];
		$this->assertSame( 'Bing search', $by_id['bing']['title'] );
		$this->assertStringContainsString( 'Clicks', $bing );
		$this->assertStringContainsString( 'Impressions', $bing );
		$this->assertStringContainsString( '&#9650; 50', $bing, 'Clicks 40 to 60 is up 50%' );
		$this->assertStringContainsString( 'blue widgets', $bing );
		$this->assertStringContainsString( 'red &lt;b&gt;gadgets&lt;/b&gt;', $bing, 'Queries are escaped' );

		$seo = $by_id['seo']['html'];
		$this->assertSame( 'SEO to fix', $by_id['seo']['title'] );
		$this->assertStringContainsString( '5 issues found', $seo );
		$this->assertStringContainsString( 'Missing meta description', $seo );
		$this->assertStringContainsString( 'Pricing page', $seo );
		$this->assertSame( 3, substr_count( $seo, 'Ask AI how to fix' ) );
		$this->assertStringNotContainsString( 'Install Yoast', $seo );
	}

	/**
	 * Each issue link carries a rawurlencoded prompt about that page.
	 */
	public function test_seo_links_encode_the_prompt(): void {
		$sections = array_column( ( new Weekly_Summary_Email() )->build_sections( $this->context() ), null, 'id' );
		$html     = $sections['seo']['html'];

		preg_match_all( '/href="([^"]+)"/', $html, $matches );
		$this->assertCount( 3, $matches[1] );
		foreach ( $matches[1] as $href ) {
			$this->assertStringContainsString( 'page=specflux-mac-ai-assistant', $href );
			$this->assertStringNotContainsString( ' ', $href );
			$this->assertStringNotContainsString( '%2520', $href );
		}

		$href = html_entity_decode( $matches[1][1] );
		parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $query );
		$this->assertSame( Weekly_Prompt_Builder::seo_issue_prompt( Seo_Issue_Summary::build( $this->scan() )['top'][1] ), $query['prompt'] );
		$this->assertStringContainsString( 'About "us"?', $query['prompt'], 'Quotes and ? survive the round trip' );
	}

	/**
	 * Bing is left out when its last connection test failed or never ran.
	 */
	public function test_bing_section_needs_a_passing_test(): void {
		$context = $this->context( false );
		$this->assertArrayNotHasKey( 'bing', $context );

		update_option( 'specflux_mac_last_test_bing', array( 'success' => false ) );
		$context = $this->context( false );
		$this->assertArrayNotHasKey( 'bing', $context );
		$this->assertNotContains( 'bing', array_column( ( new Weekly_Summary_Email() )->build_sections( $context ), 'id' ) );
	}

	/**
	 * A Bing error drops only the Bing section; the email still renders.
	 */
	public function test_bing_failure_soft_fails(): void {
		$context = $this->context( true, null, $this->bing( true ) );

		$this->assertSame( 'error', $context['bing']['status'] );

		$email    = new Weekly_Summary_Email();
		$sections = $email->build_sections( $context );
		$this->assertSame( array( 'seo' ), array_column( $sections, 'id' ) );
		$this->assertStringContainsString( 'SEO to fix', $email->render( $context, $sections ) );
	}

	/**
	 * A malformed bing or seo context never breaks the email.
	 */
	public function test_malformed_extras_soft_fail(): void {
		$context  = array(
			'platforms' => array(),
			'bing'      => array(
				'status' => 'ok',
				'data'   => array( 'range' => 'nope' ),
			),
			'seo'       => 'garbage',
		);
		$sections = ( new Weekly_Summary_Email() )->build_sections( $context );

		$this->assertSame( array(), $sections );
	}

	/**
	 * The SEO section is omitted with no issues, and a scan failure never blocks collection.
	 */
	public function test_seo_section_omitted_when_nothing_to_fix(): void {
		$context = $this->context( true, $this->scan( 0 ) );

		$this->assertSame( array( 'bing' ), array_column( ( new Weekly_Summary_Email() )->build_sections( $context ), 'id' ) );

		$failing = new Weekly_Summary(
			array(),
			array(),
			new class() extends Seo_Issue_Summary {
				/**
				 * Always fails.
				 *
				 * @throws \Exception Always.
				 */
				public function get_or_refresh() {
					throw new \Exception( 'boom' );
				}
			}
		);
		$context = $failing->collect_report();
		$this->assertArrayNotHasKey( 'seo', $context );
	}

	/**
	 * Without an SEO plugin the email says how to get title and description checks, with no link to a store.
	 */
	public function test_seo_section_without_plugin_has_muted_note(): void {
		$sections = array_column( ( new Weekly_Summary_Email() )->build_sections( $this->context( true, $this->scan( 2, null ) ) ), null, 'id' );

		$this->assertStringContainsString( 'Install Yoast SEO, Rank Math, All in One SEO or SEOPress', $sections['seo']['html'] );
		$this->assertStringNotContainsString( 'wordpress.org', $sections['seo']['html'] );
	}

	/**
	 * The sections filter still sees and can change the new sections.
	 */
	public function test_sections_filter_still_applies(): void {
		$seen                                                                         = array();
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_weekly_summary_sections'] = static function ( $sections ) use ( &$seen ) {
			$seen       = array_column( $sections, 'id' );
			$sections[] = array(
				'id'    => 'extra',
				'title' => 'Extra',
				'html'  => '<p>added</p>',
			);
			return $sections;
		};

		$final = ( new Weekly_Summary_Email() )->build_sections( $this->context() );

		$this->assertSame( array( 'bing', 'seo' ), $seen );
		$this->assertSame( array( 'bing', 'seo', 'extra' ), array_column( $final, 'id' ) );
	}
}
