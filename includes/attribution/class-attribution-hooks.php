<?php
/**
 * Copies the source cookie onto orders, donations and form entries.
 *
 * Hook names and meta writers were checked against each plugin's source (see
 * .scratch/integrations-roadmap/research/verify-a0.md). WooCommerce is skipped
 * on purpose: it has its own order attribution.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Attribution;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Per-plugin record-creation handlers. Every handler is a no-op when the
 * plugin is absent, capture is off, consent is missing or no cookie exists.
 */
class Attribution_Hooks {

	/**
	 * Register one listener per supported plugin.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'edd_built_order', array( $this, 'on_edd_order' ), 10, 1 );
		add_action( 'give_insert_payment', array( $this, 'on_give_donation' ), 10, 1 );
		add_action( 'nf_save_sub', array( $this, 'on_ninja_forms' ), 10, 1 );
		add_action( 'frm_after_create_entry', array( $this, 'on_formidable' ), 10, 3 );
		add_action( 'save_post_flamingo_inbound', array( $this, 'on_flamingo' ), 10, 3 );
		add_action( 'fluentform/submission_inserted', array( $this, 'on_fluent_forms' ), 10, 3 );
		add_action( 'gform_entry_created', array( $this, 'on_gravity_forms' ), 10, 2 );
		add_action( 'wpforms_process_complete', array( $this, 'on_wpforms' ), 10, 4 );
	}

	/**
	 * Run a writer with the current attribution, swallowing failures.
	 *
	 * @param callable $writer Receives the attribution array.
	 * @return void
	 */
	private function write( callable $writer ): void {
		$attribution = Source_Capture::current();
		if ( null === $attribution ) {
			return;
		}

		try {
			$writer( $attribution );
		} catch ( \Throwable $e ) {
			// Attribution must never break a checkout or form submission.
			return;
		}
	}

	/**
	 * Easy Digital Downloads 3.x order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function on_edd_order( $order_id ) {
		if ( ! function_exists( 'edd_add_order_meta' ) ) {
			return;
		}
		$this->write(
			static function ( array $a ) use ( $order_id ) {
				edd_add_order_meta( (int) $order_id, Source_Capture::META_KEY, $a, true );
			}
		);
	}

	/**
	 * GiveWP donation (stored in give_donationmeta).
	 *
	 * @param int $payment_id Donation ID.
	 * @return void
	 */
	public function on_give_donation( $payment_id ) {
		if ( ! function_exists( 'give_update_meta' ) ) {
			return;
		}
		$this->write(
			static function ( array $a ) use ( $payment_id ) {
				give_update_meta( (int) $payment_id, Source_Capture::META_KEY, $a, '', 'donation' );
			}
		);
	}

	/**
	 * Ninja Forms submission (nf_sub post, so post meta).
	 *
	 * @param int $sub_id Submission post ID.
	 * @return void
	 */
	public function on_ninja_forms( $sub_id ) {
		$this->write(
			static function ( array $a ) use ( $sub_id ) {
				update_post_meta( (int) $sub_id, Source_Capture::META_KEY, $a );
			}
		);
	}

	/**
	 * Formidable entry. Entry meta there is per field, so the value goes in a
	 * field_id 0 row, Formidable's own convention for entry-level data (for
	 * example captcha_score), as array( META_KEY => attribution ).
	 *
	 * @param int        $entry_id Entry ID.
	 * @param int        $form_id  Form ID.
	 * @param array|null $args     Contains is_child.
	 * @return void
	 */
	public function on_formidable( $entry_id, $form_id = 0, $args = array() ) {
		unset( $form_id );
		if ( ! class_exists( 'FrmEntryMeta' ) || ( is_array( $args ) && ! empty( $args['is_child'] ) ) ) {
			return;
		}
		$this->write(
			static function ( array $a ) use ( $entry_id ) {
				\FrmEntryMeta::add_entry_meta( (int) $entry_id, 0, '', array( Source_Capture::META_KEY => $a ) );
			}
		);
	}

	/**
	 * Flamingo inbound message (used by Contact Form 7). Flamingo has no
	 * creation action, so listen on the post type's insert and only on create.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 * @param bool          $update  Whether this is an update.
	 * @return void
	 */
	public function on_flamingo( $post_id, $post = null, $update = false ) {
		unset( $post );
		if ( $update ) {
			return;
		}
		$this->write(
			static function ( array $a ) use ( $post_id ) {
				update_post_meta( (int) $post_id, Source_Capture::META_KEY, $a );
			}
		);
	}

	/**
	 * Fluent Forms submission.
	 *
	 * @param int         $insert_id Submission ID.
	 * @param array       $form_data Submitted data.
	 * @param object|null $form      Form.
	 * @return void
	 */
	public function on_fluent_forms( $insert_id, $form_data = array(), $form = null ) {
		unset( $form_data );
		if ( ! class_exists( '\FluentForm\App\Helpers\Helper' ) ) {
			return;
		}
		$form_id = is_object( $form ) && isset( $form->id ) ? (int) $form->id : false;
		$this->write(
			static function ( array $a ) use ( $insert_id, $form_id ) {
				\FluentForm\App\Helpers\Helper::setSubmissionMeta( (int) $insert_id, Source_Capture::META_KEY, $a, $form_id );
			}
		);
	}

	/**
	 * Gravity Forms entry. UNVERIFIED against source (commercial plugin).
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return void
	 */
	public function on_gravity_forms( $entry, $form = array() ) {
		if ( ! function_exists( 'gform_update_meta' ) || ! is_array( $entry ) || empty( $entry['id'] ) ) {
			return;
		}
		$form_id = is_array( $form ) && isset( $form['id'] ) ? (int) $form['id'] : 0;
		$this->write(
			static function ( array $a ) use ( $entry, $form_id ) {
				gform_update_meta( (int) $entry['id'], Source_Capture::META_KEY, $a, $form_id );
			}
		);
	}

	/**
	 * WPForms entry. Entries exist in WPForms Pro only; Lite passes entry_id 0.
	 *
	 * @param array $fields    Fields.
	 * @param array $entry     Raw entry.
	 * @param array $form_data Form data.
	 * @param int   $entry_id  Entry ID (0 when not saved).
	 * @return void
	 */
	public function on_wpforms( $fields, $entry, $form_data, $entry_id = 0 ) {
		unset( $fields, $entry );
		if ( empty( $entry_id ) || ! function_exists( 'wpforms' ) ) {
			return;
		}
		$form_id = is_array( $form_data ) && isset( $form_data['id'] ) ? (int) $form_data['id'] : 0;
		$this->write(
			static function ( array $a ) use ( $entry_id, $form_id ) {
				$meta = wpforms()->obj( 'entry_meta' );
				if ( ! $meta ) {
					return;
				}
				$meta->add(
					array(
						'entry_id' => (int) $entry_id,
						'form_id'  => $form_id,
						'user_id'  => 0,
						'type'     => Source_Capture::META_KEY,
						'data'     => wp_json_encode( $a ),
					),
					'entry_meta'
				);
			}
		);
	}
}
