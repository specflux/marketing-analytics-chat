<?php
/**
 * Weekly Summary email preview
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Reports;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Lets an administrator open this week's email in the browser, whether or not
 * the weekly email is switched on.
 */
class Weekly_Summary_Preview {

	/**
	 * Action name for admin-post.php, without the admin_post_ prefix.
	 */
	const ACTION = 'specflux_mac_preview_summary';

	/**
	 * Nonce action.
	 */
	const NONCE_ACTION = 'specflux_mac_preview_summary';

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
	 * Register the admin-post hook.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Nonce-protected preview URL.
	 *
	 * @return string URL (wrap in esc_url() on output).
	 */
	public static function url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::NONCE_ACTION );
	}

	/**
	 * Output the preview page.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'specflux-marketing-analytics-chat' ), '', array( 'response' => 403 ) );
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'This link has expired. Go back to the settings page and try again.', 'specflux-marketing-analytics-chat' ), '', array( 'response' => 403 ) );
			return;
		}

		$html = $this->get_html();

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every value is escaped inside the renderer.
		exit;
	}

	/**
	 * Render the preview document.
	 *
	 * @return string HTML.
	 */
	public function get_html() {
		if ( empty( $this->summary->get_connected_platforms() ) ) {
			return $this->message_page( __( 'No analytics platform is connected yet, so there is nothing to preview. Connect one on the Connections screen.', 'specflux-marketing-analytics-chat' ) );
		}

		$context  = $this->summary->collect();
		$sections = $this->email->build_sections( $context );

		if ( empty( $sections ) ) {
			return $this->message_page( __( "Couldn't load data from any connected platform. Check the Connections screen.", 'specflux-marketing-analytics-chat' ) );
		}

		return $this->email->render( $context, $sections );
	}

	/**
	 * Minimal escaped message page.
	 *
	 * @param string $message Plain text.
	 * @return string HTML.
	 */
	private function message_page( $message ) {
		return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>'
			. esc_html__( 'Weekly summary preview', 'specflux-marketing-analytics-chat' )
			. '</title></head><body style="font-family:sans-serif;padding:40px;color:#1d2327;"><p>'
			. esc_html( $message )
			. '</p></body></html>';
	}
}
