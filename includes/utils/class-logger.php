<?php
/**
 * Logger utility for Specflux Marketing Analytics Chat
 *
 * Centralizes logging and respects debug mode settings.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Utils;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Logger class for debug and error logging.
 */
class Logger {

	/**
	 * Log a debug message (only when WP_DEBUG is enabled).
	 *
	 * @param string $message The message to log.
	 * @return void
	 */
	public static function debug( $message ) {
		if ( ! self::is_debug_enabled() ) {
			return;
		}

		if ( is_array( $message ) || is_object( $message ) ) {
			$message = print_r( $message, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		error_log( '[Specflux Marketing Analytics Chat] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Log an error message (always logged regardless of debug mode).
	 *
	 * @param string $message The error message to log.
	 * @return void
	 */
	public static function error( $message ) {
		// Gate error logging behind the plugin debug toggle / WP_DEBUG so the
		// plugin never writes to error_log during normal operation.
		if ( ! self::is_debug_enabled() ) {
			return;
		}

		if ( is_array( $message ) || is_object( $message ) ) {
			$message = print_r( $message, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		error_log( '[Specflux Marketing Analytics Chat ERROR] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Log a warning message (only when WP_DEBUG is enabled).
	 *
	 * @param string $message The warning message to log.
	 * @return void
	 */
	public static function warning( $message ) {
		if ( ! self::is_debug_enabled() ) {
			return;
		}

		if ( is_array( $message ) || is_object( $message ) ) {
			$message = print_r( $message, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		error_log( '[Specflux Marketing Analytics Chat WARNING] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Redact credential-like values from an array before logging.
	 *
	 * Replaces any value whose key looks like a secret (token, key, secret,
	 * password, credential) with a fixed placeholder, recursing into nested
	 * arrays. Use this on any request/response payload before passing it to a
	 * log method so credentials never reach error_log (which is often web
	 * readable).
	 *
	 * @param array $data Associative array that may contain secrets.
	 * @return array Copy of $data with sensitive values redacted.
	 */
	public static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$sensitive = '/(token|api[_-]?key|secret|password|passwd|credential|client[_-]?secret|refresh[_-]?token|access[_-]?token)/i';

		$redacted = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact( $value );
			} elseif ( is_string( $key ) && preg_match( $sensitive, $key ) ) {
				$redacted[ $key ] = '[redacted]';
			} else {
				$redacted[ $key ] = $value;
			}
		}

		return $redacted;
	}

	/**
	 * Check if debug logging is enabled.
	 *
	 * @return bool True if debug logging is enabled.
	 */
	public static function is_debug_enabled() {
		// Check plugin-specific debug setting first.
		$plugin_debug = get_option( 'specflux_mac_debug_mode', false );
		if ( $plugin_debug ) {
			return true;
		}

		// Fall back to WP_DEBUG constant.
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}
}
require_once __DIR__ . '/logger-functions.php';
