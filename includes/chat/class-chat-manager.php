<?php
/**
 * Chat Manager
 *
 * Handles database operations for AI chat conversations and messages.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Chat;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Manages chat conversations and messages.
 *
 * Provides CRUD access with lightweight caching.
 */
class Chat_Manager {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Cache group for chat data
	 *
	 * @var string
	 */
	private $cache_group = 'specflux_mac';

	/**
	 * Get conversations for a user
	 *
	 * @param int $user_id User ID.
	 * @param int $limit Number of conversations to retrieve.
	 * @param int $offset Offset for pagination.
	 * @return array Array of conversation objects.
	 */
	public function get_conversations( $user_id, $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'specflux_mac_conversations';

		$cache_key = 'conversations_' . $this->get_cache_version() . '_' . (int) $user_id . '_' . (int) $limit . '_' . (int) $offset;
		$cached    = wp_cache_get( $cache_key, $this->cache_group );
		if ( false !== $cached ) {
			return $cached;
		}

		$conversations = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, defined by plugin.
				"SELECT * FROM {$table}
				WHERE user_id = %d
				ORDER BY updated_at DESC
				LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			)
		);

		wp_cache_set( $cache_key, $conversations, $this->cache_group, MINUTE_IN_SECONDS * 5 );

		return $conversations;
	}

	/**
	 * Get a single conversation
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return object|null Conversation object or null if not found.
	 */
	public function get_conversation( $conversation_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'specflux_mac_conversations';

		$cache_key = 'conversation_' . $this->get_cache_version() . '_' . (int) $conversation_id;
		$cached    = wp_cache_get( $cache_key, $this->cache_group );
		if ( false !== $cached ) {
			return $cached;
		}

		$conversation = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, defined by plugin.
				"SELECT * FROM {$table} WHERE id = %d",
				$conversation_id
			)
		);

		if ( $conversation ) {
			wp_cache_set( $cache_key, $conversation, $this->cache_group, MINUTE_IN_SECONDS * 5 );
		}

		return $conversation;
	}

	/**
	 * Create a new conversation
	 *
	 * @param int    $user_id User ID.
	 * @param string $title Conversation title.
	 * @return int|false Conversation ID on success, false on failure.
	 */
	public function create_conversation( $user_id, $title = 'New Conversation' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'specflux_mac_conversations';
		$now   = current_time( 'mysql' );

		$result = $wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'title'      => $title,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( $result ) {
			$this->bump_cache_version();
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Update conversation title
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $title New title.
	 * @return bool True on success, false on failure.
	 */
	public function update_conversation_title( $conversation_id, $title ) {
		global $wpdb;

		$table = $wpdb->prefix . 'specflux_mac_conversations';

		$result = $wpdb->update(
			$table,
			array(
				'title'      => $title,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $conversation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			$this->bump_cache_version();
			return true;
		}

		return false;
	}

	/**
	 * Update conversation updated_at timestamp
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool True on success, false on failure.
	 */
	public function touch_conversation( $conversation_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'specflux_mac_conversations';

		$result = $wpdb->update(
			$table,
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $conversation_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false !== $result ) {
			$this->bump_cache_version();
			return true;
		}

		return false;
	}

	/**
	 * Delete a conversation and all its messages
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_conversation( $conversation_id ) {
		global $wpdb;

		// Delete messages first (no FK cascade in dbDelta tables).
		$messages_table = $wpdb->prefix . 'specflux_mac_messages';
		$wpdb->delete(
			$messages_table,
			array( 'conversation_id' => $conversation_id ),
			array( '%d' )
		);

		$table = $wpdb->prefix . 'specflux_mac_conversations';

		$result = $wpdb->delete(
			$table,
			array( 'id' => $conversation_id ),
			array( '%d' )
		);

		if ( false !== $result ) {
			$this->bump_cache_version();
			return true;
		}

		return false;
	}

	/**
	 * Get messages for a conversation
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $limit Number of messages to retrieve.
	 * @return array Array of message objects.
	 */
	public function get_messages( $conversation_id, $limit = 50 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'specflux_mac_messages';

		$cache_key = 'messages_' . $this->get_cache_version() . '_' . (int) $conversation_id . '_' . (int) $limit;
		$cached    = wp_cache_get( $cache_key, $this->cache_group );
		if ( false !== $cached ) {
			return $cached;
		}

		// Fetch the NEWEST $limit messages (DESC), then restore chronological
		// order in PHP. Selecting oldest-first with a LIMIT would truncate recent
		// history in long conversations — feeding the model a stale prefix that
		// omits the current question and can split tool_use/tool_result pairs.
		// The id tiebreak keeps same-second inserts in insertion order.
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, defined by plugin.
				"SELECT * FROM {$table}
				WHERE conversation_id = %d
				ORDER BY created_at DESC, id DESC
				LIMIT %d",
				$conversation_id,
				$limit
			)
		);

		// Restore chronological (oldest-first) order for display and history.
		$messages = array_reverse( $messages );

		// Parse JSON fields.
		foreach ( $messages as $message ) {
			if ( ! empty( $message->tool_calls ) ) {
				$message->tool_calls = json_decode( $message->tool_calls, true );
			}
			if ( ! empty( $message->metadata ) ) {
				$message->metadata = json_decode( $message->metadata, true );
			}
		}

		wp_cache_set( $cache_key, $messages, $this->cache_group, MINUTE_IN_SECONDS * 5 );

		return $messages;
	}

	/**
	 * Get recent messages shaped for sending to the model.
	 *
	 * Unlike get_messages() (which the transcript view uses unchanged), this
	 * trims the history on turn boundaries so a tool result is never sent
	 * without the assistant tool call that produced it (or the reverse), and
	 * replaces tool results from earlier turns with a short placeholder so
	 * old data does not grow every request. Stored rows are not modified.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $limit           Number of newest messages to consider.
	 * @return array Message objects, oldest first.
	 */
	public function get_messages_for_model( $conversation_id, $limit = 50 ) {
		return $this->prepare_history_for_model( $this->get_messages( $conversation_id, $limit ) );
	}

	/**
	 * Make a message window safe and compact for the model.
	 *
	 * @param array $messages Message objects, oldest first.
	 * @return array Prepared message objects (copies where changed).
	 */
	public function prepare_history_for_model( $messages ) {
		$messages = array_values( $messages );

		// A newest-N window can start mid-turn; begin at the first user message.
		$first_user = null;
		foreach ( $messages as $index => $message ) {
			if ( 'user' === $message->role ) {
				$first_user = $index;
				break;
			}
		}
		if ( null !== $first_user ) {
			$messages = array_slice( $messages, $first_user );
		}

		// Drop tool calls whose results are missing and results whose call is missing.
		$clean   = array();
		$total   = count( $messages );
		$skipped = array();
		for ( $i = 0; $i < $total; $i++ ) {
			if ( isset( $skipped[ $i ] ) ) {
				continue;
			}

			$message = $messages[ $i ];

			if ( 'tool' === $message->role ) {
				// A tool row reaching here has no preceding call in its group.
				continue;
			}

			if ( 'assistant' === $message->role && ! empty( $message->tool_calls ) && is_array( $message->tool_calls ) ) {
				$expected = array();
				foreach ( $message->tool_calls as $tool_call ) {
					$expected[] = (string) ( $tool_call['id'] ?? '' );
				}

				$group = array();
				$found = array();
				for ( $j = $i + 1; $j < $total && 'tool' === $messages[ $j ]->role; $j++ ) {
					$group[ $j ] = $messages[ $j ];
					$found[]     = (string) $messages[ $j ]->tool_call_id;
					$skipped[ $j ] = true;
				}

				if ( array_diff( $expected, $found ) ) {
					// Incomplete call: omit the whole group rather than send a broken pair.
					continue;
				}

				$clean[] = $message;
				foreach ( $group as $tool_row ) {
					if ( in_array( (string) $tool_row->tool_call_id, $expected, true ) ) {
						$clean[] = $tool_row;
					}
				}
				continue;
			}

			$clean[] = $message;
		}

		// Tool results before the latest user message are stale: keep the call, drop the data.
		$last_user = null;
		foreach ( $clean as $index => $message ) {
			if ( 'user' === $message->role ) {
				$last_user = $index;
			}
		}

		if ( null !== $last_user ) {
			foreach ( $clean as $index => $message ) {
				if ( $index < $last_user && 'tool' === $message->role ) {
					$copy          = clone $message;
					$copy->content = '[earlier tool result omitted]';
					$clean[ $index ] = $copy;
				}
			}
		}

		return $clean;
	}

	/**
	 * Add a message to a conversation
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $role Message role ('user', 'assistant', 'system', 'tool').
	 * @param string $content Message content.
	 * @param array  $metadata Optional metadata (model, tokens, etc).
	 * @return int|false Message ID on success, false on failure.
	 */
	public function add_message( $conversation_id, $role, $content, $metadata = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'specflux_mac_messages';

		$data = array(
			'conversation_id' => $conversation_id,
			'role'            => $role,
			'content'         => $content,
			'created_at'      => current_time( 'mysql' ),
		);

		$format = array( '%d', '%s', '%s', '%s' );

		// Add metadata if provided.
		if ( ! empty( $metadata ) ) {
			$data['metadata'] = wp_json_encode( $metadata );
			$format[]         = '%s';
		}

		$result = $wpdb->insert( $table, $data, $format );

		if ( $result ) {
			// Update conversation timestamp.
			$this->touch_conversation( $conversation_id );
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Add a tool use message to a conversation
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param array  $tool_calls Array of tool call objects.
	 * @param string $content Optional text content.
	 * @return int|false Message ID on success, false on failure.
	 */
	public function add_tool_message( $conversation_id, $tool_calls, $content = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'specflux_mac_messages';

		$result = $wpdb->insert(
			$table,
			array(
				'conversation_id' => $conversation_id,
				'role'            => 'assistant',
				'content'         => $content,
				'tool_calls'      => wp_json_encode( $tool_calls ),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( $result ) {
			// Update conversation timestamp.
			$this->touch_conversation( $conversation_id );
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Add a tool result message to a conversation
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $tool_call_id Tool call ID.
	 * @param string $tool_name Tool name.
	 * @param mixed  $result Tool result.
	 * @return int|false Message ID on success, false on failure.
	 */
	public function add_tool_result( $conversation_id, $tool_call_id, $tool_name, $result ) {
		global $wpdb;

		$table = $wpdb->prefix . 'specflux_mac_messages';

		$content = is_string( $result ) ? $result : wp_json_encode( $result );

		$insert_result = $wpdb->insert(
			$table,
			array(
				'conversation_id' => $conversation_id,
				'role'            => 'tool',
				'content'         => $content,
				'tool_call_id'    => $tool_call_id,
				'tool_name'       => $tool_name,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $insert_result ) {
			// Update conversation timestamp.
			$this->touch_conversation( $conversation_id );
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Get message count for a conversation
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return int Number of messages.
	 */
	public function get_message_count( $conversation_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'specflux_mac_messages';

		$cache_key = 'message_count_' . $this->get_cache_version() . '_' . (int) $conversation_id;
		$cached    = wp_cache_get( $cache_key, $this->cache_group );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, defined by plugin.
				"SELECT COUNT(*) FROM {$table} WHERE conversation_id = %d",
				$conversation_id
			)
		);

		wp_cache_set( $cache_key, (int) $count, $this->cache_group, MINUTE_IN_SECONDS * 5 );

		return (int) $count;
	}

	/**
	 * Generate a title from the first message
	 *
	 * @param string $message First message content.
	 * @return string Generated title.
	 */
	public function generate_title_from_message( $message ) {
		// Get first 50 characters or first sentence.
		$title = wp_trim_words( $message, 8, '...' );
		return $title;
	}

	/**
	 * Search conversations by title
	 *
	 * @param int    $user_id User ID.
	 * @param string $search Search query.
	 * @param int    $limit Number of results.
	 * @return array Array of conversation objects.
	 */
	public function search_conversations( $user_id, $search, $limit = 10 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'specflux_mac_conversations';

		$cache_key = 'search_' . $this->get_cache_version() . '_' . (int) $user_id . '_' . md5( $search ) . '_' . (int) $limit;
		$cached    = wp_cache_get( $cache_key, $this->cache_group );
		if ( false !== $cached ) {
			return $cached;
		}

		$conversations = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, defined by plugin.
				"SELECT * FROM {$table}
				WHERE user_id = %d
				AND title LIKE %s
				ORDER BY updated_at DESC
				LIMIT %d",
				$user_id,
				'%' . $wpdb->esc_like( $search ) . '%',
				$limit
			)
		);

		wp_cache_set( $cache_key, $conversations, $this->cache_group, MINUTE_IN_SECONDS * 5 );

		return $conversations;
	}

	/**
	 * Get cache version for chat data.
	 *
	 * @return int Cache version.
	 */
	private function get_cache_version() {
		$version = wp_cache_get( 'version', $this->cache_group );
		if ( false === $version ) {
			$version = 1;
			wp_cache_set( 'version', $version, $this->cache_group );
		}

		return (int) $version;
	}

	/**
	 * Bump cache version to invalidate cached data.
	 *
	 * @return int New cache version.
	 */
	private function bump_cache_version() {
		$version = $this->get_cache_version() + 1;
		wp_cache_set( 'version', $version, $this->cache_group );

		return $version;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
