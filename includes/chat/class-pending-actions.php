<?php
/**
 * Pending chat actions
 *
 * Server-side store for changes the chat proposes but never applies on its
 * own. Records are created by trusted server code (an ability), shown to the
 * user as an Apply button, and only executed when the user clicks it. The
 * model only ever sees the id, label and summary; the payload stays here, so
 * it cannot be forged or altered from the chat.
 *
 * Storage is one transient per action rather than a per-user option: each
 * write touches a single key (no read-modify-write races between two
 * requests), and WordPress handles expiry. Pending actions expire after 24
 * hours; once an action reaches a final status the record is kept longer so a
 * reloaded conversation can still show what happened.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Chat;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Creates, reads and updates pending chat actions
 */
class Pending_Actions {

	/**
	 * Seconds a pending action can still be applied.
	 */
	const PENDING_TTL = DAY_IN_SECONDS;

	/**
	 * Seconds a finished (applied, dismissed, failed) record is kept for display.
	 */
	const FINISHED_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Transient key prefix.
	 */
	const KEY_PREFIX = 'specflux_mac_action_';

	/**
	 * Create a pending action for the current user.
	 *
	 * Keys of $action: type (string, required), label (button text), summary
	 * (one line shown above the button), payload (array, opaque to this
	 * plugin), capability (default 'manage_options'), object_id (int|null,
	 * passed to current_user_can() as the object), conversation_id (int,
	 * optional).
	 *
	 * @param array $action Action definition.
	 * @return array The safe public view: id, type, label, summary. The payload
	 *               is deliberately not included. Empty array when invalid.
	 */
	public static function create( array $action ) {
		$type = isset( $action['type'] ) ? sanitize_key( (string) $action['type'] ) : '';
		if ( '' === $type || ! get_current_user_id() ) {
			return array();
		}

		$id      = wp_generate_password( 20, false );
		$created = time();

		$object_id = isset( $action['object_id'] ) && null !== $action['object_id'] ? absint( $action['object_id'] ) : null;

		$record = array(
			'id'              => $id,
			'type'            => $type,
			'label'           => isset( $action['label'] ) ? sanitize_text_field( (string) $action['label'] ) : '',
			'summary'         => isset( $action['summary'] ) ? sanitize_text_field( (string) $action['summary'] ) : '',
			'payload'         => isset( $action['payload'] ) && is_array( $action['payload'] ) ? $action['payload'] : array(),
			'capability'      => ! empty( $action['capability'] ) ? sanitize_key( (string) $action['capability'] ) : 'manage_options',
			'object_id'       => $object_id,
			'user_id'         => get_current_user_id(),
			'conversation_id' => isset( $action['conversation_id'] ) ? absint( $action['conversation_id'] ) : 0,
			'created'         => $created,
			'expires'         => $created + self::PENDING_TTL,
			'status'          => 'pending',
			'result'          => null,
		);

		if ( '' === $record['label'] ) {
			$record['label'] = __( 'Apply', 'specflux-marketing-analytics-chat' );
		}

		set_transient( self::KEY_PREFIX . $id, $record, self::PENDING_TTL );

		return self::public_view( $record );
	}

	/**
	 * Load an action record.
	 *
	 * @param string $id Action id.
	 * @return array|null Full record, or null when unknown or purged.
	 */
	public static function get( $id ) {
		$id = is_string( $id ) ? preg_replace( '/[^A-Za-z0-9]/', '', $id ) : '';
		if ( '' === $id ) {
			return null;
		}

		$record = get_transient( self::KEY_PREFIX . $id );

		return is_array( $record ) ? $record : null;
	}

	/**
	 * Whether a pending record is past its apply window.
	 *
	 * @param array $record Action record.
	 * @return bool
	 */
	public static function is_expired( array $record ) {
		return 'pending' === ( $record['status'] ?? '' ) && time() > (int) ( $record['expires'] ?? 0 );
	}

	/**
	 * Move an action to a new status.
	 *
	 * @param string $id     Action id.
	 * @param string $status 'applying', 'applied', 'dismissed' or 'failed'.
	 * @param mixed  $result Optional result (handler message or error text).
	 * @return bool False when the record does not exist or the status is unknown.
	 */
	public static function mark( $id, $status, $result = null ) {
		if ( ! in_array( $status, array( 'applying', 'applied', 'dismissed', 'failed' ), true ) ) {
			return false;
		}

		$record = self::get( $id );
		if ( null === $record ) {
			return false;
		}

		$record['status'] = $status;
		$record['result'] = $result;

		// A failed action may be tried again, so it keeps its original window.
		$ttl = in_array( $status, array( 'applied', 'dismissed' ), true ) ? self::FINISHED_TTL : self::PENDING_TTL;

		set_transient( self::KEY_PREFIX . $record['id'], $record, $ttl );

		return true;
	}

	/**
	 * Tie a freshly created action to the conversation that surfaced it.
	 *
	 * Only the creating user's still-pending, unbound action is touched.
	 *
	 * @param string $id              Action id.
	 * @param int    $conversation_id Conversation id.
	 * @param int    $user_id         Current user id.
	 * @return array|null Public view of the action, or null when it is not eligible.
	 */
	public static function bind_to_conversation( $id, $conversation_id, $user_id ) {
		$record = self::get( $id );

		if ( null === $record || (int) $record['user_id'] !== (int) $user_id || 'pending' !== $record['status'] || self::is_expired( $record ) ) {
			return null;
		}

		if ( empty( $record['conversation_id'] ) ) {
			$record['conversation_id'] = (int) $conversation_id;
			set_transient( self::KEY_PREFIX . $record['id'], $record, max( 1, (int) $record['expires'] - time() ) );
		}

		return self::public_view( $record );
	}

	/**
	 * Current display state for a stored action reference.
	 *
	 * Used when a conversation is reloaded. Never reveals another user's action.
	 *
	 * @param string $id      Action id.
	 * @param int    $user_id User viewing the conversation.
	 * @return array status (pending|applied|dismissed|expired) and result.
	 */
	public static function status_for_display( $id, $user_id ) {
		$record = self::get( $id );

		if ( null === $record || (int) $record['user_id'] !== (int) $user_id || self::is_expired( $record ) ) {
			return array(
				'status' => 'expired',
				'result' => null,
			);
		}

		// A failed or in-flight action can still be tried again, so it shows as pending.
		$status = in_array( $record['status'], array( 'applying', 'failed' ), true ) ? 'pending' : $record['status'];

		return array(
			'status' => $status,
			'result' => is_string( $record['result'] ) ? $record['result'] : null,
		);
	}

	/**
	 * The part of a record that is safe to show to the model and the browser.
	 *
	 * @param array $record Action record.
	 * @return array id, type, label, summary.
	 */
	private static function public_view( array $record ) {
		return array(
			'id'      => $record['id'],
			'type'    => $record['type'],
			'label'   => $record['label'],
			'summary' => $record['summary'],
		);
	}
}
