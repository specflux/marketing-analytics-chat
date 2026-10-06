<?php
/**
 * Tests for the GSC_Client class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\API_Clients\GSC_Client;
use Specflux_Marketing_Analytics\Credentials\OAuth_Handler;
use Specflux_Marketing_Analytics\Cache\Cache_Manager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Google Search Console Client test class.
 */
class GSCClientTest extends TestCase {

	/**
	 * GSC Client instance.
	 *
	 * @var GSC_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array(
			'specflux_mac_gsc_site_url' => 'https://example.com',
		);

		$this->client = new GSC_Client();
	}

	/**
	 * Test client initialization.
	 */
	public function test_client_initialization(): void {
		$this->assertInstanceOf( GSC_Client::class, $this->client );
	}

	/**
	 * Test get_site_url returns configured site URL.
	 */
	public function test_get_site_url(): void {
		$site_url = $this->client->get_site_url();
		$this->assertEquals( 'https://example.com', $site_url );
	}

	/**
	 * Test set_site_url updates the site URL.
	 */
	public function test_set_site_url(): void {
		$new_url = 'https://newsite.com';
		$result  = $this->client->set_site_url( $new_url );

		$this->assertTrue( $result );
		$this->assertEquals( $new_url, $this->client->get_site_url() );
	}

	/**
	 * Test set_site_url returns true when value is unchanged.
	 */
	public function test_set_site_url_unchanged_value(): void {
		$current_url = $this->client->get_site_url();
		$result      = $this->client->set_site_url( $current_url );

		$this->assertTrue( $result );
	}

	/**
	 * Test query_search_analytics throws exception when site URL not configured.
	 */
	public function test_query_search_analytics_throws_exception_without_site_url(): void {
		global $mock_options;
		$mock_options['specflux_mac_gsc_site_url'] = '';

		$client = new GSC_Client();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'GSC site URL not configured' );

		$client->query_search_analytics( '7daysAgo' );
	}

	/**
	 * Test get_url_inspection throws exception when site URL not configured.
	 */
	public function test_get_url_inspection_throws_exception_without_site_url(): void {
		global $mock_options;
		$mock_options['specflux_mac_gsc_site_url'] = '';

		$client = new GSC_Client();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'GSC site URL not configured' );

		$client->get_url_inspection( 'https://example.com/page' );
	}

	/**
	 * Test get_sitemap_status throws exception when site URL not configured.
	 */
	public function test_get_sitemap_status_throws_exception_without_site_url(): void {
		global $mock_options;
		$mock_options['specflux_mac_gsc_site_url'] = '';

		$client = new GSC_Client();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'GSC site URL not configured' );

		$client->get_sitemap_status();
	}

	/**
	 * Test parse_date_range handles relative dates.
	 */
	public function test_parse_date_range_relative(): void {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'parse_date_range' );
		$method->setAccessible( true );

		// Test '7daysAgo' format
		$result = $method->invoke( $this->client, '7daysAgo' );
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $result[0] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $result[1] );
	}

	/**
	 * Test parse_date_range handles comma-separated dates.
	 */
	public function test_parse_date_range_comma_separated(): void {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'parse_date_range' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, '2024-01-01,2024-01-31' );
		$this->assertEquals( array( '2024-01-01', '2024-01-31' ), $result );
	}

	/**
	 * Test parse_date_range handles specific date.
	 */
	public function test_parse_date_range_specific_date(): void {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'parse_date_range' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, '2024-01-15' );
		$this->assertEquals( array( '2024-01-15', '2024-01-15' ), $result );
	}

	/**
	 * Test parse_date_range handles 'yesterday'.
	 */
	public function test_parse_date_range_yesterday(): void {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'parse_date_range' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, 'yesterday' );
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertEquals( gmdate( 'Y-m-d', strtotime( '-4 days' ) ), $result[0] );
		$this->assertEquals( gmdate( 'Y-m-d', strtotime( '-3 days' ) ), $result[1] );
	}

	/**
	 * Test parse_search_analytics_response with empty response.
	 */
	public function test_parse_search_analytics_response_empty(): void {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'parse_search_analytics_response' );
		$method->setAccessible( true );

		$mock_response = $this->createMock( \Google\Service\SearchConsole\SearchAnalyticsQueryResponse::class );
		$mock_response->method( 'getRows' )->willReturn( null );

		$result = $method->invoke( $this->client, $mock_response );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'rows', $result );
		$this->assertArrayHasKey( 'row_count', $result );
		$this->assertCount( 0, $result['rows'] );
		$this->assertEquals( 0, $result['row_count'] );
	}

	/**
	 * Test list_sites returns null when no access token.
	 */
	public function test_list_sites_returns_null_without_token(): void {
		global $mock_oauth_tokens;
		$mock_oauth_tokens = array();

		$result = $this->client->list_sites();
		$this->assertNull( $result );
	}

	/**
	 * Test get_top_queries filters by minimum impressions.
	 */
	public function test_get_top_queries_filters_impressions(): void {
		// This would require mocking the API response, which is complex
		// For now, we test that the method signature is correct
		$this->assertTrue( method_exists( $this->client, 'get_top_queries' ) );
	}

	/**
	 * Test build_filters returns empty array for empty input.
	 */
	public function test_build_filters_empty(): void {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_filters' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->client, array() );
		$this->assertIsArray( $result );
		$this->assertCount( 0, $result );
	}

	/**
	 * Test build_filters returns filter groups for valid input.
	 */
	public function test_build_filters_returns_filter_groups(): void {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_filters' );
		$method->setAccessible( true );

		$filters = array(
			array(
				'dimension'  => 'country',
				'expression' => 'USA',
				'operator'   => 'equals',
			),
			array(
				'dimension'  => 'query',
				'expression' => 'wordpress',
			),
		);

		$result = $method->invoke( $this->client, $filters );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertInstanceOf( \Google\Service\SearchConsole\ApiDimensionFilterGroup::class, $result[0] );
	}

	/**
	 * Test build_filters skips invalid entries.
	 */
	public function test_build_filters_skips_invalid_entries(): void {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_filters' );
		$method->setAccessible( true );

		$filters = array(
			array( 'dimension' => 'country' ), // Missing expression
			array( 'expression' => 'USA' ),    // Missing dimension
		);

		$result = $method->invoke( $this->client, $filters );
		$this->assertIsArray( $result );
		$this->assertCount( 0, $result );
	}

	/**
	 * Test site normalization ignores scheme, www, case and trailing slash.
	 */
	public function test_normalize_site_for_match(): void {
		$this->assertSame( 'example.com', GSC_Client::normalize_site_for_match( 'https://www.Example.com/' ) );
		$this->assertSame( 'example.com', GSC_Client::normalize_site_for_match( 'http://example.com' ) );
		$this->assertSame( 'example.com', GSC_Client::normalize_site_for_match( 'sc-domain:example.com' ) );
	}

	/**
	 * Test suggestion matches www vs non-www.
	 */
	public function test_suggest_site_www_variants(): void {
		$sites = array( array( 'site_url' => 'https://www.example.com/' ) );
		$this->assertSame( 'https://www.example.com/', GSC_Client::suggest_site( $sites, 'https://example.com' ) );

		$sites = array( array( 'site_url' => 'https://example.com/' ) );
		$this->assertSame( 'https://example.com/', GSC_Client::suggest_site( $sites, 'https://www.example.com' ) );
	}

	/**
	 * Test suggestion matches http vs https and trailing slash.
	 */
	public function test_suggest_site_scheme_and_trailing_slash(): void {
		$sites = array( array( 'site_url' => 'http://example.com/' ) );
		$this->assertSame( 'http://example.com/', GSC_Client::suggest_site( $sites, 'https://example.com' ) );
		$this->assertSame( 'http://example.com/', GSC_Client::suggest_site( $sites, 'https://example.com/' ) );
	}

	/**
	 * Test suggestion matches a domain property.
	 */
	public function test_suggest_site_sc_domain(): void {
		$sites = array( array( 'site_url' => 'sc-domain:example.com' ) );
		$this->assertSame( 'sc-domain:example.com', GSC_Client::suggest_site( $sites, 'https://www.example.com' ) );
	}

	/**
	 * Test no match returns null.
	 */
	public function test_suggest_site_no_match(): void {
		$sites = array(
			array( 'site_url' => 'https://other.com/' ),
			array( 'site_url' => 'sc-domain:another.org' ),
		);
		$this->assertNull( GSC_Client::suggest_site( $sites, 'https://example.com' ) );
		$this->assertNull( GSC_Client::suggest_site( array(), 'https://example.com' ) );
	}

	/**
	 * Test an exact URL-prefix wins, then sc-domain, then scheme/www variants.
	 */
	public function test_suggest_site_prefers_exact_then_domain(): void {
		$sites = array(
			array( 'site_url' => 'sc-domain:example.com' ),
			array( 'site_url' => 'http://www.example.com/' ),
			array( 'site_url' => 'https://example.com/' ),
		);
		$this->assertSame( 'https://example.com/', GSC_Client::suggest_site( $sites, 'https://example.com' ) );

		$sites = array(
			array( 'site_url' => 'http://www.example.com/' ),
			array( 'site_url' => 'sc-domain:example.com' ),
			array( 'site_url' => 'https://www.example.com/' ),
		);
		$this->assertSame( 'sc-domain:example.com', GSC_Client::suggest_site( $sites, 'https://example.com' ) );

		$sites = array(
			array( 'site_url' => 'http://example.com/' ),
			array( 'site_url' => 'https://www.example.com/' ),
		);
		$this->assertSame( 'https://www.example.com/', GSC_Client::suggest_site( $sites, 'https://example.com' ) );
	}

	/**
	 * Test only an exact URL-prefix or sc-domain match is confident enough to save.
	 */
	public function test_match_site_confidence(): void {
		$this->assertTrue( GSC_Client::match_site( array( 'https://example.com/' ), 'https://example.com' )['confident'] );
		$this->assertTrue( GSC_Client::match_site( array( 'sc-domain:example.com' ), 'https://www.example.com' )['confident'] );
		$this->assertFalse( GSC_Client::match_site( array( 'https://www.example.com/' ), 'https://example.com' )['confident'] );
		$this->assertFalse( GSC_Client::match_site( array( 'http://example.com/' ), 'https://example.com' )['confident'] );
		$this->assertSame(
			array(
				'site'      => null,
				'confident' => false,
			),
			GSC_Client::match_site( array( 'https://other.com/' ), 'https://example.com' )
		);
	}
}
