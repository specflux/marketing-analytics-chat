<?php
/**
 * Tests for the weekly summary email.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary_Email;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary_Scheduler;

/**
 * Fake GA4 client returning canned reports.
 */
class Fake_GA4 {
	/**
	 * Recorded calls.
	 *
	 * @var array
	 */
	public $calls = array();

	/**
	 * Canned run_report.
	 *
	 * @param array  $metrics    Metrics.
	 * @param array  $dimensions Dimensions.
	 * @param string $range      Range.
	 * @param array  $options    Options.
	 * @return array
	 */
	public function run_report( $metrics, $dimensions = array(), $range = '', $options = array() ) {
		$this->calls[] = array( $metrics, $dimensions, $range, $options );

		if ( array( 'pagePath' ) === $dimensions ) {
			return array(
				'rows' => array(
					array(
						'pagePath'        => '/pricing',
						'screenPageViews' => '120',
					),
					array(
						'pagePath'        => '/<b>x</b>',
						'screenPageViews' => '80',
					),
				),
			);
		}

		if ( array( 'sessionDefaultChannelGroup' ) === $dimensions ) {
			return array(
				'rows' => array(
					array(
						'sessionDefaultChannelGroup' => 'Organic Search',
						'sessions'                   => '300',
					),
				),
			);
		}

		// Headline report: current window has higher numbers than the previous one.
		$is_current = false === strpos( $range, '2026-09-23' );
		return array(
			'rows' => array(
				array(
					'sessions'        => $is_current ? '150' : '100',
					'totalUsers'      => $is_current ? '90' : '0',
					'screenPageViews' => $is_current ? '400' : '500',
				),
			),
		);
	}
}

/**
 * Fake GSC client.
 */
class Fake_GSC {
	/**
	 * Canned query_search_analytics.
	 *
	 * @param string $range      Range.
	 * @param array  $dimensions Dimensions.
	 * @return array
	 */
	public function query_search_analytics( $range = '', $dimensions = array() ) {
		if ( array( 'query' ) === $dimensions ) {
			return array(
				'rows' => array(
					array(
						'key'    => 'specflux',
						'clicks' => 12,
					),
				),
			);
		}

		return array(
			'rows' => array(
				array(
					'clicks'      => 10,
					'impressions' => 1000,
					'ctr'         => 0.01,
					'position'    => 8.5,
				),
			),
		);
	}
}

/**
 * Client that always throws.
 */
class Throwing_Client {
	/**
	 * Always fails.
	 *
	 * @param mixed ...$args Ignored.
	 * @throws \Exception Always.
	 */
	public function __call( $name, $args ) {
		throw new \Exception( 'boom' );
	}
}

/**
 * Weekly summary tests.
 */
class WeeklySummaryTest extends TestCase {

	/**
	 * Reset mock state.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array();
		unset(
			$GLOBALS['specflux_mac_test_cron'],
			$GLOBALS['specflux_mac_test_cron_log'],
			$GLOBALS['specflux_mac_test_filters'],
			$GLOBALS['specflux_mac_test_mail'],
			$GLOBALS['specflux_mac_test_mail_result'],
			$GLOBALS['specflux_mac_test_timezone']
		);
	}

	/**
	 * Clean up global mock state.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['specflux_mac_test_filters'], $GLOBALS['specflux_mac_test_timezone'] );
		parent::tearDown();
	}

	/**
	 * A fixed "now": Wednesday 2026-10-07 10:00 UTC.
	 *
	 * @return int
	 */
	private function now(): int {
		return gmmktime( 10, 0, 0, 10, 7, 2026 );
	}

	/**
	 * Build a collector over fake clients.
	 *
	 * @param array $clients Clients.
	 * @return Weekly_Summary
	 */
	private function summary( array $clients ): Weekly_Summary {
		return new Weekly_Summary( $clients, array_keys( $clients ) );
	}

	/**
	 * Percent change handles normal, zero-baseline and zero/zero cases.
	 */
	public function test_percent_change(): void {
		$this->assertEqualsWithDelta( 50.0, Weekly_Summary::percent_change( 150, 100 ), 0.001 );
		$this->assertEqualsWithDelta( -20.0, Weekly_Summary::percent_change( 400, 500 ), 0.001 );
		$this->assertSame( 0.0, Weekly_Summary::percent_change( 0, 0 ) );
		$this->assertNull( Weekly_Summary::percent_change( 90, 0 ) );
	}

	/**
	 * GA4 ends yesterday; GSC ends three days ago; windows are 7 days and adjacent.
	 */
	public function test_ranges(): void {
		$ranges = $this->summary( array() )->get_ranges( $this->now() );

		$this->assertSame( array( '2026-09-30', '2026-10-06' ), $ranges['ga4']['current'] );
		$this->assertSame( array( '2026-09-23', '2026-09-29' ), $ranges['ga4']['previous'] );
		$this->assertSame( array( '2026-09-28', '2026-10-04' ), $ranges['gsc']['current'] );
		$this->assertSame( array( '2026-09-21', '2026-09-27' ), $ranges['gsc']['previous'] );
	}

	/**
	 * Data assembly computes metrics, changes and top lists.
	 */
	public function test_collect_assembles_data(): void {
		$ga4     = new Fake_GA4();
		$context = $this->summary(
			array(
				'ga4' => $ga4,
				'gsc' => new Fake_GSC(),
			)
		)->collect( $this->now() );

		$this->assertSame( 'ok', $context['platforms']['ga4']['status'] );
		$metrics = array_column( $context['platforms']['ga4']['data']['metrics'], null, 'key' );
		$this->assertEqualsWithDelta( 50.0, $metrics['sessions']['change'], 0.001 );
		$this->assertNull( $metrics['totalUsers']['change'], 'previous=0 has no baseline' );
		$this->assertEqualsWithDelta( -20.0, $metrics['screenPageViews']['change'], 0.001 );
		$this->assertCount( 2, $context['platforms']['ga4']['data']['pages'] );
		$this->assertSame( 'Organic Search', $context['platforms']['ga4']['data']['sources'][0]['label'] );
		$this->assertSame( '2026-09-30,2026-10-06', $ga4->calls[0][2] );

		$gsc = array_column( $context['platforms']['gsc']['data']['metrics'], null, 'key' );
		$this->assertEqualsWithDelta( 1.0, $gsc['ctr']['current'], 0.001, 'CTR is shown as a percentage' );
		$this->assertTrue( $gsc['position']['lower_is_better'] );
		$this->assertSame( 'specflux', $context['platforms']['gsc']['data']['queries'][0]['label'] );
	}

	/**
	 * A failing platform is isolated and never breaks the others.
	 */
	public function test_failing_platform_is_isolated(): void {
		$context = $this->summary(
			array(
				'ga4'     => new Throwing_Client(),
				'gsc'     => new Fake_GSC(),
				'clarity' => new Throwing_Client(),
			)
		)->collect( $this->now() );

		$this->assertSame( 'error', $context['platforms']['ga4']['status'] );
		$this->assertSame( 'ok', $context['platforms']['gsc']['status'] );

		$email    = new Weekly_Summary_Email();
		$sections = $email->build_sections( $context );
		$ids      = array_column( $sections, 'id' );

		$this->assertSame( array( 'ga4', 'gsc' ), $ids, 'Clarity failure is skipped silently' );
		$this->assertStringContainsString( 'load this data', $sections[0]['html'] );
	}

	/**
	 * The sections filter can append and reorder sections.
	 */
	public function test_sections_filter_is_applied(): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_weekly_summary_sections'] = static function ( $sections, $context ) {
			$sections[] = array(
				'id'    => 'extra',
				'title' => 'Extra',
				'html'  => '<p>added by add-on</p>',
			);
			$sections[] = 'garbage';
			return $sections;
		};

		$context  = $this->summary( array( 'gsc' => new Fake_GSC() ) )->collect( $this->now() );
		$email    = new Weekly_Summary_Email();
		$sections = $email->build_sections( $context );

		$this->assertSame( array( 'gsc', 'extra' ), array_column( $sections, 'id' ) );
		$this->assertStringContainsString( 'added by add-on', $email->render( $context ) );
	}

	/**
	 * Nothing is sent when no platform is connected.
	 */
	public function test_no_send_when_nothing_connected(): void {
		$scheduler = new Weekly_Summary_Scheduler( new Weekly_Summary( array(), array() ) );
		$result    = $scheduler->send();

		$this->assertFalse( $result['sent'] );
		$this->assertEmpty( $GLOBALS['specflux_mac_test_mail'] ?? array() );
	}

	/**
	 * A connected site sends one HTML email to the recipients.
	 */
	public function test_send_uses_html_content_type(): void {
		$scheduler = new Weekly_Summary_Scheduler( $this->summary( array( 'gsc' => new Fake_GSC() ) ) );
		$result    = $scheduler->send();

		$this->assertTrue( $result['sent'] );
		$this->assertCount( 1, $GLOBALS['specflux_mac_test_mail'] );
		$mail = $GLOBALS['specflux_mac_test_mail'][0];
		$this->assertSame( array( 'admin@example.com' ), $mail['to'] );
		$this->assertStringContainsString( 'your weekly marketing summary', $mail['subject'] );
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $mail['headers'] );
	}

	/**
	 * A wp_mail failure is reported, not swallowed.
	 */
	public function test_send_reports_mail_failure(): void {
		$GLOBALS['specflux_mac_test_mail_result'] = false;

		$scheduler = new Weekly_Summary_Scheduler( $this->summary( array( 'gsc' => new Fake_GSC() ) ) );

		$this->assertFalse( $scheduler->send()['sent'] );
	}

	/**
	 * Recipient parsing validates each address.
	 */
	public function test_recipient_validation(): void {
		$parsed = Weekly_Summary_Scheduler::parse_recipients( 'a@example.com, nope, B@example.com;a@example.com' );

		$this->assertSame( array( 'a@example.com', 'B@example.com' ), $parsed['valid'] );
		$this->assertSame( array( 'nope' ), $parsed['invalid'] );
	}

	/**
	 * Empty saved recipients fall back to the admin email; saved ones win.
	 */
	public function test_recipients_default_and_saved(): void {
		global $mock_options;
		$scheduler = new Weekly_Summary_Scheduler();

		$this->assertSame( array( 'admin@example.com' ), $scheduler->get_recipients() );

		$mock_options[ Weekly_Summary_Scheduler::OPTION_RECIPIENTS ] = 'one@example.com, bad';
		$this->assertSame( array( 'one@example.com' ), $scheduler->get_recipients() );
	}

	/**
	 * The recipients filter is applied and its output re-validated.
	 */
	public function test_recipients_filter_is_applied_and_revalidated(): void {
		global $mock_options;
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_weekly_summary_recipients'] = static function ( $recipients ) {
			return array_merge( $recipients, array( 'extra@example.com', 'not-an-email' ) );
		};

		$this->assertSame( array( 'admin@example.com', 'extra@example.com' ), ( new Weekly_Summary_Scheduler() )->get_recipients() );
	}

	/**
	 * Next run is the next Monday 08:00 in the site timezone.
	 */
	public function test_next_run_timestamp(): void {
		$scheduler = new Weekly_Summary_Scheduler();

		// Wednesday 10:00 UTC -> Monday 2026-10-12 08:00 UTC.
		$this->assertSame( gmmktime( 8, 0, 0, 10, 12, 2026 ), $scheduler->next_run_timestamp( $this->now() ) );
		// Monday 07:00 -> same day 08:00.
		$this->assertSame( gmmktime( 8, 0, 0, 10, 12, 2026 ), $scheduler->next_run_timestamp( gmmktime( 7, 0, 0, 10, 12, 2026 ) ) );
		// Monday 08:00 exactly -> a week later.
		$this->assertSame( gmmktime( 8, 0, 0, 10, 19, 2026 ), $scheduler->next_run_timestamp( gmmktime( 8, 0, 0, 10, 12, 2026 ) ) );
		// Sunday -> next day.
		$this->assertSame( gmmktime( 8, 0, 0, 10, 12, 2026 ), $scheduler->next_run_timestamp( gmmktime( 23, 0, 0, 10, 11, 2026 ) ) );
	}

	/**
	 * Site timezone shifts the run time.
	 */
	public function test_next_run_respects_site_timezone(): void {
		$GLOBALS['specflux_mac_test_timezone'] = 'America/New_York';

		// Monday 08:00 EDT is 12:00 UTC.
		$this->assertSame( gmmktime( 12, 0, 0, 10, 12, 2026 ), ( new Weekly_Summary_Scheduler() )->next_run_timestamp( $this->now() ) );
	}

	/**
	 * Schedule is created when enabled and missing, and cleared when disabled.
	 */
	public function test_sync_schedule(): void {
		global $mock_options;
		$scheduler = new Weekly_Summary_Scheduler();

		$mock_options[ Weekly_Summary_Scheduler::OPTION_ENABLED ] = 1;
		$scheduler->sync_schedule();
		$this->assertCount( 1, $GLOBALS['specflux_mac_test_cron_log'] );
		$this->assertSame( 'weekly', $GLOBALS['specflux_mac_test_cron_log'][0][1] );
		$this->assertSame( Weekly_Summary_Scheduler::HOOK, $GLOBALS['specflux_mac_test_cron_log'][0][2] );

		// Already scheduled: no duplicate.
		$scheduler->sync_schedule();
		$this->assertCount( 1, $GLOBALS['specflux_mac_test_cron_log'] );

		$mock_options[ Weekly_Summary_Scheduler::OPTION_ENABLED ] = 0;
		$scheduler->sync_schedule();
		$this->assertArrayNotHasKey( Weekly_Summary_Scheduler::HOOK, $GLOBALS['specflux_mac_test_cron'] );
	}

	/**
	 * A missing option means off, so updating sites must opt in.
	 */
	public function test_disabled_when_option_missing(): void {
		$this->assertFalse( ( new Weekly_Summary_Scheduler() )->is_enabled() );
	}

	/**
	 * Fresh activation seeds the option on.
	 */
	public function test_activate_seeds_enabled(): void {
		Weekly_Summary_Scheduler::activate();

		$this->assertTrue( ( new Weekly_Summary_Scheduler() )->is_enabled() );
	}

	/**
	 * Nothing is mailed when every connected platform failed.
	 */
	public function test_send_skipped_when_all_platforms_failed(): void {
		$scheduler = new Weekly_Summary_Scheduler( $this->summary( array( 'gsc' => new Throwing_Client() ) ) );
		$result    = $scheduler->send();

		$this->assertFalse( $result['sent'] );
		$this->assertStringContainsString( 'no summary was sent', $result['message'] );
		$this->assertEmpty( $GLOBALS['specflux_mac_test_mail'] ?? array() );
	}

	/**
	 * Average position is unavailable, not 0, without impressions.
	 */
	public function test_position_is_null_without_impressions(): void {
		$gsc = new class() {
			/**
			 * Zero impressions in the current window only.
			 *
			 * @param string $range      Range.
			 * @param array  $dimensions Dimensions.
			 * @return array
			 */
			public function query_search_analytics( $range = '', $dimensions = array() ) {
				if ( array() !== $dimensions ) {
					return array( 'rows' => array() );
				}
				$is_current = false === strpos( $range, '2026-09-23' );
				return array(
					'rows' => array(
						array(
							'clicks'      => 0,
							'impressions' => $is_current ? 0 : 50,
							'ctr'         => 0,
							'position'    => $is_current ? 0 : 7.5,
						),
					),
				);
			}
		};

		$context  = $this->summary( array( 'gsc' => $gsc ) )->collect( $this->now() );
		$position = null;
		foreach ( $context['platforms']['gsc']['data']['metrics'] as $metric ) {
			if ( 'position' === $metric['key'] ) {
				$position = $metric;
			}
		}

		$this->assertNotNull( $position );
		$this->assertNull( $position['current'] );
		$this->assertNull( $position['change'] );
		$this->assertSame( '—', ( new Weekly_Summary_Email() )->format_value( null, 'position' ) );
	}

	/**
	 * Hostile values are escaped everywhere, and no upsell copy appears.
	 */
	public function test_renderer_escapes_values(): void {
		$context = array(
			'generated_at' => $this->now(),
			'ranges'       => ( new Weekly_Summary() )->get_ranges( $this->now() ),
			'platforms'    => array(
				'ga4' => array(
					'status' => 'ok',
					'data'   => array(
						'range'   => array( '2026-09-30', '2026-10-06' ),
						'metrics' => array(
							array(
								'key'             => 'sessions',
								'label'           => '<script>alert(1)</script>',
								'current'         => 10,
								'previous'        => 5,
								'change'          => 100.0,
								'format'          => 'int',
								'lower_is_better' => false,
							),
						),
						'pages'   => array(
							array(
								'label' => '"><img src=x onerror=alert(1)>',
								'value' => 3,
							),
						),
						'sources' => array(),
					),
				),
			),
		);

		$html = ( new Weekly_Summary_Email() )->render( $context );

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'specflux-mac-ai-assistant', $html );
		$this->assertStringNotContainsString( 'Pro', $html );
		$this->assertStringNotContainsString( '$', $html );
	}

	/**
	 * Changes render green for improvements and red for declines, inverted for position.
	 */
	public function test_change_colours(): void {
		$email = new Weekly_Summary_Email();

		$this->assertStringContainsString( Weekly_Summary_Email::COLOR_UP, $email->format_change( 10.0 ) );
		$this->assertStringContainsString( Weekly_Summary_Email::COLOR_DOWN, $email->format_change( -10.0 ) );
		$this->assertStringContainsString( Weekly_Summary_Email::COLOR_UP, $email->format_change( -10.0, true ) );
		$this->assertStringContainsString( 'New', $email->format_change( null ) );
	}

	/**
	 * Render a human-readable sample to /tmp using fake data.
	 */
	public function test_render_sample_file(): void {
		$context = $this->summary(
			array(
				'ga4' => new Fake_GA4(),
				'gsc' => new Fake_GSC(),
			)
		)->collect( $this->now() );

		$context['platforms']['clarity'] = array(
			'status' => 'ok',
			'data'   => array(
				'stats' => array(
					array(
						'label' => 'Sessions',
						'value' => '1,284',
					),
					array(
						'label' => 'Pages per session',
						'value' => '2.4',
					),
					array(
						'label' => 'Avg. scroll depth',
						'value' => '57%',
					),
				),
			),
		);

		$html = ( new Weekly_Summary_Email() )->render( $context );

		$this->assertStringContainsString( 'Ask the AI about this week', $html );
		$this->assertStringContainsString( 'Change email settings', $html );
		$this->assertGreaterThan( 0, file_put_contents( '/tmp/smac-weekly-summary-sample.html', $html ) );
	}
}
