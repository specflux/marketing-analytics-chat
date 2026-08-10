<?php
/**
 * Ability Response Builder
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Builds MCP tool and resource responses with uniform error handling
 */
class Ability_Response {

	/**
	 * Build a tool response from a producer callback
	 *
	 * Runs the callback and JSON-encodes whatever it returns. Any exception is
	 * converted into the standard tool error response.
	 *
	 * @param callable $produce Callback returning the data to encode.
	 * @return array Tool result.
	 */
	public static function tool( callable $produce ) {
		try {
			$data = $produce();

			return array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $data, JSON_PRETTY_PRINT ),
					),
				),
			);
		} catch ( \Exception $e ) {
			return array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Error: ' . $e->getMessage(),
					),
				),
				'isError' => true,
			);
		}
	}

	/**
	 * Build a resource response from a producer callback
	 *
	 * Runs the callback and JSON-encodes whatever it returns. Any exception is
	 * converted into a plain-text resource response for the same URI.
	 *
	 * @param string   $uri     Resource URI reported back to the client.
	 * @param callable $produce Callback returning the data to encode.
	 * @return array Resource result.
	 */
	public static function resource( $uri, callable $produce ) {
		try {
			$data = $produce();

			return array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => 'application/json',
						'text'     => wp_json_encode( $data, JSON_PRETTY_PRINT ),
					),
				),
			);
		} catch ( \Exception $e ) {
			return array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => 'text/plain',
						'text'     => 'Error: ' . $e->getMessage(),
					),
				),
			);
		}
	}
}
