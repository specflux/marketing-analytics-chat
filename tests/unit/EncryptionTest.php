<?php
/**
 * Tests for the Encryption class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Credentials\Encryption;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Encryption test class.
 */
class EncryptionTest extends TestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array();
	}

	/**
	 * Test that encryption works and returns a string.
	 */
	public function test_encrypt_returns_string(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'Sodium extension not available.' );
		}

		$credentials = array(
			'api_key' => 'test_key_123',
			'secret'  => 'test_secret_456',
		);

		$encrypted = Encryption::encrypt( $credentials, 'test_platform' );

		$this->assertIsString( $encrypted );
		$this->assertNotEmpty( $encrypted );
	}

	/**
	 * Test that encryption and decryption are reversible.
	 */
	public function test_encrypt_decrypt_reversible(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'Sodium extension not available.' );
		}

		$credentials = array(
			'api_key' => 'test_key_123',
			'secret'  => 'test_secret_456',
		);

		$encrypted  = Encryption::encrypt( $credentials, 'test_platform' );
		$decrypted  = Encryption::decrypt( $encrypted, 'test_platform' );

		$this->assertEquals( $credentials, $decrypted );
	}

	/**
	 * Test that invalid encrypted data returns false.
	 */
	public function test_decrypt_invalid_data_returns_false(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'Sodium extension not available.' );
		}

		$result = Encryption::decrypt( 'invalid_encrypted_data', 'test_platform' );

		$this->assertFalse( $result );
	}

	/**
	 * Test save and get credentials through the storage interface.
	 */
	public function test_save_and_get_credentials(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'Sodium extension not available.' );
		}

		$credentials = array(
			'client_id'     => 'test_client_id',
			'client_secret' => 'test_client_secret',
		);

		$manager = new Credential_Manager();

		$this->assertTrue( $manager->save_credentials( 'ga4', $credentials ) );
		$this->assertEquals( $credentials, $manager->get_credentials( 'ga4' ) );
	}

	/**
	 * Test get credentials returns null when nothing is stored.
	 */
	public function test_get_credentials_returns_null_when_absent(): void {
		$manager = new Credential_Manager();
		$manager->delete_credentials( 'gsc' );

		$this->assertNull( $manager->get_credentials( 'gsc' ) );
	}

	/**
	 * Test unsupported platforms are rejected rather than written.
	 */
	public function test_unsupported_platform_is_rejected(): void {
		$manager = new Credential_Manager();

		$this->assertFalse( $manager->save_credentials( 'nonexistent_platform', array( 'key' => 'value' ) ) );
		$this->assertNull( $manager->get_credentials( 'nonexistent_platform' ) );
	}

	/**
	 * Test delete credentials.
	 */
	public function test_delete_credentials(): void {
		if ( ! extension_loaded( 'sodium' ) ) {
			$this->markTestSkipped( 'Sodium extension not available.' );
		}

		$manager = new Credential_Manager();
		$manager->save_credentials( 'clarity', array( 'api_token' => 'value' ) );

		$this->assertTrue( $manager->delete_credentials( 'clarity' ) );
		$this->assertNull( $manager->get_credentials( 'clarity' ) );
	}
}
