<?php
/**
 * Tests for the Platform_Registry class.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Credentials\Connection_Tester;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;
use PHPUnit\Framework\TestCase;

/**
 * Platform registry test class.
 */
class PlatformRegistryTest extends TestCase {

	/**
	 * Reset registry, filters and options.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array();
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		Platform_Registry::reset();
	}

	/**
	 * Clean up the filter and cache.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		Platform_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Register a platform through the filter.
	 *
	 * @param array $definition Platform definition.
	 */
	private function register( array $definition ): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = static function ( $platforms ) use ( $definition ) {
			$platforms[ $definition['slug'] ] = $definition;
			return $platforms;
		};
		Platform_Registry::reset();
	}

	/**
	 * Core platforms are registered with the expected shape.
	 */
	public function test_defaults(): void {
		$this->assertSame( array( 'clarity', 'ga4', 'gsc' ), Platform_Registry::slugs() );

		$clarity = Platform_Registry::get( 'clarity' );
		$this->assertSame( 'api_key', $clarity['auth'] );
		$this->assertSame( array( 'api_token', 'project_id' ), $clarity['fields'] );
		$this->assertSame( HOUR_IN_SECONDS, $clarity['cache_ttl'] );

		$ga4 = Platform_Registry::get( 'ga4' );
		$this->assertSame( 'google_oauth', $ga4['auth'] );
		$this->assertSame( 30 * MINUTE_IN_SECONDS, $ga4['cache_ttl'] );
		$this->assertSame( array( 'https://www.googleapis.com/auth/analytics.readonly' ), $ga4['scopes'] );

		$gsc = Platform_Registry::get( 'gsc' );
		$this->assertSame( DAY_IN_SECONDS, $gsc['cache_ttl'] );
		$this->assertSame( array( 'https://www.googleapis.com/auth/webmasters.readonly' ), $gsc['scopes'] );

		$this->assertTrue( Platform_Registry::is_registered( 'gsc' ) );
		$this->assertFalse( Platform_Registry::is_registered( 'nope' ) );
		$this->assertNull( Platform_Registry::get( 'nope' ) );
	}

	/**
	 * A filter-registered platform appears with defaults filled in.
	 */
	public function test_filter_registers_platform(): void {
		$this->register(
			array(
				'slug'   => 'dataforseo',
				'label'  => 'DataForSEO',
				'auth'   => 'api_key',
				'fields' => array( 'login', 'password' ),
			)
		);

		$this->assertTrue( Platform_Registry::is_registered( 'dataforseo' ) );
		$this->assertSame( array( 'clarity', 'ga4', 'gsc', 'dataforseo' ), Platform_Registry::slugs() );

		$definition = Platform_Registry::get( 'dataforseo' );
		$this->assertSame( HOUR_IN_SECONDS, $definition['cache_ttl'] );
		$this->assertNull( $definition['connection_test'] );
		$this->assertTrue( $definition['tab'] );
	}

	/**
	 * Invalid slugs and auth types are handled.
	 */
	public function test_invalid_definitions_are_sanitised(): void {
		$this->register(
			array(
				'slug' => 'Bad Slug!',
			)
		);
		$this->assertFalse( Platform_Registry::is_registered( 'Bad Slug!' ) );

		$this->register(
			array(
				'slug' => 'weird',
				'auth' => 'telepathy',
			)
		);
		$this->assertSame( 'api_key', Platform_Registry::get( 'weird' )['auth'] );
	}

	/**
	 * Credential_Manager round-trips a filter-registered platform and rejects unknown ones.
	 */
	public function test_credential_manager_accepts_registered_platform(): void {
		$manager = new Credential_Manager();

		$this->assertFalse( $manager->save_credentials( 'dataforseo', array( 'login' => 'a' ) ) );

		$this->register(
			array(
				'slug'   => 'dataforseo',
				'label'  => 'DataForSEO',
				'fields' => array( 'login', 'password' ),
			)
		);

		$this->assertTrue( $manager->save_credentials( 'dataforseo', array( 'login' => 'me', 'password' => 'secret' ) ) );
		$this->assertTrue( $manager->has_credentials( 'dataforseo' ) );

		$stored = get_option( Credential_Manager::OPTION_PREFIX . 'dataforseo' );
		$this->assertStringNotContainsString( 'secret', is_string( $stored ) ? $stored : wp_json_encode( $stored ) );

		$retrieved = $manager->get_credentials( 'dataforseo' );
		$this->assertSame( 'me', $retrieved['login'] );
		$this->assertSame( 'secret', $retrieved['password'] );

		$this->assertArrayHasKey( 'dataforseo', $manager->get_all_statuses() );

		$this->assertTrue( $manager->delete_credentials( 'dataforseo' ) );
		$this->assertFalse( $manager->has_credentials( 'dataforseo' ) );
	}

	/**
	 * Connection_Tester uses a registered connection_test callable.
	 */
	public function test_connection_tester_uses_registered_callable(): void {
		$this->register(
			array(
				'slug'            => 'pagespeed',
				'label'           => 'PageSpeed',
				'auth'            => 'none',
				'connection_test' => static function () {
					return array(
						'success' => true,
						'message' => 'ok',
					);
				},
			)
		);

		$tester = new Connection_Tester();
		$this->assertTrue( $tester->test_connection( 'pagespeed' )['success'] );
		$this->assertSame( 'connected', $tester->get_connection_status( 'pagespeed' ) );
		$this->assertFalse( $tester->test_connection( 'unknown' )['success'] );
		$this->assertSame( 'error', $tester->get_connection_status( 'unknown' ) );
	}

	/**
	 * Test results are remembered per platform and forgotten on disconnect.
	 */
	public function test_last_connection_result_is_remembered_and_forgotten(): void {
		$this->register(
			array(
				'slug'            => 'bingx',
				'auth'            => 'api_key',
				'fields'          => array( 'api_key' ),
				'connection_test' => static function () {
					return array(
						'success' => false,
						'message' => 'Rejected.',
					);
				},
			)
		);

		$tester = new Connection_Tester();
		$tester->test_connection( 'bingx' );
		$last = Connection_Tester::get_last_result( 'bingx' );
		$this->assertFalse( $last['success'] );
		$this->assertSame( 'Rejected.', $last['message'] );

		( new \Specflux_Marketing_Analytics\Credentials\Credential_Manager() )->delete_credentials( 'bingx' );
		$this->assertNull( Connection_Tester::get_last_result( 'bingx' ) );
	}

	/**
	 * Field labels capitalise acronyms and honour explicit labels.
	 */
	public function test_field_label(): void {
		$this->assertSame( 'API key', Platform_Registry::field_label( array(), 'api_key' ) );
		$this->assertSame( 'Site URL', Platform_Registry::field_label( array(), 'site_url' ) );
		$this->assertSame( 'Login', Platform_Registry::field_label( array( 'field_labels' => array( 'login' => 'Login' ) ), 'login' ) );
	}
}
