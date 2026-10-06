<?php
/**
 * Chat AJAX Handler
 *
 * Handles AJAX requests for chat interface operations.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Chat;

use Specflux_Marketing_Analytics\Utils\Permission_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Handles chat-related AJAX requests
 */
class Chat_Ajax_Handler {

	/**
	 * Chat manager instance
	 *
	 * @var Chat_Manager
	 */
	private $chat_manager;

	/**
	 * MCP client instance
	 *
	 * @var MCP_Client
	 */
	private $mcp_client;

	/**
	 * LLM provider instance
	 *
	 * @var LLM_Provider_Interface
	 */
	private $llm_provider;

	/**
	 * Proposed actions collected from tool results during the current turn.
	 *
	 * @var array
	 */
	private $turn_actions = array();

	/**
	 * Constructor
	 *
	 * Collaborators are injected so the chat flow can be exercised offline;
	 * each argument defaults to exactly the production collaborator, so no
	 * existing caller needs to change.
	 *
	 * @param Chat_Manager|null           $chat_manager Chat manager instance.
	 * @param MCP_Client|null             $mcp_client   MCP client instance.
	 * @param LLM_Provider_Interface|null $llm_provider LLM provider instance.
	 */
	public function __construct( $chat_manager = null, $mcp_client = null, $llm_provider = null ) {
		$this->chat_manager = null !== $chat_manager ? $chat_manager : new Chat_Manager();
		$this->mcp_client   = null !== $mcp_client ? $mcp_client : new MCP_Client();
		$this->llm_provider = null !== $llm_provider ? $llm_provider : $this->get_llm_provider();
	}

	/**
	 * Register AJAX handlers
	 */
	public function register_handlers() {
		add_action( 'wp_ajax_specflux_mac_create_conversation', array( $this, 'create_conversation' ) );
		add_action( 'wp_ajax_specflux_mac_send_message', array( $this, 'send_message' ) );
		add_action( 'wp_ajax_specflux_mac_retry_tool', array( $this, 'retry_tool_call' ) );
		add_action( 'wp_ajax_specflux_mac_delete_conversation', array( $this, 'delete_conversation' ) );
		add_action( 'wp_ajax_specflux_mac_apply_chat_action', array( $this, 'apply_chat_action' ) );
		add_action( 'wp_ajax_specflux_mac_dismiss_chat_action', array( $this, 'dismiss_chat_action' ) );
	}

	/**
	 * Create a new conversation
	 */
	public function create_conversation() {
		// Verify nonce.
		check_ajax_referer( 'specflux_mac_admin', 'nonce' );

		// Check user permissions.
		if ( ! Permission_Manager::can_access_plugin() ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions', 'specflux-marketing-analytics-chat' ) ),
				403
			);
		}

		// Always own the conversation to the authenticated user; never trust a
		// client-supplied user_id (prevents creating conversations for others).
		$user_id = get_current_user_id();

		// Create conversation.
		$conversation_id = $this->chat_manager->create_conversation( $user_id, 'New Conversation' );

		if ( $conversation_id ) {
			wp_send_json_success(
				array(
					'conversation_id' => $conversation_id,
					'message'         => __( 'Conversation created successfully', 'specflux-marketing-analytics-chat' ),
				)
			);
		} else {
			wp_send_json_error(
				array( 'message' => __( 'Failed to create conversation', 'specflux-marketing-analytics-chat' ) ),
				500
			);
		}
	}

	/**
	 * Send a message and get AI response
	 */
	public function send_message() {
		// Verify nonce.
		check_ajax_referer( 'specflux_mac_admin', 'nonce' );

		// Check user permissions.
		if ( ! Permission_Manager::can_access_plugin() ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions', 'specflux-marketing-analytics-chat' ) ),
				403
			);
		}

		// Get parameters.
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;
		$message         = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		// Validate.
		if ( ! $conversation_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid conversation ID', 'specflux-marketing-analytics-chat' ) ),
				400
			);
		}

		if ( empty( $message ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Message cannot be empty', 'specflux-marketing-analytics-chat' ) ),
				400
			);
		}

		// Verify conversation belongs to user.
		$conversation = $this->chat_manager->get_conversation( $conversation_id );
		if ( ! $conversation || get_current_user_id() !== (int) $conversation->user_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Conversation not found', 'specflux-marketing-analytics-chat' ) ),
				404
			);
		}

		// Add user message to database.
		$user_message_id = $this->chat_manager->add_message(
			$conversation_id,
			'user',
			$message
		);

		if ( ! $user_message_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Failed to save message', 'specflux-marketing-analytics-chat' ) ),
				500
			);
		}

		// Generate conversation title from first message if needed.
		$message_count = $this->chat_manager->get_message_count( $conversation_id );
		$new_title     = null;
		if ( 1 === $message_count ) {
			$new_title = $this->chat_manager->generate_title_from_message( $message );
			$this->chat_manager->update_conversation_title( $conversation_id, $new_title );
		}

		// Get AI response.
		$ai_response_result = $this->get_ai_response( $conversation_id, $message );

		if ( is_wp_error( $ai_response_result ) ) {
			wp_send_json_error(
				array( 'message' => $ai_response_result->get_error_message() ),
				500
			);
		}

		$ai_response = $ai_response_result['content'];
		$tool_calls  = $ai_response_result['tool_calls'] ?? null;
		$usage       = $ai_response_result['usage'] ?? null;

		// Add assistant response to database.
		if ( $tool_calls ) {
			$assistant_message_id = $this->chat_manager->add_tool_message(
				$conversation_id,
				$tool_calls,
				$ai_response
			);
		} else {
			$assistant_message_id = $this->chat_manager->add_message(
				$conversation_id,
				'assistant',
				$ai_response,
				$usage ? array( 'usage' => $usage ) : array()
			);
		}

		if ( ! $assistant_message_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Failed to save AI response', 'specflux-marketing-analytics-chat' ) ),
				500
			);
		}

		// Collect all assistant messages to return to frontend.
		$all_messages = array();

		// Add initial AI response if it has content.
		if ( ! empty( $ai_response ) ) {
			$all_messages[] = array(
				'role'       => 'assistant',
				'content'    => $ai_response,
				'usage'      => $usage,
				'tool_calls' => $tool_calls,
			);
		}

		// Track failed tools for retry functionality.
		$failed_tools = array();
		$actions      = array();

		// If there were tool calls, execute them and get a final response.
		if ( $tool_calls ) {
			$final_response = $this->handle_tool_calls( $conversation_id, $tool_calls, $usage, $ai_response_result['tools_offered'] ?? array() );
			if ( ! is_wp_error( $final_response ) ) {
				// Any interim assistant text from intermediate tool rounds.
				foreach ( $final_response['interim_messages'] ?? array() as $interim_message ) {
					$all_messages[] = $interim_message;
				}
				// Add the final response as a separate message.
				$actions       = $final_response['actions'] ?? array();
				$final_message = array(
					'role'    => 'assistant',
					'content' => $final_response['content'],
					'usage'   => $final_response['usage'],
				);
				if ( $actions ) {
					$final_message['actions'] = $actions;
				}
				$all_messages[] = $final_message;
				// Update main response vars for backward compatibility.
				$ai_response = $final_response['content'];
				$usage       = $final_response['usage'];
				// Track any tools that failed during execution.
				$failed_tools = $final_response['failed_tools'] ?? array();
			} else {
				// Tool execution failed - add error message so frontend can display it.
				$error_content = sprintf(
					/* translators: %s: Error message */
					__( 'I tried to use tools to answer your question, but encountered an error: %s', 'specflux-marketing-analytics-chat' ),
					$final_response->get_error_message()
				);
				$all_messages[] = array(
					'role'     => 'assistant',
					'content'  => $error_content,
					'is_error' => true,
				);
				$ai_response    = $error_content;
			}
		}

		// Build failed tools list for the error message (if any tools failed).
		$failed_tools_for_response = array();
		if ( ! empty( $failed_tools ) ) {
			foreach ( $failed_tools as $ft ) {
				$failed_tools_for_response[] = array(
					'name'      => $ft['name'],
					'error'     => $ft['error'],
					'arguments' => $ft['arguments'],
				);
			}
		}

		// Return success with AI response(s).
		wp_send_json_success(
			array(
				'content'       => $ai_response, // Final/main content for backward compat.
				'messages'      => $all_messages, // All messages for proper UI update.
				'new_title'     => $new_title,
				'usage'         => $usage,
				'tool_metadata' => $ai_response_result['tool_metadata'] ?? null,
				'failed_tools'  => $failed_tools_for_response, // Tools that failed for retry.
				'actions'       => $actions, // Proposed actions (id, type, label, summary) awaiting a click.
				'message'       => __( 'Message sent successfully', 'specflux-marketing-analytics-chat' ),
			)
		);
	}

	/**
	 * Get LLM provider instance
	 *
	 * Chat is served exclusively by the WordPress core AI Client, so the model
	 * provider and its credentials are chosen once at the site level under
	 * Settings > Connectors rather than being configured per plugin.
	 *
	 * @return LLM_Provider_Interface|null LLM provider or null if not configured.
	 */
	private function get_llm_provider() {
		$settings = get_option( 'specflux_mac_settings', array() );

		return new WP_AI_Provider(
			array(
				'temperature' => $settings['ai_temperature'] ?? 0.7,
				'max_tokens'  => $settings['ai_max_tokens'] ?? 4096,
			)
		);
	}

	/**
	 * Filter MCP tools based on settings
	 *
	 * @param array $tools All available MCP tools.
	 * @return array Filtered tools.
	 */
	private function filter_tools( $tools ) {
		$settings = get_option( 'specflux_mac_settings', array() );

		// Get enabled tool categories (default: all enabled).
		$enabled_categories = $settings['enabled_tool_categories'] ?? array( 'all' );

		// If "all" is selected, return all tools.
		if ( in_array( 'all', $enabled_categories, true ) ) {
			return $tools;
		}

		$category_map = self::get_tool_category_map();

		$filtered = array();
		foreach ( $tools as $tool ) {
			$short_name = self::normalize_ability_name( $tool['name'] );
			$category   = $category_map[ $short_name ] ?? null;

			// Cross-platform tools (and any unmapped tool, e.g. a premium add-on
			// ability) are base capabilities the chat always keeps; a specific
			// category selection only ever hides the other platforms' tools.
			if ( null === $category || 'cross-platform' === $category ) {
				$filtered[] = $tool;
				continue;
			}

			if ( in_array( $category, $enabled_categories, true ) ) {
				$filtered[] = $tool;
			}
		}

		return $filtered;
	}

	/**
	 * Reduce an ability identifier to its short name for category lookup.
	 *
	 * Ability names arrive namespaced ('marketing-analytics/get-ga4-metrics')
	 * and some providers sanitize them to the core prefix form
	 * ('wpab__marketing-analytics__get-ga4-metrics'); both reduce to the final
	 * segment ('get-ga4-metrics').
	 *
	 * This is the source of truth for the rule; admin/views/chat.php calls it
	 * directly and admin/js/chat-interface.js mirrors it in
	 * ChatInterface.normalizeAbilityName().
	 *
	 * @param string $name Raw ability/tool name.
	 * @return string Short ability name.
	 */
	public static function normalize_ability_name( $name ) {
		$name = (string) $name;

		if ( false !== strpos( $name, '__' ) ) {
			$parts = explode( '__', $name );
			$name  = (string) end( $parts );
		}

		if ( false !== strpos( $name, '/' ) ) {
			$parts = explode( '/', $name );
			$name  = (string) end( $parts );
		}

		return $name;
	}

	/**
	 * Explicit ability -> tool-category map.
	 *
	 * Ability short names do not reliably contain their platform token (e.g.
	 * 'get-search-performance' is a GSC tool), so category membership is mapped
	 * explicitly rather than inferred from a prefix. Cross-platform abilities
	 * are always available regardless of the selected categories.
	 *
	 * @return array<string,string> Short ability name => category slug.
	 */
	private static function get_tool_category_map() {
		return array(
			// GA4.
			'ga4-overview'             => 'ga4',
			'get-ga4-events'           => 'ga4',
			'get-ga4-metrics'          => 'ga4',
			'get-ga4-realtime'         => 'ga4',
			'get-traffic-sources'      => 'ga4',
			// Google Search Console.
			'get-indexing-status'      => 'gsc',
			'get-search-performance'   => 'gsc',
			'get-top-queries'          => 'gsc',
			'gsc-overview'             => 'gsc',
			// Microsoft Clarity.
			'analyze-clarity-heatmaps' => 'clarity',
			'clarity-dashboard'        => 'clarity',
			'get-clarity-insights'     => 'clarity',
			'get-clarity-recordings'   => 'clarity',
			// Cross-platform (always available).
			'compare-periods'          => 'cross-platform',
			'generate-summary-report'  => 'cross-platform',
			'get-top-content'          => 'cross-platform',
		);
	}

	/**
	 * Map stored message rows into the LLM provider message format.
	 *
	 * @param array $history_messages Message rows from the chat manager.
	 * @return array Messages formatted for the LLM provider.
	 */
	private function format_messages_for_llm( $history_messages ) {
		$formatted_messages = array();

		foreach ( $history_messages as $msg ) {
			$formatted_messages[] = array(
				'role'         => $msg->role,
				'content'      => $msg->content,
				'tool_calls'   => ! empty( $msg->tool_calls ) ? $msg->tool_calls : null,
				'tool_call_id' => $msg->tool_call_id ?? null,
				'tool_name'    => $msg->tool_name ?? null,
			);
		}

		return $formatted_messages;
	}

	/**
	 * Get AI response for a message
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $message User message.
	 * @return array|WP_Error Response array or WP_Error.
	 */
	private function get_ai_response( $conversation_id, $message ) {
		// Check if provider is configured.
		if ( ! $this->llm_provider || ! $this->llm_provider->is_configured() ) {
			return new \WP_Error(
				'provider_not_configured',
				__( 'AI provider is not configured. Please configure your API key in Settings.', 'specflux-marketing-analytics-chat' )
			);
		}

		// Get conversation history.
		$formatted_messages = $this->format_messages_for_llm(
			$this->chat_manager->get_messages_for_model( $conversation_id )
		);

		// Get available MCP tools.
		$mcp_tools = $this->mcp_client->list_tools();
		if ( is_wp_error( $mcp_tools ) ) {
			$mcp_tools = array(); // Fallback to no tools if MCP server is unavailable.
		}

		// Filter tools based on settings.
		$filtered_tools = $this->filter_tools( $mcp_tools );

		// Send message to LLM.
		$response = $this->llm_provider->send_message( $formatted_messages, $filtered_tools, $this->get_provider_options() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Tools to keep offering the model across follow-up tool rounds.
		$response['tools_offered'] = $filtered_tools;

		// Add tool metadata to response.
		$response['tool_metadata'] = array(
			'total_available' => count( $mcp_tools ),
			'tools_sent'      => count( $filtered_tools ),
			'filtered'        => count( $mcp_tools ) !== count( $filtered_tools ),
		);

		return $response;
	}

	/**
	 * Handle tool calls from AI
	 *
	 * Executes the requested tools, feeds the results back, and keeps going for
	 * as long as the model asks for more tools, up to a capped number of
	 * follow-up rounds. The final allowed round is sent without tools so the
	 * model has to answer.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tool_calls Tool calls from AI.
	 * @param array $initial_usage Initial token usage from tool_use request.
	 * @param array $tools Tools to keep offering the model between rounds.
	 * @return array|WP_Error Array with 'content', 'usage', 'failed_tools' and 'interim_messages', or WP_Error.
	 */
	private function handle_tool_calls( $conversation_id, $tool_calls, $initial_usage = array(), $tools = array() ) {
		$failed_tools      = array();
		$interim_messages  = array();
		$this->turn_actions = array();
		$cumulative_usage = is_array( $initial_usage ) ? $initial_usage : array();

		/**
		 * Filters how many follow-up model calls a single chat turn may make.
		 *
		 * The last allowed call is made without tools, so the model must answer.
		 *
		 * @param int $max_rounds Maximum rounds. Default 5.
		 */
		$max_rounds = max( 1, (int) apply_filters( 'specflux_mac_chat_max_tool_rounds', 5 ) );

		/**
		 * Filters how many seconds a chat turn may spend before the model must answer.
		 *
		 * Each round is a model call plus live API calls; many hosts stop PHP at
		 * 30 to 60 seconds, which the user would see as a generic AI error.
		 *
		 * The final answer call still runs after the budget, so the default leaves
		 * room for it inside a 60-second gateway timeout.
		 *
		 * @param int $budget Seconds. Default 25.
		 */
		$time_budget = (float) apply_filters( 'specflux_mac_chat_time_budget', 25 );
		$started     = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( (int) $time_budget + 60 );
		}

		for ( $round = 1; $round <= $max_rounds; $round++ ) {
			$failed_tools = array_merge( $failed_tools, $this->execute_tool_calls( $conversation_id, $tool_calls ) );

			// Get updated conversation history with tool results.
			$formatted_messages = $this->format_messages_for_llm(
				$this->chat_manager->get_messages_for_model( $conversation_id )
			);

			// The last allowed round, or a turn out of time, gets no tools, forcing a written answer.
			$out_of_time = ( microtime( true ) - $started ) > $time_budget;
			$round_tools = ( $round < $max_rounds && ! $out_of_time ) ? $tools : array();

			$response = $this->llm_provider->send_message( $formatted_messages, $round_tools, $this->get_provider_options() );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$cumulative_usage = $this->add_usage( $cumulative_usage, $response['usage'] ?? array() );

			// More tools requested and rounds remain: persist and loop.
			if ( ! empty( $response['tool_calls'] ) && ! empty( $round_tools ) ) {
				$tool_calls = $response['tool_calls'];

				// add_tool_message stores the interim text with the calls, so
				// it must not also be saved through add_message.
				$this->chat_manager->add_tool_message(
					$conversation_id,
					$tool_calls,
					$response['content'] ?? ''
				);

				if ( ! empty( $response['content'] ) ) {
					$interim_messages[] = array(
						'role'       => 'assistant',
						'content'    => $response['content'],
						'usage'      => $response['usage'] ?? null,
						'tool_calls' => $tool_calls,
					);
				}

				continue;
			}

			if ( '' === trim( (string) ( $response['content'] ?? '' ) ) ) {
				$response['content'] = __( 'I gathered the data but could not finish the answer. Please ask again, or narrow the question to one platform or period.', 'specflux-marketing-analytics-chat' );
			}

			// Save final response with cumulative usage.
			$metadata = $cumulative_usage ? array( 'usage' => $cumulative_usage ) : array();
			if ( $this->turn_actions ) {
				$metadata['actions'] = $this->turn_actions;
			}

			$this->chat_manager->add_message(
				$conversation_id,
				'assistant',
				$response['content'],
				$metadata
			);

			return array(
				'content'          => $response['content'],
				'usage'            => $cumulative_usage,
				'failed_tools'     => $failed_tools,
				'interim_messages' => $interim_messages,
				'actions'          => $this->turn_actions,
			);
		}

		// Unreachable: the last round never continues. Satisfies static analysis.
		return new \WP_Error( 'tool_loop_exhausted', __( 'The tool loop ended without an answer.', 'specflux-marketing-analytics-chat' ) );
	}

	/**
	 * Execute one batch of tool calls and persist their results.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tool_calls Tool calls from the model.
	 * @return array Failed tool entries (name, id, error, arguments).
	 */
	private function execute_tool_calls( $conversation_id, $tool_calls ) {
		$failed_tools = array();

		foreach ( $tool_calls as $tool_call ) {
			$tool_name = $tool_call['name'];
			$arguments = $tool_call['arguments'] ?? array();

			// Call the MCP tool.
			$result = $this->mcp_client->call_tool( $tool_name, $arguments );

			if ( is_wp_error( $result ) ) {
				$result_content = 'Error: ' . $result->get_error_message();
				// Track failed tools for reporting.
				$failed_tools[] = array(
					'name'      => $tool_name,
					'id'        => $tool_call['id'],
					'error'     => $result->get_error_message(),
					'arguments' => $arguments,
				);
			} else {
				$result_content = $this->mcp_client->format_tool_result( $tool_name, $result );

				if ( empty( $result['isError'] ) ) {
					$this->collect_proposed_actions( $conversation_id, $result );
				}

				// An ability can report failure in-band with isError instead of a WP_Error.
				if ( ! empty( $result['isError'] ) ) {
					$failed_tools[] = array(
						'name'      => $tool_name,
						'id'        => $tool_call['id'],
						'error'     => $this->extract_tool_error( $result ),
						'arguments' => $arguments,
					);
				}
			}

			// Save tool result to database.
			$this->chat_manager->add_tool_result(
				$conversation_id,
				$tool_call['id'],
				$tool_name,
				$result_content
			);
		}

		return $failed_tools;
	}

	/**
	 * Collect proposed actions from a successful tool result.
	 *
	 * An ability proposes a change by returning a top-level `proposed_action`
	 * (the output of Pending_Actions::create()) in its JSON result. Only the id
	 * is trusted: label, summary and type are re-read from the server-side
	 * record, which must belong to the current user and still be pending, so a
	 * tool result cannot make the chat show a button for something that was
	 * never really proposed.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param array $result          MCP tool result.
	 */
	private function collect_proposed_actions( $conversation_id, $result ) {
		foreach ( (array) ( $result['content'] ?? array() ) as $item ) {
			if ( ! isset( $item['text'] ) || ! is_string( $item['text'] ) ) {
				continue;
			}

			$data = json_decode( $item['text'], true );
			if ( ! is_array( $data ) || empty( $data['proposed_action']['id'] ) || ! is_string( $data['proposed_action']['id'] ) ) {
				continue;
			}

			$action = Pending_Actions::bind_to_conversation( $data['proposed_action']['id'], $conversation_id, get_current_user_id() );
			if ( $action ) {
				$this->turn_actions[] = $action;
			}
		}
	}

	/**
	 * Common checks for the action endpoints, returning the action record.
	 *
	 * Sends a JSON error and returns null when the request is not allowed.
	 *
	 * @return array|null Action record, or null after an error response.
	 */
	private function load_action_for_request() {
		check_ajax_referer( 'specflux_mac_admin', 'nonce' );

		if ( ! Permission_Manager::can_access_plugin() ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions', 'specflux-marketing-analytics-chat' ) ),
				403
			);
			return null;
		}

		$action_id = isset( $_POST['action_id'] ) ? sanitize_text_field( wp_unslash( $_POST['action_id'] ) ) : '';
		$record    = Pending_Actions::get( $action_id );

		// An unknown id and another user's id look identical on purpose.
		if ( null === $record || get_current_user_id() !== (int) $record['user_id'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'This action is no longer available.', 'specflux-marketing-analytics-chat' ),
					'status'  => 'expired',
				),
				404
			);
			return null;
		}

		return $record;
	}

	/**
	 * Apply a proposed chat action after the user clicks Apply.
	 *
	 * The change itself is made by a handler Pro (or another add-on) hooks onto
	 * specflux_mac_apply_chat_action_{type}; this plugin registers none.
	 */
	public function apply_chat_action() {
		$record = $this->load_action_for_request();
		if ( null === $record ) {
			return;
		}

		if ( 'applied' === $record['status'] || 'applying' === $record['status'] ) {
			wp_send_json_error(
				array(
					'message' => 'applied' === $record['status']
						? __( 'This action was already applied.', 'specflux-marketing-analytics-chat' )
						: __( 'This action is already being applied.', 'specflux-marketing-analytics-chat' ),
					'status'  => $record['status'],
				),
				409
			);
			return;
		}

		if ( 'dismissed' === $record['status'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'This action was dismissed.', 'specflux-marketing-analytics-chat' ),
					'status'  => 'dismissed',
				),
				409
			);
			return;
		}

		if ( Pending_Actions::is_expired( $record ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This action has expired. Ask again to get a fresh proposal.', 'specflux-marketing-analytics-chat' ),
					'status'  => 'expired',
				),
				410
			);
			return;
		}

		$can = null === $record['object_id']
			? current_user_can( $record['capability'] )
			: current_user_can( $record['capability'], (int) $record['object_id'] );

		if ( ! $can ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to apply this change.', 'specflux-marketing-analytics-chat' ) ),
				403
			);
			return;
		}

		// Claim the action before running the handler so a double click cannot apply twice.
		Pending_Actions::mark( $record['id'], 'applying' );

		/**
		 * Applies a proposed chat action.
		 *
		 * Return null (the default) when no add-on handles this type, a WP_Error
		 * to report a failure, or true or an array with an optional 'message'
		 * on success. Permission, ownership, expiry and single-use checks have
		 * already passed; the payload is the one stored when the action was
		 * created, never anything sent by the browser.
		 *
		 * @param array|bool|\WP_Error|null $result  Handler result. Default null.
		 * @param array                     $payload Payload stored with the action.
		 * @param array                     $record  Full action record (id, type, object_id, user_id, ...).
		 */
		$result = apply_filters( 'specflux_mac_apply_chat_action_' . $record['type'], null, $record['payload'], $record );

		if ( null === $result ) {
			Pending_Actions::mark( $record['id'], 'failed', __( 'No handler is available for this action. Is the Pro add-on active?', 'specflux-marketing-analytics-chat' ) );
			wp_send_json_error(
				array( 'message' => __( 'No handler is available for this action. Is the Pro add-on active?', 'specflux-marketing-analytics-chat' ) ),
				501
			);
			return;
		}

		if ( is_wp_error( $result ) ) {
			Pending_Actions::mark( $record['id'], 'failed', $result->get_error_message() );
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			return;
		}

		$message = is_array( $result ) && ! empty( $result['message'] ) && is_string( $result['message'] )
			? sanitize_text_field( $result['message'] )
			: '';

		Pending_Actions::mark( $record['id'], 'applied', $message );

		wp_send_json_success(
			array(
				'status'  => 'applied',
				'message' => '' !== $message ? $message : __( 'Applied.', 'specflux-marketing-analytics-chat' ),
			)
		);
	}

	/**
	 * Dismiss a proposed chat action.
	 */
	public function dismiss_chat_action() {
		$record = $this->load_action_for_request();
		if ( null === $record ) {
			return;
		}

		if ( 'applied' === $record['status'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'This action was already applied.', 'specflux-marketing-analytics-chat' ),
					'status'  => 'applied',
				),
				409
			);
			return;
		}

		Pending_Actions::mark( $record['id'], 'dismissed' );

		wp_send_json_success(
			array(
				'status'  => 'dismissed',
				'message' => __( 'Dismissed.', 'specflux-marketing-analytics-chat' ),
			)
		);
	}

	/**
	 * Add two token usage arrays together.
	 *
	 * @param array $total Running total.
	 * @param array $extra Usage to add.
	 * @return array Combined usage, or the running total when there is nothing to add.
	 */
	private function add_usage( $total, $extra ) {
		if ( empty( $extra ) || ! is_array( $extra ) ) {
			return $total;
		}

		if ( empty( $total ) ) {
			return $extra;
		}

		return array(
			'input_tokens'  => ( $total['input_tokens'] ?? 0 ) + ( $extra['input_tokens'] ?? 0 ),
			'output_tokens' => ( $total['output_tokens'] ?? 0 ) + ( $extra['output_tokens'] ?? 0 ),
		);
	}

	/**
	 * Provider options carrying the chat system prompt.
	 *
	 * @return array Options passed to every send_message() call.
	 */
	private function get_provider_options() {
		return array(
			'system' => ( new System_Prompt() )->build(),
		);
	}

	/**
	 * Pull a readable error message out of an isError tool result.
	 *
	 * @param array $result Tool result carrying isError.
	 * @return string Error message with any "Error: " prefix removed.
	 */
	private function extract_tool_error( $result ) {
		$message = '';

		foreach ( (array) ( $result['content'] ?? array() ) as $item ) {
			if ( isset( $item['text'] ) && is_string( $item['text'] ) ) {
				$message .= $item['text'];
			}
		}

		$message = trim( preg_replace( '/^Error:\s*/', '', $message ) );

		return '' !== $message ? $message : __( 'The tool reported an error.', 'specflux-marketing-analytics-chat' );
	}

	/**
	 * Retry failed tool calls
	 */
	public function retry_tool_call() {
		// Verify nonce.
		check_ajax_referer( 'specflux_mac_admin', 'nonce' );

		// Check user permissions.
		if ( ! Permission_Manager::can_access_plugin() ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions', 'specflux-marketing-analytics-chat' ) ),
				403
			);
		}

		// Get parameters.
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;
		$tool_name       = isset( $_POST['tool_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tool_name'] ) ) : '';
		$tool_arguments  = array();
		$tool_arguments_raw = isset( $_POST['tool_arguments'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tool_arguments'] ) ) : '';
		if ( '' !== $tool_arguments_raw ) {
			$decoded_arguments = json_decode( $tool_arguments_raw, true );
			if ( is_array( $decoded_arguments ) ) {
				$tool_arguments = $this->sanitize_tool_arguments( $decoded_arguments );
			}
		}

		// Validate.
		if ( ! $conversation_id || empty( $tool_name ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid parameters', 'specflux-marketing-analytics-chat' ) ),
				400
			);
		}

		// Execute the tool.
		$result = $this->mcp_client->call_tool( $tool_name, $tool_arguments );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'tool'    => $tool_name,
				)
			);
		}

		if ( ! empty( $result['isError'] ) ) {
			wp_send_json_error(
				array(
					'message' => $this->extract_tool_error( $result ),
					'tool'    => $tool_name,
				)
			);
		}

		// Format the result.
		$formatted_result = $this->mcp_client->format_tool_result( $tool_name, $result );

		// Get AI to interpret the result.
		$interpretation = $this->get_tool_result_interpretation( $formatted_result, $tool_name );

		wp_send_json_success(
			array(
				'content'    => $interpretation,
				'raw_result' => $formatted_result,
				'tool'       => $tool_name,
			)
		);
	}

	/**
	 * Get AI interpretation of tool result
	 *
	 * @param string $result Tool result.
	 * @param string $tool_name Tool name.
	 * @return string AI interpretation.
	 */
	private function get_tool_result_interpretation( $result, $tool_name ) {
		if ( ! $this->llm_provider || ! $this->llm_provider->is_configured() ) {
			// Return raw result if no AI available.
			return $result;
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => sprintf(
					"Here's the result from the %s tool. Please provide a brief, helpful summary:\n\n%s",
					$tool_name,
					$result
				),
			),
		);

		$response = $this->llm_provider->send_message( $messages, array(), $this->get_provider_options() );

		if ( is_wp_error( $response ) ) {
			return $result;
		}

		return $response['content'] ?? $result;
	}

	/**
	 * Recursively sanitize tool arguments.
	 *
	 * @param mixed $value Tool arguments value.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_tool_arguments( $value ) {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				$sanitized_key               = is_string( $key ) ? sanitize_key( $key ) : $key;
				$sanitized[ $sanitized_key ] = $this->sanitize_tool_arguments( $item );
			}
			return $sanitized;
		}

		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		return $value;
	}

	/**
	 * Delete a conversation
	 */
	public function delete_conversation() {
		// Verify nonce.
		check_ajax_referer( 'specflux_mac_admin', 'nonce' );

		// Check user permissions.
		if ( ! Permission_Manager::can_access_plugin() ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions', 'specflux-marketing-analytics-chat' ) ),
				403
			);
		}

		// Get conversation ID.
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0;

		// Validate.
		if ( ! $conversation_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid conversation ID', 'specflux-marketing-analytics-chat' ) ),
				400
			);
		}

		// Verify conversation belongs to user.
		$conversation = $this->chat_manager->get_conversation( $conversation_id );
		if ( ! $conversation || get_current_user_id() !== (int) $conversation->user_id ) {
			wp_send_json_error(
				array( 'message' => __( 'Conversation not found or access denied', 'specflux-marketing-analytics-chat' ) ),
				404
			);
		}

		// Delete conversation.
		$deleted = $this->chat_manager->delete_conversation( $conversation_id );

		if ( $deleted ) {
			wp_send_json_success(
				array(
					'message' => __( 'Conversation deleted successfully', 'specflux-marketing-analytics-chat' ),
				)
			);
		} else {
			wp_send_json_error(
				array( 'message' => __( 'Failed to delete conversation', 'specflux-marketing-analytics-chat' ) ),
				500
			);
		}
	}
}
