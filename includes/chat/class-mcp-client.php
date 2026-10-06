<?php
/**
 * MCP Client
 *
 * Client for communicating with the local WordPress MCP server.
 * Provides methods to list and call MCP tools.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Chat;

use WP_Error;
use Specflux_Marketing_Analytics\Utils\Logger;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * MCP Client for calling local MCP server tools
 */
class MCP_Client {

	/**
	 * WordPress user for MCP context
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Constructor
	 *
	 * @param int $user_id WordPress user ID for MCP context.
	 */
	public function __construct( $user_id = null ) {
		$this->user_id = $user_id ?: get_current_user_id();
	}

	/**
	 * List available MCP tools
	 *
	 * @return array|WP_Error Array of tool definitions or WP_Error if the Abilities API is unavailable.
	 */
	public function list_tools() {
		if ( function_exists( 'wp_get_abilities' ) ) {
			$abilities = wp_get_abilities();
			$tools     = array();

			Logger::debug( 'MCP Client: list_tools: Found ' . count( $abilities ) . ' abilities' );

			foreach ( $abilities as $ability_key => $ability ) {
				// WP_Ability objects use getter methods for protected properties.
				$name         = '';
				$description  = '';
				$input_schema = null;

				if ( is_object( $ability ) && $ability instanceof \WP_Ability ) {
					// Use getter methods for WP_Ability objects.
					$name         = $ability->get_name();
					$description  = $ability->get_description();
					$input_schema = $ability->get_input_schema();
				} elseif ( is_object( $ability ) ) {
					// Fallback for other object types - try getter methods first, then properties.
					if ( method_exists( $ability, 'get_name' ) ) {
						$name = $ability->get_name();
					} else {
						$name = $ability->id ?? $ability->name ?? $ability->slug ?? '';
					}

					if ( method_exists( $ability, 'get_description' ) ) {
						$description = $ability->get_description();
					} else {
						$description = $ability->description ?? '';
					}

					if ( method_exists( $ability, 'get_input_schema' ) ) {
						$input_schema = $ability->get_input_schema();
					} else {
						$input_schema = $ability->input_schema ?? null;
					}
				} elseif ( is_array( $ability ) ) {
					$name         = $ability['id'] ?? $ability['name'] ?? $ability['slug'] ?? '';
					$description  = $ability['description'] ?? '';
					$input_schema = $ability['input_schema'] ?? null;
				}

				// If still empty, use the array key (which is often the ability ID).
				if ( empty( $name ) && is_string( $ability_key ) && ! is_numeric( $ability_key ) ) {
					$name = $ability_key;
				}

				// Skip abilities without valid names.
				if ( empty( $name ) ) {
					Logger::debug( 'MCP Client: SKIPPING ability with empty name. Key: ' . $ability_key );
					continue;
				}

				// Ensure input_schema has proper structure for empty schemas.
				if ( empty( $input_schema ) || ! is_array( $input_schema ) ) {
					$input_schema = array(
						'type'       => 'object',
						'properties' => new \stdClass(),
					);
				}

				$tools[] = array(
					'name'        => $name,
					'description' => $description,
					'inputSchema' => $input_schema,
				);
			}

			Logger::debug( 'MCP Client: list_tools: Returning ' . count( $tools ) . ' valid tools' );

			return $tools;
		}

		return new WP_Error(
			'mcp_abilities_unavailable',
			__( 'The WordPress Abilities API is unavailable.', 'specflux-marketing-analytics-chat' )
		);
	}

	/**
	 * Call an MCP tool
	 *
	 * @param string $tool_name Tool name (e.g., 'marketing-analytics/get-clarity-insights').
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error Tool result or WP_Error on failure.
	 */
	public function call_tool( $tool_name, $arguments = array() ) {
		if ( function_exists( 'wp_get_ability' ) ) {
			$ability = wp_get_ability( $tool_name );

			if ( $ability instanceof \WP_Ability ) {
				// Execute the ability directly. Core treats an empty array as
				// "input provided" and rejects it when the ability defines no
				// input schema, so pass null for argument-less tool calls.
				$result = $ability->execute( empty( $arguments ) ? null : $arguments );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				// Abilities built with Ability_Response::tool() already return the
				// MCP structure; pass it through untouched so the text is not
				// JSON-encoded a second time and isError survives.
				if ( is_array( $result ) && isset( $result['content'] ) && is_array( $result['content'] ) ) {
					return $this->limit_result_rows( $result );
				}

				// Format plain results to match the MCP response structure.
				return $this->limit_result_rows(
					array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => is_string( $result ) ? $result : wp_json_encode( $result ),
							),
						),
					)
				);
			}

			Logger::debug( 'MCP Client: Ability not found via wp_get_ability: ' . $tool_name );
		}

		return new WP_Error(
			'mcp_tool_not_found',
			sprintf(
				/* translators: %s: Tool name */
				__( 'Tool not found: %s', 'specflux-marketing-analytics-chat' ),
				$tool_name
			)
		);
	}

	/**
	 * Cap long row lists in a tool result before it reaches the model.
	 *
	 * Only the text the chat sends to the model is changed; abilities and the
	 * external MCP server still return full data. Any JSON list longer than the
	 * cap is cut and its parent object gains
	 * truncated.{field} = { shown, total } so the model knows it is partial.
	 *
	 * @param array $result MCP-shaped tool result.
	 * @return array Result with oversized lists truncated.
	 */
	private function limit_result_rows( $result ) {
		/**
		 * Filters the maximum rows per list of a tool result sent to the model.
		 *
		 * @param int $max_rows Maximum rows. Default 25. Zero or less disables truncation.
		 */
		$max_rows = (int) apply_filters( 'specflux_mac_chat_max_result_rows', 25 );

		if ( $max_rows < 1 || empty( $result['content'] ) || ! is_array( $result['content'] ) ) {
			return $result;
		}

		foreach ( $result['content'] as $index => $item ) {
			if ( ! isset( $item['type'], $item['text'] ) || 'text' !== $item['type'] || ! is_string( $item['text'] ) ) {
				continue;
			}

			$decoded = json_decode( $item['text'], true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$truncated = false;
			$decoded   = $this->truncate_lists( $decoded, $max_rows, $truncated );

			if ( $truncated ) {
				$result['content'][ $index ]['text'] = wp_json_encode( $decoded );
			}
		}

		return $result;
	}

	/**
	 * Recursively truncate lists longer than the cap.
	 *
	 * @param array $data      Decoded JSON value.
	 * @param int   $max_rows  Row cap.
	 * @param bool  $truncated Set to true when anything was cut.
	 * @return array Truncated value.
	 */
	private function truncate_lists( $data, $max_rows, &$truncated ) {
		if ( array_is_list( $data ) ) {
			if ( count( $data ) > $max_rows ) {
				$truncated = true;
				$data      = array(
					'rows'      => array_slice( $data, 0, $max_rows ),
					'truncated' => array(
						'rows' => array(
							'shown' => $max_rows,
							'total' => count( $data ),
						),
					),
				);
			}

			return $data;
		}

		$notes = array();
		foreach ( $data as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( array_is_list( $value ) && count( $value ) > $max_rows ) {
				$truncated      = true;
				$notes[ $key ]  = array(
					'shown' => $max_rows,
					'total' => count( $value ),
				);
				$data[ $key ]   = array_slice( $value, 0, $max_rows );
				$value          = $data[ $key ];
			}

			// Recurse into the rows/objects themselves (but not into a list we just cut as a list).
			foreach ( $value as $child_key => $child ) {
				if ( is_array( $child ) ) {
					$data[ $key ][ $child_key ] = $this->truncate_lists( $child, $max_rows, $truncated );
				}
			}
		}

		if ( $notes ) {
			$data['truncated'] = array_merge( is_array( $data['truncated'] ?? null ) ? $data['truncated'] : array(), $notes );
		}

		return $data;
	}

	/**
	 * Format tool result for AI context
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $result Tool result.
	 * @return string Formatted result for AI.
	 */
	public function format_tool_result( $tool_name, $result ) {
		$formatted = "Tool: {$tool_name}\n\n";

		if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
			foreach ( $result['content'] as $content_item ) {
				if ( isset( $content_item['type'] ) && 'text' === $content_item['type'] ) {
					$formatted .= $content_item['text'] . "\n\n";
				}
			}
		} elseif ( is_string( $result ) ) {
			$formatted .= $result . "\n";
		} else {
			$formatted .= wp_json_encode( $result ) . "\n";
		}

		return $formatted;
	}
}
