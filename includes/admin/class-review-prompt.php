<?php
/**
 * Review Prompt
 *
 * Shows a single dismissible, non-incentivized request for a WordPress.org
 * review once the site has actually pulled analytics data a few times.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Admin;

use Specflux_Marketing_Analytics\Utils\Permission_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Asks for a WordPress.org review after repeated successful data fetches.
 */
class Review_Prompt {

	/**
	 * Option holding the number of successful data fetches.
	 *
	 * @var string
	 */
	public const OPTION_FETCH_COUNT = 'specflux_mac_review_fetch_count';

	/**
	 * Option holding the timestamp of the first recorded fetch.
	 *
	 * @var string
	 */
	public const OPTION_FIRST_FETCH = 'specflux_mac_review_first_fetch';

	/**
	 * Option set when the prompt is dismissed permanently.
	 *
	 * @var string
	 */
	public const OPTION_DISMISSED = 'specflux_mac_review_dismissed';

	/**
	 * Option holding the timestamp until which the prompt is snoozed.
	 *
	 * @var string
	 */
	public const OPTION_SNOOZE_UNTIL = 'specflux_mac_review_snooze_until';

	/**
	 * Minimum number of successful fetches before asking.
	 *
	 * @var int
	 */
	public const MIN_FETCHES = 3;

	/**
	 * Minimum age, in seconds, of the first fetch before asking.
	 *
	 * @var int
	 */
	public const MIN_AGE = 7 * DAY_IN_SECONDS;

	/**
	 * How long "Maybe later" hides the prompt, in seconds.
	 *
	 * @var int
	 */
	public const SNOOZE_DURATION = 30 * DAY_IN_SECONDS;

	/**
	 * Upper bound on the stored fetch count.
	 *
	 * The exact number stops being interesting well before this; capping it
	 * keeps the option from growing without limit.
	 *
	 * @var int
	 */
	public const MAX_FETCH_COUNT = 1000;

	/**
	 * WordPress.org review form URL.
	 *
	 * @var string
	 */
	public const REVIEW_URL = 'https://wordpress.org/support/plugin/specflux-marketing-analytics-chat/reviews/#new-post';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'specflux_mac_data_fetched', array( $this, 'record_fetch' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
		add_action( 'wp_ajax_specflux_mac_review_dismiss', array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Record a successful data fetch.
	 *
	 * @param string $platform Platform key the data came from (unused, kept for hook parity).
	 */
	public function record_fetch( $platform = '' ) {
		unset( $platform );

		$count = (int) get_option( self::OPTION_FETCH_COUNT, 0 );

		if ( $count >= self::MAX_FETCH_COUNT ) {
			return;
		}

		if ( ! get_option( self::OPTION_FIRST_FETCH ) ) {
			update_option( self::OPTION_FIRST_FETCH, time(), false );
		}

		update_option( self::OPTION_FETCH_COUNT, $count + 1, false );
	}

	/**
	 * Decide whether the review prompt should be shown right now.
	 *
	 * @return bool True when every condition is met.
	 */
	public function should_show() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return false;
		}

		if ( get_option( self::OPTION_DISMISSED ) ) {
			return false;
		}

		$snoozed_until = (int) get_option( self::OPTION_SNOOZE_UNTIL, 0 );

		if ( $snoozed_until > time() ) {
			return false;
		}

		if ( (int) get_option( self::OPTION_FETCH_COUNT, 0 ) < self::MIN_FETCHES ) {
			return false;
		}

		$first_fetch = (int) get_option( self::OPTION_FIRST_FETCH, 0 );

		if ( ! $first_fetch || ( time() - $first_fetch ) < self::MIN_AGE ) {
			return false;
		}

		return $this->is_plugin_screen();
	}

	/**
	 * Check that the current admin screen belongs to this plugin.
	 *
	 * Mirrors the `strpos( $hook, 'specflux-mac' )` gate used when enqueueing
	 * admin assets, so the notice never leaks onto unrelated screens.
	 *
	 * @return bool True when on one of the plugin's own admin pages.
	 */
	private function is_plugin_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen || empty( $screen->id ) ) {
			return false;
		}

		return strpos( $screen->id, 'specflux-mac' ) !== false;
	}

	/**
	 * Render the review notice when eligible.
	 */
	public function maybe_render_notice() {
		if ( ! $this->should_show() ) {
			return;
		}

		?>
		<div class="notice notice-info is-dismissible smac-review-notice">
			<p>
				<?php
				esc_html_e(
					'You have pulled analytics through Marketing Analytics Chat a few times now. If it has been useful, a review on WordPress.org helps other site owners find it.',
					'specflux-marketing-analytics-chat'
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( self::REVIEW_URL ); ?>" class="button button-primary smac-review-leave" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Leave a review', 'specflux-marketing-analytics-chat' ); ?>
				</a>
				<button type="button" class="button-link smac-review-later">
					<?php esc_html_e( 'Maybe later', 'specflux-marketing-analytics-chat' ); ?>
				</button>
				<button type="button" class="button-link smac-review-never">
					<?php esc_html_e( 'Already did / don&#8217;t ask again', 'specflux-marketing-analytics-chat' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the AJAX dismissal of the review notice.
	 */
	public function handle_dismiss() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'specflux_mac_admin' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Please refresh the page and try again.', 'specflux-marketing-analytics-chat' ),
				)
			);
			return;
		}

		if ( ! Permission_Manager::can_access_plugin() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'specflux-marketing-analytics-chat' ),
				)
			);
			return;
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'later';

		if ( 'never' === $mode ) {
			$this->dismiss_forever();
		} else {
			$this->snooze();
		}

		wp_send_json_success(
			array(
				'mode' => 'never' === $mode ? 'never' : 'later',
			)
		);
	}

	/**
	 * Hide the prompt permanently.
	 */
	public function dismiss_forever() {
		update_option( self::OPTION_DISMISSED, true, false );
	}

	/**
	 * Hide the prompt for the snooze window.
	 */
	public function snooze() {
		update_option( self::OPTION_SNOOZE_UNTIL, time() + self::SNOOZE_DURATION, false );
	}
}
