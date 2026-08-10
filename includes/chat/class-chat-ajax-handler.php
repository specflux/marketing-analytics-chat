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

		// If there were tool calls, execute them and get a final response.
		if ( $tool_calls ) {
			$final_response = $this->handle_tool_calls( $conversation_id, $tool_calls, $usage );
			if ( ! is_wp_error( $final_response ) ) {
				// Add the final response as a separate message.
				$all_messages[] = array(
					'role'    => 'assistant',
					'content' => $final_response['content'],
					'usage'   => $final_response['usage'],
				);
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
			$this->chat_manager->get_messages( $conversation_id )
		);

		// Get available MCP tools.
		$mcp_tools = $this->mcp_client->list_tools();
		if ( is_wp_error( $mcp_tools ) ) {
			$mcp_tools = array(); // Fallback to no tools if MCP server is unavailable.
		}

		// Filter tools based on settings.
		$filtered_tools = $this->filter_tools( $mcp_tools );

		// Send message to LLM.
		$response = $this->llm_provider->send_message( $formatted_messages, $filtered_tools );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

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
	 * @param int   $conversation_id Conversation ID.
	 * @param array $tool_calls Tool calls from AI.
	 * @param array $initial_usage Initial token usage from tool_use request.
	 * @return array|WP_Error Array with 'content', 'usage', and 'failed_tools', or WP_Error.
	 */
	private function handle_tool_calls( $conversation_id, $tool_calls, $initial_usage = array() ) {
		$tool_results = array();
		$failed_tools = array();

		// Execute each tool call.
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
			}

			// Save tool result to database.
			$this->chat_manager->add_tool_result(
				$conversation_id,
				$tool_call['id'],
				$tool_name,
				$result_content
			);

			$tool_results[] = array(
				'role'         => 'tool',
				'content'      => $result_content,
				'tool_call_id' => $tool_call['id'],
			);
		}

		// Get updated conversation history with tool results.
		$formatted_messages = $this->format_messages_for_llm(
			$this->chat_manager->get_messages( $conversation_id )
		);

		// Get final response from AI with tool results.
		$final_response = $this->llm_provider->send_message( $formatted_messages, array() );

		if ( is_wp_error( $final_response ) ) {
			return $final_response;
		}

		// Accumulate token usage from both API calls.
		$cumulative_usage = array();
		if ( ! empty( $initial_usage ) && ! empty( $final_response['usage'] ) ) {
			$cumulative_usage = array(
				'input_tokens'  => ( $initial_usage['input_tokens'] ?? 0 ) + ( $final_response['usage']['input_tokens'] ?? 0 ),
				'output_tokens' => ( $initial_usage['output_tokens'] ?? 0 ) + ( $final_response['usage']['output_tokens'] ?? 0 ),
			);
		} elseif ( ! empty( $final_response['usage'] ) ) {
			$cumulative_usage = $final_response['usage'];
		} elseif ( ! empty( $initial_usage ) ) {
			$cumulative_usage = $initial_usage;
		}

		// Save final response with cumulative usage.
		$this->chat_manager->add_message(
			$conversation_id,
			'assistant',
			$final_response['content'],
			$cumulative_usage ? array( 'usage' => $cumulative_usage ) : array()
		);

		return array(
			'content'      => $final_response['content'],
			'usage'        => $cumulative_usage,
			'failed_tools' => $failed_tools,
		);
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

		$response = $this->llm_provider->send_message( $messages, array() );

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
