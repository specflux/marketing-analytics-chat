<?php
/**
 * Tests for the Chat Manager class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Chat\Chat_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Chat Manager test class.
 */
class ChatManagerTest extends TestCase {

	/**
	 * Chat Manager instance.
	 *
	 * @var Chat_Manager
	 */
	private $manager;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_cache;
		$mock_cache    = array();
		$this->manager = new Chat_Manager();
	}

	/**
	 * Test class exists.
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( 'Specflux_Marketing_Analytics\Chat\Chat_Manager' ) );
	}

	/**
	 * Test cache group uses expected prefix.
	 */
	public function test_cache_group_prefix(): void {
		$reflection = new \ReflectionClass( $this->manager );
		$property   = $reflection->getProperty( 'cache_group' );
		$property->setAccessible( true );

		$cache_group = $property->getValue( $this->manager );

		$this->assertStringContainsString(
			'specflux_mac',
			$cache_group,
			'Cache group should contain specflux_mac'
		);
	}

	/**
	 * Test database table name uses expected prefix.
	 */
	public function test_database_table_prefix(): void {
		// Read the class source to verify table name construction.
		$reflection = new \ReflectionClass( $this->manager );
		$filename   = $reflection->getFileName();
		$source     = file_get_contents( $filename );

		$this->assertStringContainsString(
			"'specflux_mac_conversations'",
			$source,
			'Conversations table should use specflux_mac_ prefix'
		);

		$this->assertStringContainsString(
			"'specflux_mac_messages'",
			$source,
			'Messages table should use specflux_mac_ prefix'
		);
	}

	/**
	 * Test get_conversations returns array.
	 */
	public function test_get_conversations_returns_array(): void {
		$result = $this->manager->get_conversations( 1 );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_conversations with default parameters.
	 */
	public function test_get_conversations_default_params(): void {
		// With mock wpdb returning empty results, should return empty array.
		$result = $this->manager->get_conversations( 1 );

		$this->assertEmpty( $result );
	}

	/**
	 * Test get_conversation returns null for non-existent.
	 */
	public function test_get_conversation_returns_null(): void {
		$result = $this->manager->get_conversation( 999 );

		$this->assertNull( $result );
	}

	/**
	 * Test Chat_Manager has required public methods.
	 */
	public function test_has_required_methods(): void {
		$methods = array(
			'get_conversations',
			'get_conversation',
			'create_conversation',
			'get_messages',
			'add_message',
			'delete_conversation',
		);

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( $this->manager, $method ),
				"Chat_Manager should have '{$method}' method"
			);
		}
	}

	/**
	 * Test create_conversation calls database insert.
	 */
	public function test_create_conversation(): void {
		global $wpdb;

		// Mock wpdb->insert_id for the insert result.
		$wpdb->insert_id = 42;

		$result = $this->manager->create_conversation( 1, 'Test Conversation' );

		// Should return the insert ID or a truthy value.
		$this->assertNotFalse( $result );
	}

	/**
	 * Test generate_title_from_message creates readable title.
	 */
	public function test_generate_title_from_message(): void {
		$title = $this->manager->generate_title_from_message(
			'What are my top performing pages from last week according to Google Analytics?'
		);

		$this->assertIsString( $title );
		$this->assertNotEmpty( $title );
		// Title should be truncated to a reasonable length.
		$this->assertLessThanOrEqual( 100, strlen( $title ) );
	}

	/**
	 * Test generate_title_from_message with short message.
	 */
	public function test_generate_title_short_message(): void {
		$title = $this->manager->generate_title_from_message( 'Hello' );

		$this->assertIsString( $title );
		$this->assertNotEmpty( $title );
	}

	/**
	 * Test get_messages returns array.
	 */
	public function test_get_messages_returns_array(): void {
		$result = $this->manager->get_messages( 1 );

		$this->assertIsArray( $result );
	}

	/**
	 * Test conversations caching.
	 */
	public function test_conversations_use_cache(): void {
		global $mock_cache;

		// First call - cache miss.
		$result1 = $this->manager->get_conversations( 1 );

		// Verify something was cached.
		$has_cache_entry = false;
		foreach ( $mock_cache as $key => $value ) {
			if ( strpos( $key, 'specflux_mac' ) !== false ) {
				$has_cache_entry = true;
				break;
			}
		}

		$this->assertTrue( $has_cache_entry, 'Conversations should be cached after first fetch' );
	}

	/**
	 * Test add_message has correct signature.
	 */
	public function test_add_message_method_signature(): void {
		$reflection = new \ReflectionMethod( $this->manager, 'add_message' );
		$params     = $reflection->getParameters();

		$this->assertGreaterThanOrEqual( 3, count( $params ), 'add_message should accept at least 3 parameters' );
	}

	/**
	 * Test delete_conversation method exists and is callable.
	 */
	public function test_delete_conversation_callable(): void {
		$this->assertTrue( is_callable( array( $this->manager, 'delete_conversation' ) ) );
	}
}
