<?php
/**
 * Prompt form input
 *
 * Turns the Prompts screen's create/edit form submission into the array that
 * Prompt_Manager stores, or an error message. No WordPress state is read, so the
 * rules can be tested directly.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Prompts;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Parses and validates the prompt form fields (names unchanged since 0.x).
 */
class Prompt_Form_Input {

	/**
	 * Parse the form fields.
	 *
	 * @param array $post Raw (slashed) request values, as in $_POST. The nonce must already be verified.
	 * @return array{data:?array,error:string} Either the prompt data or an error message.
	 */
	public static function parse( array $post ) {
		$name         = isset( $post['prompt_name'] ) ? sanitize_text_field( wp_unslash( $post['prompt_name'] ) ) : '';
		$label        = isset( $post['prompt_label'] ) ? sanitize_text_field( wp_unslash( $post['prompt_label'] ) ) : '';
		$description  = isset( $post['prompt_description'] ) ? sanitize_textarea_field( wp_unslash( $post['prompt_description'] ) ) : '';
		$instructions = isset( $post['prompt_instructions'] ) ? wp_kses_post( wp_unslash( $post['prompt_instructions'] ) ) : '';
		$category     = isset( $post['prompt_category'] ) ? sanitize_text_field( wp_unslash( $post['prompt_category'] ) ) : 'marketing-analytics';
		$arguments_in = isset( $post['prompt_arguments'] ) ? sanitize_textarea_field( wp_unslash( $post['prompt_arguments'] ) ) : '';

		if ( '' === $name || '' === $label || '' === $description || '' === $instructions ) {
			return self::fail( __( 'Please fill in all required fields.', 'specflux-marketing-analytics-chat' ) );
		}

		if ( ! preg_match( '/^[a-z0-9-]+$/', $name ) ) {
			return self::fail( __( 'Prompt name can only contain lowercase letters, numbers, and hyphens.', 'specflux-marketing-analytics-chat' ) );
		}

		$arguments = array();
		if ( '' !== $arguments_in ) {
			$decoded = json_decode( $arguments_in, true );
			if ( ! is_array( $decoded ) ) {
				return self::fail( __( 'Invalid JSON in Arguments field.', 'specflux-marketing-analytics-chat' ) );
			}
			foreach ( $decoded as $argument ) {
				if ( ! is_array( $argument ) ) {
					continue;
				}
				$arguments[] = array(
					'name'        => isset( $argument['name'] ) ? sanitize_key( $argument['name'] ) : '',
					'type'        => isset( $argument['type'] ) ? sanitize_text_field( $argument['type'] ) : '',
					'description' => isset( $argument['description'] ) ? sanitize_text_field( $argument['description'] ) : '',
					'required'    => ! empty( $argument['required'] ),
					'default'     => isset( $argument['default'] ) && is_scalar( $argument['default'] )
						? sanitize_text_field( (string) $argument['default'] )
						: null,
				);
			}
		}

		return array(
			'data'  => array(
				'name'         => $name,
				'label'        => $label,
				'description'  => $description,
				'instructions' => $instructions,
				'category'     => $category,
				'arguments'    => $arguments,
			),
			'error' => '',
		);
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $message Error message.
	 * @return array{data:?array,error:string}
	 */
	private static function fail( $message ) {
		return array(
			'data'  => null,
			'error' => $message,
		);
	}
}
