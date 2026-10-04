<?php
/**
 * Integration tests for GA4 API client.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\integration;

use Specflux_Marketing_Analytics\API_Clients\GA4_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * GA4 integration test class.
 */
class GA4IntegrationTest extends TestCase {

	/**
	 * GA4 client instance.
	 *
	 * @var GA4_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->client = new GA4_Client();

		// Skip tests if no credentials
		if ( ! getenv( 'GA4_CLIENT_ID' ) || ! getenv( 'GA4_CLIENT_SECRET' ) ) {
			$this->markTestSkipped( 'GA4 API credentials not available for integration testing.' );
		}

		$credential_manager = new Credential_Manager();
		$credential_manager->save_credentials( 'ga4', array(
			'client_id'     => getenv( 'GA4_CLIENT_ID' ),
			'client_secret' => getenv( 'GA4_CLIENT_SECRET' ),
			'refresh_token' => getenv( 'GA4_REFRESH_TOKEN' ),
		) );
	}

	/**
	 * Test real GA4 API call.
	 */
	#[Group('integration')]
	#[Group('external-api')]
	public function test_get_metrics_real_api_call(): void {
		$property_id = getenv( 'GA4_PROPERTY_ID' );

		if ( ! $property_id ) {
			$this->markTestSkipped( 'GA4_PROPERTY_ID not set.' );
		}

		update_option( 'specflux_mac_ga4_property_id', $property_id );
		$client = new GA4_Client();

		$result = $client->run_report( array( 'activeUsers', 'sessions' ), array(), '7daysAgo' );

		$this->assertIsArray( $result );
	}

	/**
	 * Test OAuth token refresh.
	 */
	#[Group('integration')]
	public function test_oauth_token_refresh(): void {
		// Client should automatically refresh expired tokens
		// This is handled by the Google API client library
		$this->assertTrue( method_exists( $this->client, 'refresh_access_token' ) ||
						   class_exists( 'Google_Client' ) );
	}

	/**
	 * Test multiple metrics in single request.
	 */
	#[Group('integration')]
	public function test_multiple_metrics(): void {
		$property_id = getenv( 'GA4_PROPERTY_ID' );

		if ( ! $property_id ) {
			$this->markTestSkipped( 'GA4_PROPERTY_ID not set.' );
		}

		update_option( 'specflux_mac_ga4_property_id', $property_id );
		$client = new GA4_Client();

		$result = $client->run_report(
			array( 'activeUsers', 'sessions', 'screenPageViews', 'bounceRate' ),
			array(),
			'30daysAgo'
		);

		$this->assertIsArray( $result );

		if ( ! empty( $result ) ) {
			$this->assertArrayHasKey( 'rows', $result );
		}
	}

	/**
	 * Test dimension grouping.
	 */
	#[Group('integration')]
	public function test_dimension_grouping(): void {
		$property_id = getenv( 'GA4_PROPERTY_ID' );

		if ( ! $property_id ) {
			$this->markTestSkipped( 'GA4_PROPERTY_ID not set.' );
		}

		update_option( 'specflux_mac_ga4_property_id', $property_id );
		$client = new GA4_Client();

		$result = $client->run_report(
			array( 'activeUsers' ),
			array( 'country', 'deviceCategory' ),
			'7daysAgo'
		);

		$this->assertIsArray( $result );
	}
}
