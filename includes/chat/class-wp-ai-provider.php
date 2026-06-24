<?php
/**
 * WordPress AI Client Provider
 *
 * LLM provider backed by the WordPress core AI Client (WordPress 7.0+).
 * Requests are routed to whichever AI provider the site has configured
 * under Settings > Connectors, so no API key is stored in this plugin.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Chat;

use WP_Error;
use Specflux_Marketing_Analytics\Utils\Logger;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * WordPress core AI Client provider
 */
class WP_AI_Provider implements LLM_Provider_Interface {

	/**
	 * Function name prefix for abilities, matching the core AI Client convention.
	 */
	const FUNCTION_PREFIX = 'wpab__';

	/**
	 * Maximum tokens for response
	 *
	 * @var int
	 */
	private $max_tokens;

	/**
	 * Temperature for response generation
	 *
	 * @var float
	 */
	private $temperature;

	/**
	 * Constructor
	 *
	 * @param array $config Configuration array (temperature, max_tokens).
	 */
	public function __construct( $config = array() ) {
		$this->max_tokens  = $config['max_tokens'] ?? 4096;
		$this->temperature = $config['temperature'] ?? 0.7;
	}

	/**
	 * Get provider name
	 *
	 * @return string Provider name.
	 */
	public function get_name() {
		return 'wp-ai';
	}

	/**
	 * Get provider display name
	 *
	 * @return string Provider display name.
	 */
	public function get_display_name() {
		return __( 'WordPress AI (Core)', 'specflux-marketing-analytics-chat' );
	}

	/**
	 * Check if provider is configured
	 *
	 * The core AI Client is considered configured when at least one connected
	 * provider supports text generation.
	 *
	 * @return bool True if the core AI Client can generate text.
	 */
	public function is_configured() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		try {
			return (bool) wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
		} catch ( \Throwable $e ) {
			Logger::debug( 'WP AI Client: availability check failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get configuration errors
	 *
	 * @return array Array of error messages if not configured.
	 */
	public function get_configuration_errors() {
		$errors = array();

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$errors[] = __( 'The WordPress AI Client is not available. WordPress 7.0 or higher is required.', 'specflux-marketing-analytics-chat' );
		} elseif ( ! $this->is_configured() ) {
			$errors[] = __( 'No AI provider is connected in WordPress. Add a provider API key under Settings > Connectors.', 'specflux-marketing-analytics-chat' );
		}

		return $errors;
	}

	/**
	 * Send a message through the WordPress core AI Client
	 *
	 * @param array $messages Conversation history.
	 * @param array $tools Available MCP tools.
	 * @param array $options Additional options.
	 * @return array|WP_Error Response or WP_Error.
	 */
	public function send_message( $messages, $tools = array(), $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'provider_not_configured',
				__( 'The WordPress AI Client is not configured. Add a provider under Settings > Connectors.', 'specflux-marketing-analytics-chat' )
			);
		}

		$system = ! empty( $options['system'] ) ? $options['system'] : $this->get_default_system_message();

		try {
			$builder = wp_ai_client_prompt( $this->format_messages( $messages ) )
				->using_system_instruction( $system )
				->using_max_tokens( (int) ( $options['max_tokens'] ?? $this->max_tokens ) );

			/*
			 * Temperature is opt-in for the WordPress AI provider. The model is
			 * chosen by the site under Settings > Connectors, and some models
			 * (e.g. OpenAI reasoning models) reject a custom temperature and
			 * fail the entire request. Default to letting the model decide; let
			 * advanced sites override via filter.
			 */
			$temperature = apply_filters( 'specflux_mac_wp_ai_temperature', null, $options['temperature'] ?? $this->temperature );
			if ( null !== $temperature ) {
				$builder = $builder->using_temperature( (float) $temperature );
			}

			$declarations = $this->convert_tools_format( $tools );
			if ( ! empty( $declarations ) ) {
				$builder = $builder->using_function_declarations( ...$declarations );
			}

			$result = $builder->generate_text_result();
		} catch ( \Throwable $e ) {
			Logger::debug( 'WP AI Client: request failed: ' . $e->getMessage() );
			return new WP_Error(
				'api_request_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'WordPress AI Client request failed: %s', 'specflux-marketing-analytics-chat' ),
					$e->getMessage()
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->parse_result( $result );
	}

	/**
	 * Convert plugin message history to AI Client message objects
	 *
	 * @param array $messages Raw messages.
	 * @return array Array of Message DTOs.
	 */
	private function format_messages( $messages ) {
		$formatted = array();

		foreach ( $messages as $message ) {
			// System messages are handled via using_system_instruction().
			if ( 'system' === $message['role'] ) {
				continue;
			}

			// Tool results are sent back as function responses in a user message.
			if ( 'tool' === $message['role'] ) {
				$formatted[] = new UserMessage(
					array(
						new MessagePart(
							new FunctionResponse(
								$message['tool_call_id'] ?? 'unknown',
								null,
								$message['content']
							)
						),
					)
				);
				continue;
			}

			if ( 'assistant' === $message['role'] ) {
				$parts = array();

				if ( ! empty( $message['content'] ) ) {
					$parts[] = new MessagePart( (string) $message['content'] );
				}

				// Replay tool calls the assistant made earlier in the conversation.
				if ( ! empty( $message['tool_calls'] ) ) {
					foreach ( $message['tool_calls'] as $tool_call ) {
						$arguments = $tool_call['arguments'] ?? $tool_call['input'] ?? array();

						$parts[] = new MessagePart(
							new FunctionCall(
								$tool_call['id'] ?? null,
								$this->convert_tool_name_from_mcp( $tool_call['name'] ),
								is_array( $arguments ) || is_object( $arguments ) ? (array) $arguments : array()
							)
						);
					}
				}

				if ( ! empty( $parts ) ) {
					$formatted[] = new ModelMessage( $parts );
				}
				continue;
			}

			// Regular user message.
			$formatted[] = new UserMessage( array( new MessagePart( (string) $message['content'] ) ) );
		}

		return $formatted;
	}

	/**
	 * Convert MCP tools to AI Client function declarations
	 *
	 * Uses the same wpab__ naming convention as the core AI Client uses for
	 * abilities, since function names may not contain slashes.
	 *
	 * @param array $mcp_tools MCP tool definitions.
	 * @return array Array of FunctionDeclaration DTOs.
	 */
	private function convert_tools_format( $mcp_tools ) {
		$declarations = array();

		foreach ( $mcp_tools as $tool ) {
			$name = $tool['name'] ?? '';
			if ( empty( $name ) ) {
				continue;
			}

			$input_schema = $tool['inputSchema'] ?? null;

			$declarations[] = new FunctionDeclaration(
				$this->convert_tool_name_from_mcp( $name ),
				$tool['description'] ?? '',
				! empty( $input_schema ) ? $input_schema : null
			);
		}

		return $declarations;
	}

	/**
	 * Convert MCP tool name to a valid function name
	 *
	 * @param string $mcp_name MCP tool name (e.g. "marketing-analytics/get-ga4-metrics").
	 * @return string Function name (e.g. "wpab__marketing-analytics__get-ga4-metrics").
	 */
	private function convert_tool_name_from_mcp( $mcp_name ) {
		if ( str_starts_with( $mcp_name, self::FUNCTION_PREFIX ) ) {
			return $mcp_name;
		}

		return self::FUNCTION_PREFIX . str_replace( '/', '__', $mcp_name );
	}

	/**
	 * Convert a function name back to MCP tool name
	 *
	 * @param string $function_name Function name from the model.
	 * @return string MCP tool name.
	 */
	private function convert_tool_name_to_mcp( $function_name ) {
		if ( str_starts_with( $function_name, self::FUNCTION_PREFIX ) ) {
			$function_name = substr( $function_name, strlen( self::FUNCTION_PREFIX ) );
		}

		return str_replace( '__', '/', $function_name );
	}

	/**
	 * Parse an AI Client result into the plugin response format
	 *
	 * @param object $result GenerativeAiResult instance.
	 * @return array Parsed response.
	 */
	private function parse_result( $result ) {
		$text_parts = array();
		$tool_calls = array();

		$message = $result->toMessage();

		foreach ( $message->getParts() as $index => $part ) {
			$function_call = $part->getFunctionCall();

			if ( $function_call instanceof FunctionCall ) {
				$name = $function_call->getName() ?? '';

				$tool_calls[] = array(
					'id'        => $function_call->getId() ?? 'call_' . $index,
					'name'      => $this->convert_tool_name_to_mcp( $name ),
					'arguments' => (array) ( $function_call->getArgs() ?? array() ),
				);
				continue;
			}

			$text = $part->getText();
			if ( null !== $text && '' !== $text ) {
				$text_parts[] = $text;
			}
		}

		$usage = array();
		try {
			$token_usage = $result->getTokenUsage();
			$usage       = array(
				'input_tokens'  => $token_usage->getPromptTokens(),
				'output_tokens' => $token_usage->getCompletionTokens(),
			);
		} catch ( \Throwable $e ) {
			Logger::debug( 'WP AI Client: token usage unavailable: ' . $e->getMessage() );
		}

		return array(
			'content'     => implode( "\n\n", $text_parts ),
			'tool_calls'  => ! empty( $tool_calls ) ? $tool_calls : null,
			'stop_reason' => ! empty( $tool_calls ) ? 'tool_use' : 'end_turn',
			'usage'       => $usage,
			'raw'         => null,
		);
	}

	/**
	 * Get default system message
	 *
	 * @return string System message.
	 */
	private function get_default_system_message() {
		return __( 'You are a helpful AI assistant with access to marketing analytics data from Google Analytics 4, Google Search Console, and Microsoft Clarity. Use the available tools to answer questions about website performance, user behavior, and marketing metrics. Provide clear, actionable insights based on the data.', 'specflux-marketing-analytics-chat' );
	}
}
