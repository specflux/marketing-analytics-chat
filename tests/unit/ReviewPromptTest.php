<?php
/**
 * Tests for the WordPress.org review prompt.
 *
 * @package Specflux_Marketing_Analytics
 */

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Admin\Review_Prompt;

/**
 * Review prompt eligibility and dismissal tests.
 */
class ReviewPromptTest extends TestCase {

	/**
	 * Prompt under test.
	 *
	 * @var Review_Prompt
	 */
	private $prompt;

	/**
	 * Reset mock option store and admin screen.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options                    = array();
		$GLOBALS['mock_current_screen']  = null;
		$this->prompt                    = new Review_Prompt();
	}

	/**
	 * Clean up globals so other test classes are unaffected.
	 */
	protected function tearDown(): void {
		global $mock_options;
		$mock_options                   = array();
		$GLOBALS['mock_current_screen'] = null;
		unset( $_POST['mode'], $_POST['nonce'] );
		parent::tearDown();
	}

	/**
	 * Pretend the browser is on one of the plugin's own admin screens.
	 *
	 * @param string $screen_id Screen id to report.
	 */
	private function on_plugin_screen( $screen_id = 'toplevel_page_specflux-mac' ) {
		$screen                         = new stdClass();
		$screen->id                     = $screen_id;
		$GLOBALS['mock_current_screen'] = $screen;
	}

	/**
	 * Record a number of fetches with a first-fetch timestamp in the past.
	 *
	 * @param int $count    Number of fetches to record.
	 * @param int $days_ago Age of the first fetch, in days.
	 */
	private function seed_fetches( $count, $days_ago ) {
		update_option( Review_Prompt::OPTION_FETCH_COUNT, $count, false );
		update_option( Review_Prompt::OPTION_FIRST_FETCH, time() - ( $days_ago * DAY_IN_SECONDS ), false );
	}

	public function test_record_fetch_increments_count_and_sets_first_fetch(): void {
		$before = time();
		$this->prompt->record_fetch( 'ga4' );

		$this->assertSame( 1, (int) get_option( Review_Prompt::OPTION_FETCH_COUNT ) );

		$first = (int) get_option( Review_Prompt::OPTION_FIRST_FETCH );
		$this->assertGreaterThanOrEqual( $before, $first );
		$this->assertLessThanOrEqual( time(), $first );

		$this->prompt->record_fetch( 'gsc' );
		$this->prompt->record_fetch( 'clarity' );

		$this->assertSame( 3, (int) get_option( Review_Prompt::OPTION_FETCH_COUNT ) );

		// The first-fetch timestamp is only written once.
		$this->assertSame( $first, (int) get_option( Review_Prompt::OPTION_FIRST_FETCH ) );
	}

	public function test_record_fetch_stops_at_the_cap(): void {
		update_option( Review_Prompt::OPTION_FETCH_COUNT, Review_Prompt::MAX_FETCH_COUNT, false );
		update_option( Review_Prompt::OPTION_FIRST_FETCH, time() - DAY_IN_SECONDS, false );

		$this->prompt->record_fetch( 'ga4' );

		$this->assertSame(
			Review_Prompt::MAX_FETCH_COUNT,
			(int) get_option( Review_Prompt::OPTION_FETCH_COUNT )
		);
	}

	public function test_not_shown_with_too_few_fetches(): void {
		$this->on_plugin_screen();
		$this->seed_fetches( Review_Prompt::MIN_FETCHES - 1, 30 );

		$this->assertFalse( $this->prompt->should_show() );
	}

	public function test_not_shown_before_the_minimum_age(): void {
		$this->on_plugin_screen();
		$this->seed_fetches( 10, 2 );

		$this->assertFalse( $this->prompt->should_show() );
	}

	public function test_shown_once_fetch_count_and_age_are_met(): void {
		$this->on_plugin_screen();
		$this->seed_fetches( Review_Prompt::MIN_FETCHES, 8 );

		$this->assertTrue( $this->prompt->should_show() );
	}

	public function test_not_shown_off_plugin_screens(): void {
		$this->seed_fetches( 10, 30 );

		// No screen at all.
		$this->assertFalse( $this->prompt->should_show() );

		// A screen belonging to some other part of wp-admin.
		$this->on_plugin_screen( 'edit-post' );
		$this->assertFalse( $this->prompt->should_show() );
	}

	public function test_not_shown_once_permanently_dismissed(): void {
		$this->on_plugin_screen();
		$this->seed_fetches( 10, 30 );
		update_option( Review_Prompt::OPTION_DISMISSED, true, false );

		$this->assertFalse( $this->prompt->should_show() );
	}

	public function test_not_shown_while_snoozed(): void {
		$this->on_plugin_screen();
		$this->seed_fetches( 10, 30 );
		$this->prompt->snooze();

		$this->assertGreaterThan( time(), (int) get_option( Review_Prompt::OPTION_SNOOZE_UNTIL ) );
		$this->assertFalse( $this->prompt->should_show() );
	}

	public function test_shown_again_after_the_snooze_expires(): void {
		$this->on_plugin_screen();
		$this->seed_fetches( 10, 60 );
		update_option( Review_Prompt::OPTION_SNOOZE_UNTIL, time() - 1, false );

		$this->assertTrue( $this->prompt->should_show() );
	}

	public function test_dismiss_ajax_with_later_snoozes_without_dismissing(): void {
		$_POST['nonce'] = 'test-nonce';
		$_POST['mode']  = 'later';

		$this->prompt->handle_dismiss();

		$this->assertFalse( (bool) get_option( Review_Prompt::OPTION_DISMISSED ) );
		$this->assertGreaterThan( time(), (int) get_option( Review_Prompt::OPTION_SNOOZE_UNTIL ) );
	}

	public function test_dismiss_ajax_with_never_sets_dismissed(): void {
		$_POST['nonce'] = 'test-nonce';
		$_POST['mode']  = 'never';

		$this->prompt->handle_dismiss();

		$this->assertTrue( (bool) get_option( Review_Prompt::OPTION_DISMISSED ) );
		$this->assertSame( 0, (int) get_option( Review_Prompt::OPTION_SNOOZE_UNTIL, 0 ) );
	}

	public function test_dismiss_ajax_defaults_to_snooze_when_mode_is_missing(): void {
		$_POST['nonce'] = 'test-nonce';

		$this->prompt->handle_dismiss();

		$this->assertFalse( (bool) get_option( Review_Prompt::OPTION_DISMISSED ) );
		$this->assertGreaterThan( time(), (int) get_option( Review_Prompt::OPTION_SNOOZE_UNTIL ) );
	}

	public function test_init_registers_expected_hooks(): void {
		global $mock_actions;
		$mock_actions = array();

		$this->prompt->init();

		$hooks = array_column( $mock_actions, 'hook' );

		$this->assertContains( 'specflux_mac_data_fetched', $hooks );
		$this->assertContains( 'admin_notices', $hooks );
		$this->assertContains( 'wp_ajax_specflux_mac_review_dismiss', $hooks );
	}

	public function test_notice_renders_review_link_and_controls(): void {
		$this->on_plugin_screen();
		$this->seed_fetches( 10, 30 );

		ob_start();
		$this->prompt->maybe_render_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'smac-review-notice', $output );
		$this->assertStringContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( Review_Prompt::REVIEW_URL, $output );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $output );
		$this->assertStringContainsString( 'smac-review-later', $output );
		$this->assertStringContainsString( 'smac-review-never', $output );
	}

	public function test_notice_renders_nothing_when_ineligible(): void {
		ob_start();
		$this->prompt->maybe_render_notice();
		$output = ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}
}
