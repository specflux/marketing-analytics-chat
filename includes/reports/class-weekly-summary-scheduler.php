<?php
/**
 * Weekly Summary scheduling, settings and delivery
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Reports;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Owns the weekly cron event, the email settings and the actual send.
 */
class Weekly_Summary_Scheduler {

	/**
	 * Cron hook that triggers the weekly send.
	 */
	const HOOK = 'specflux_mac_weekly_summary';

	/**
	 * Option: whether the weekly email is enabled (1 or 0; missing means off, activation seeds 1).
	 */
	const OPTION_ENABLED = 'specflux_mac_weekly_summary_enabled';

	/**
	 * Option: comma-separated recipient list (empty means the site admin email).
	 */
	const OPTION_RECIPIENTS = 'specflux_mac_weekly_summary_recipients';

	/**
	 * Data collector.
	 *
	 * @var Weekly_Summary
	 */
	private $summary;

	/**
	 * Email renderer.
	 *
	 * @var Weekly_Summary_Email
	 */
	private $email;

	/**
	 * Constructor.
	 *
	 * @param Weekly_Summary|null       $summary Data collector (defaults to live clients).
	 * @param Weekly_Summary_Email|null $email   Renderer.
	 */
	public function __construct( ?Weekly_Summary $summary = null, ?Weekly_Summary_Email $email = null ) {
		$this->summary = $summary ?? new Weekly_Summary();
		$this->email   = $email ?? new Weekly_Summary_Email();
	}

	/**
	 * Run on plugin activation: seed defaults and schedule.
	 *
	 * @return void
	 */
	public static function activate() {
		add_option( self::OPTION_ENABLED, 1 );

		( new self() )->sync_schedule();
	}

	/**
	 * Whether the weekly email is switched on. Defaults to off when the option
	 * has never been saved, so sites updating from an earlier version must opt in.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) (int) get_option( self::OPTION_ENABLED, 0 );
	}

	/**
	 * Split a comma-separated string into valid and invalid addresses.
	 *
	 * @param string $raw Raw input.
	 * @return array{valid:string[],invalid:string[]}
	 */
	public static function parse_recipients( $raw ) {
		$valid   = array();
		$invalid = array();

		foreach ( preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $candidate ) {
			$address = is_email( $candidate );
			if ( $address ) {
				$valid[ strtolower( $address ) ] = $address;
			} else {
				$invalid[] = $candidate;
			}
		}

		return array(
			'valid'   => array_values( $valid ),
			'invalid' => $invalid,
		);
	}

	/**
	 * Final recipient list: saved addresses (or the admin email), filtered.
	 *
	 * @return string[]
	 */
	public function get_recipients() {
		$parsed     = self::parse_recipients( (string) get_option( self::OPTION_RECIPIENTS, '' ) );
		$recipients = $parsed['valid'];

		if ( empty( $recipients ) ) {
			$parsed     = self::parse_recipients( (string) get_bloginfo( 'admin_email' ) );
			$recipients = $parsed['valid'];
		}

		/**
		 * Filters who receives the weekly summary email.
		 *
		 * @param string[] $recipients Email addresses.
		 */
		$filtered = apply_filters( 'specflux_mac_weekly_summary_recipients', $recipients );

		$clean = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $address ) {
			$valid = is_string( $address ) ? is_email( $address ) : false;
			if ( $valid ) {
				$clean[ strtolower( $valid ) ] = $valid;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Timestamp of the next Monday 08:00 in the site timezone.
	 *
	 * Strictly in the future: on Monday before 08:00 it is today, otherwise
	 * next week.
	 *
	 * @param int|null $now Unix timestamp to treat as "now".
	 * @return int
	 */
	public function next_run_timestamp( $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$local = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );

		$days_ahead = ( 8 - (int) $local->format( 'N' ) ) % 7;
		$candidate  = $local->modify( '+' . $days_ahead . ' days' )->setTime( 8, 0, 0 );

		if ( $candidate->getTimestamp() <= $now ) {
			$candidate = $candidate->modify( '+7 days' )->setTime( 8, 0, 0 );
		}

		return $candidate->getTimestamp();
	}

	/**
	 * Make the cron event match the enabled setting.
	 *
	 * Called on activation, on `init` (self-healing if the event was lost) and
	 * after settings are saved.
	 *
	 * @return void
	 */
	public function sync_schedule() {
		$next = wp_next_scheduled( self::HOOK );

		if ( $this->is_enabled() ) {
			if ( ! $next ) {
				wp_schedule_event( $this->next_run_timestamp(), 'weekly', self::HOOK );
			}
		} elseif ( $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * Cron callback: send the weekly summary, then realign the schedule.
	 *
	 * A fixed 7 day interval drifts an hour across daylight saving changes, so
	 * the next event is re-anchored to Monday 08:00 site time after each run.
	 *
	 * @return void
	 */
	public function run() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->send();

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( $this->next_run_timestamp(), 'weekly', self::HOOK );
	}

	/**
	 * Collect data, render and send the email.
	 *
	 * @return array{sent:bool,message:string} Outcome with a human-readable message.
	 */
	public function send() {
		if ( empty( $this->summary->get_connected_platforms() ) ) {
			return array(
				'sent'    => false,
				'message' => __( 'No analytics platform is connected yet, so there is nothing to summarise.', 'specflux-marketing-analytics-chat' ),
			);
		}

		$recipients = $this->get_recipients();
		if ( empty( $recipients ) ) {
			return array(
				'sent'    => false,
				'message' => __( 'There are no valid recipient email addresses.', 'specflux-marketing-analytics-chat' ),
			);
		}

		$context  = $this->summary->collect();
		$sections = $this->email->build_sections( $context );

		$has_data = false;
		foreach ( (array) ( $context['platforms'] ?? array() ) as $result ) {
			if ( is_array( $result ) && 'ok' === ( $result['status'] ?? '' ) ) {
				$has_data = true;
				break;
			}
		}

		if ( ! $has_data || empty( $sections ) ) {
			return array(
				'sent'    => false,
				'message' => __( "Couldn't load data from any connected platform, so no summary was sent. Check the Connections screen.", 'specflux-marketing-analytics-chat' ),
			);
		}

		$subject = $this->email->get_subject( $context );
		$body    = $this->email->render( $context, $sections );

		$sent = wp_mail( $recipients, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		if ( ! $sent ) {
			return array(
				'sent'    => false,
				'message' => __( 'WordPress could not send the email. Check your site email configuration.', 'specflux-marketing-analytics-chat' ),
			);
		}

		return array(
			'sent'    => true,
			'message' => sprintf(
				/* translators: %s: comma-separated email addresses */
				__( 'Summary sent to %s.', 'specflux-marketing-analytics-chat' ),
				implode( ', ', $recipients )
			),
		);
	}
}
