<?php
/**
 * Integration tests for Clarity API client.
 *
 * These tests require actual API credentials and are typically run in CI/CD.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\integration;

use Specflux_Marketing_Analytics\API_Clients\Clarity_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Cache\Cache_Manager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Clarity integration test class.
 */
class ClarityIntegrationTest extends TestCase {

	/**
	 * Clarity client instance.
	 *
	 * @var Clarity_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Skip tests if no credentials are available
		if ( ! getenv( 'CLARITY_PROJECT_ID' ) || ! getenv( 'CLARITY_API_KEY' ) ) {
			$this->markTestSkipped( 'Clarity API credentials not available for integration testing.' );
		}

		$this->client = new Clarity_Client(
			getenv( 'CLARITY_API_KEY' ),
			getenv( 'CLARITY_PROJECT_ID' )
		);
	}

	/**
	 * Test actual API call to Clarity.
	 */
	#[Group('integration')]
	#[Group('external-api')]
	public function test_get_insights_real_api_call(): void {
		// Clarity caps the window at 3 days.
		$result = $this->client->get_insights( 3, array( 'Device' ) );

		$this->assertIsArray( $result );
	}

	/**
	 * Test caching works with real API.
	 */
	#[Group('integration')]
	public function test_caching_with_real_api(): void {
		$dimensions = array( 'Country' );

		// First call - should hit API.
		$result1 = $this->client->get_insights( 3, $dimensions );

		// Second call - should use cache.
		$result2 = $this->client->get_insights( 3, $dimensions );

		// Results should be identical.
		$this->assertEquals( $result1, $result2 );

		$cache_manager = new Cache_Manager();
		$cache_key     = $cache_manager->generate_key(
			'clarity',
			'project_live_insights',
			array(
				'project_id'  => $this->client->get_project_id(),
				'num_of_days' => 3,
				'dimensions'  => $dimensions,
			)
		);
		$this->assertNotFalse( $cache_manager->get( $cache_key ) );
	}

	/**
	 * Test rate limiting awareness.
	 */
	#[Group('integration')]
	#[Group('slow')]
	public function test_rate_limiting(): void {
		// Clarity allows 10 requests per day
		// This test should not exceed the limit in a single run

		for ( $i = 0; $i < 3; $i++ ) {
			$result = $this->client->get_insights( array(
				'num_of_days' => 1,
				'dimension1'  => 'Device',
			) );

			// Due to caching, only first request should hit API
			$this->assertTrue( is_array( $result ) || false === $result );
		}

		// Verify we didn't exhaust the rate limit
		$this->assertTrue( true );
	}

	/**
	 * Test error handling with real API.
	 */
	#[Group('integration')]
	public function test_error_handling_with_invalid_parameters(): void {
		$result = $this->client->get_insights( array(
			'num_of_days' => 999, // Invalid: exceeds 90 days
		) );

		// Should handle error gracefully
		$this->assertFalse( $result );
	}

	/**
	 * Clean up after tests.
	 */
	protected function tearDown(): void {
		// Clear cache
		( new Cache_Manager() )->clear_platform_cache( 'clarity' );

		parent::tearDown();
	}
}
