<?php
/**
 * Credential Encryption Handler
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Credentials;

use Specflux_Marketing_Analytics\Utils\Logger;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Handles encryption and decryption of API credentials using libsodium
 */
class Encryption {

	/**
	 * Encryption key option name
	 */
	const KEY_OPTION = 'specflux_mac_encryption_key';

	/**
	 * Get or generate encryption key
	 *
	 * @return string The encryption key
	 */
	private static function get_key() {
		$key = get_option( self::KEY_OPTION );

		if ( ! $key ) {
			Logger::debug( 'Encryption key not found, generating new key' );
			$new_key = base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );

			// Use add_option which will fail if the option already exists (race condition protection).
			$added = add_option( self::KEY_OPTION, $new_key, '', false );

			if ( $added ) {
				Logger::debug( 'New encryption key generated and stored' );
				$key = $new_key;
			} else {
				// Another process already created the key, fetch it.
				Logger::debug( 'Key was already created by another process, fetching it' );
				$key = get_option( self::KEY_OPTION );

				// Double-check that we got a key.
				if ( ! $key ) {
					throw new \RuntimeException( 'Failed to generate or retrieve encryption key' );
				}
			}
		}

		return base64_decode( $key );
	}

	/**
	 * Encrypt credentials
	 *
	 * @param array  $credentials The credentials to encrypt.
	 * @param string $platform    The platform identifier for logging.
	 * @return string|false Encrypted string or false on failure
	 */
	public static function encrypt( $credentials, $platform = 'unknown' ) {
		try {
			Logger::debug( sprintf( 'Starting encryption for platform: %s', $platform ) );

			if ( ! extension_loaded( 'sodium' ) ) {
				Logger::error( 'Sodium extension not loaded' );
				return false;
			}

			$key       = self::get_key();
			$nonce     = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plaintext = wp_json_encode( $credentials );

			Logger::debug( sprintf( 'Encrypting credentials (length: %d bytes)', strlen( $plaintext ) ) );

			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			$encrypted  = base64_encode( $nonce . $ciphertext );

			// Clean up.
			sodium_memzero( $plaintext );
			sodium_memzero( $key );

			Logger::debug( sprintf( 'Encryption successful for %s (encrypted length: %d bytes)', $platform, strlen( $encrypted ) ) );

			return $encrypted;

		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Encryption FAILED for %s: %s', $platform, $e->getMessage() ) );
			Logger::debug( sprintf( 'Encryption error trace: %s', $e->getTraceAsString() ) );
			return false;
		}
	}

	/**
	 * Decrypt credentials
	 *
	 * @param string $encrypted The encrypted string.
	 * @param string $platform  The platform identifier for logging.
	 * @return array|false Decrypted credentials or false on failure
	 */
	public static function decrypt( $encrypted, $platform = 'unknown' ) {
		try {
			Logger::debug( sprintf( 'Starting decryption for platform: %s', $platform ) );

			if ( ! extension_loaded( 'sodium' ) ) {
				Logger::error( 'Sodium extension not loaded' );
				return false;
			}

			$key     = self::get_key();
			$decoded = base64_decode( $encrypted );

			if ( false === $decoded ) {
				Logger::error( sprintf( 'Base64 decode failed for %s', $platform ) );
				return false;
			}

			$nonce      = mb_substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit' );
			$ciphertext = mb_substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit' );

			Logger::debug( sprintf( 'Decrypting credentials (ciphertext length: %d bytes)', strlen( $ciphertext ) ) );

			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

			// Clean up.
			sodium_memzero( $key );

			if ( false === $plaintext ) {
				Logger::error( sprintf( 'Decryption failed for %s (invalid key or corrupted data)', $platform ) );
				return false;
			}

			$credentials = json_decode( $plaintext, true );
			sodium_memzero( $plaintext );

			if ( null === $credentials ) {
				Logger::error( sprintf( 'JSON decode failed for %s', $platform ) );
				return false;
			}

			Logger::debug( sprintf( 'Decryption successful for %s', $platform ) );

			return $credentials;

		} catch ( \Exception $e ) {
			Logger::error( sprintf( 'Decryption FAILED for %s: %s', $platform, $e->getMessage() ) );
			Logger::debug( sprintf( 'Decryption error trace: %s', $e->getTraceAsString() ) );
			return false;
		}
	}
}
