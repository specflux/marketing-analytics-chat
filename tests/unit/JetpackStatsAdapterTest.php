<?php
/**
 * Tests for the Jetpack Stats adapter (WPCOM_Stats is mocked).
 *
 * Response shapes follow Automattic\Jetpack\Stats\WPCOM_Stats in Jetpack 16.3.1
 * (see .scratch/integrations-roadmap/research/verify-a4.md).
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Content\Jetpack_Stats_Adapter;
use Specflux_Marketing_Analytics\Content\Local_Analytics_Platform;
use Specflux_Marketing_Analytics\Content\Local_Analytics_Reader;

/**
 * Scripted WPCOM_Stats.
 */
class Jetpack_Stats_Double {

	/**
	 * Responses by method (array or WP_Error).
	 *
	 * @var array
	 */
	public $responses = array();

	/**
	 * Calls made: method => args list.
	 *
	 * @var array
	 */
	public $calls = array();

	/**
	 * Visits.
	 *
	 * @param array $args Args.
	 * @return mixed
	 */
	public function get_visits( $args = array() ) {
		$this->calls['get_visits'][] = $args;
		return $this->responses['get_visits'] ?? array();
	}

	/**
	 * Top posts.
	 *
	 * @param array $args Args.
	 * @return mixed
	 */
	public function get_top_posts( $args = array() ) {
		$this->calls['get_top_posts'][] = $args;
		return $this->responses['get_top_posts'] ?? array();
	}

	/**
	 * Referrers.
	 *
	 * @param array $args Args.
	 * @return mixed
	 */
	public function get_referrers( $args = array() ) {
		$this->calls['get_referrers'][] = $args;
		return $this->responses['get_referrers'] ?? array();
	}
}

/**
 * Adapter with scripted environment.
 */
class Jetpack_Env_Adapter extends Jetpack_Stats_Adapter {

	/**
	 * Whether the stats class exists.
	 *
	 * @var bool
	 */
	public $class_exists = true;

	/**
	 * Whether connected.
	 *
	 * @var bool
	 */
	public $connected = true;

	/**
	 * Active constants.
	 *
	 * @var string[]
	 */
	public $constants = array( 'JETPACK__VERSION' );

	/**
	 * Client double.
	 *
	 * @var Jetpack_Stats_Double
	 */
	public $client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( new \stdClass() );
		$this->client = new Jetpack_Stats_Double();
	}

	/**
	 * Scripted.
	 *
	 * @param string $name Constant.
	 * @return bool
	 */
	protected function has_constant( $name ) {
		return in_array( $name, $this->constants, true );
	}

	/**
	 * Scripted.
	 *
	 * @return bool
	 */
	protected function stats_class_exists() {
		return $this->class_exists;
	}

	/**
	 * Scripted.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->connected;
	}

	/**
	 * Scripted.
	 *
	 * @return object
	 */
	protected function stats_client() {
		return $this->client;
	}
}

/**
 * Jetpack Stats adapter tests.
 */
class JetpackStatsAdapterTest extends TestCase {

	/**
	 * Range 2026-09-01 to 2026-09-03.
	 *
	 * @return array
	 */
	private function range() {
		return Local_Analytics_Reader::build_range(
			array(
				'start_date' => '2026-09-01',
				'end_date'   => '2026-09-03',
			)
		);
	}

	/**
	 * Visits response.
	 *
	 * @return array
	 */
	private function visits() {
		return array(
			'date'   => '2026-09-03',
			'unit'   => 'day',
			'fields' => array( 'period', 'views', 'visitors' ),
			'data'   => array(
				array( '2026-08-31', 99, 90 ),
				array( '2026-09-01', 10, 4 ),
				array( '2026-09-02', 20, 8 ),
				array( '2026-09-03', 0, 0 ),
			),
		);
	}

	/**
	 * Fresh transients per test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mock_transients'] = array();
	}

	/**
	 * Stats class missing: unsupported, no fatal, no data.
	 */
	public function test_class_missing(): void {
		$adapter               = new Jetpack_Env_Adapter();
		$adapter->class_exists = false;

		$this->assertFalse( $adapter->is_supported() );
		$this->assertFalse( $adapter->has_data() );
		$this->assertSame( 0, $adapter->totals( $this->range() )['pageviews'] );
		$this->assertSame( array(), $adapter->top_pages( $this->range(), 5 ) );
		$this->assertStringContainsString( 'WPCOM_Stats', $adapter->last_error() );
	}

	/**
	 * Not connected: graceful, API never called, reason given.
	 */
	public function test_not_connected(): void {
		$adapter            = new Jetpack_Env_Adapter();
		$adapter->connected = false;

		$this->assertFalse( $adapter->has_data() );
		$this->assertSame( array(), $adapter->referrers( $this->range(), 5 ) );
		$this->assertSame( array(), $adapter->daily( $this->range() ) );
		$this->assertArrayNotHasKey( 'get_visits', $adapter->client->calls );
		$this->assertStringContainsString( 'not connected to WordPress.com', $adapter->last_error() );

		$reader = new Local_Analytics_Reader( array( $adapter ) );
		$this->assertSame( '', $reader->get_label() );
		$this->assertArrayHasKey( 'Jetpack Stats', $reader->get_unreadable() );
		$test = ( new Local_Analytics_Platform( $reader ) )->test_connection();
		$this->assertFalse( $test['success'] );
		$this->assertStringContainsString( 'not connected to WordPress.com', $test['message'] );
	}

	/**
	 * WP_Error from the API: empty result, reason kept, error not cached.
	 */
	public function test_wp_error_from_api(): void {
		$adapter                             = new Jetpack_Env_Adapter();
		$adapter->client->responses['get_visits'] = new \WP_Error( 'stats_error', 'Failed to fetch Stats from WPCOM' );

		$this->assertSame( array(), $adapter->daily( $this->range() ) );
		$this->assertSame( 0, $adapter->totals( $this->range() )['visitors'] );
		$this->assertStringContainsString( 'Failed to fetch Stats', $adapter->last_error() );
		$this->assertSame( array(), $GLOBALS['mock_transients'] );
	}

	/**
	 * Empty data: no data, zero totals.
	 */
	public function test_empty_data(): void {
		$adapter = new Jetpack_Env_Adapter();
		$adapter->client->responses['get_visits'] = array(
			'fields' => array( 'period', 'views', 'visitors' ),
			'data'   => array(),
		);

		$this->assertFalse( $adapter->has_data() );
		$this->assertSame( 0, $adapter->totals( $this->range() )['pageviews'] );
		$this->assertSame( array(), $adapter->daily( $this->range() ) );
	}

	/**
	 * Visits are mapped, clipped to the range and summed.
	 */
	public function test_visits_mapped(): void {
		$adapter = new Jetpack_Env_Adapter();
		$adapter->client->responses['get_visits'] = $this->visits();

		$totals = $adapter->totals( $this->range() );
		$this->assertSame( 30, $totals['pageviews'] );
		$this->assertSame( 12, $totals['visitors'] );
		$this->assertNull( $totals['sessions'] );
		$this->assertSame( 'sum_of_daily', $totals['visitors_basis'] );
		$this->assertCount( 3, $adapter->daily( $this->range() ) );
		$this->assertSame(
			array(
				'date'      => '2026-09-02',
				'visitors'  => 8,
				'pageviews' => 20,
				'sessions'  => null,
			),
			$adapter->daily( $this->range() )[1]
		);

		$args = $adapter->client->calls['get_visits'][0];
		$this->assertSame( 'day', $args['unit'] );
		$this->assertSame( 3, $args['quantity'] );
		$this->assertSame( '2026-09-03', $args['date'] );
	}

	/**
	 * Top posts: summary block mapped to paths, merged and sorted.
	 */
	public function test_top_pages_from_summary_and_days(): void {
		$adapter = new Jetpack_Env_Adapter();
		$adapter->client->responses['get_top_posts'] = array(
			'days' => array(
				'2026-09-01' => array(
					'postviews' => array(
						array(
							'id'    => 1,
							'href'  => 'https://example.test/a/',
							'title' => 'A',
							'views' => 5,
						),
						array(
							'id'    => 2,
							'href'  => 'https://example.test/b/',
							'title' => 'B',
							'views' => 9,
						),
					),
				),
				'2026-09-02' => array(
					'postviews' => array(
						array(
							'id'    => 1,
							'href'  => 'https://example.test/a/',
							'title' => 'A',
							'views' => 7,
						),
					),
				),
			),
		);

		$items = $adapter->top_pages( $this->range(), 5 );
		$this->assertSame( '/a/', $items[0]['path'] );
		$this->assertSame( 12, $items[0]['pageviews'] );
		$this->assertSame( '/b/', $items[1]['path'] );

		$adapter->client->responses['get_top_posts'] = array(
			'summary' => array(
				'postviews' => array(
					array(
						'href'  => 'https://example.test/',
						'views' => 3,
					),
				),
			),
		);
		$GLOBALS['mock_transients']  = array();
		$adapter2 = new Jetpack_Env_Adapter();
		$adapter2->client->responses = $adapter->client->responses;
		$this->assertSame( '/', $adapter2->top_pages( $this->range(), 5 )[0]['path'] );
	}

	/**
	 * Referrers: groups mapped to hosts and merged.
	 */
	public function test_referrers_mapped(): void {
		$adapter = new Jetpack_Env_Adapter();
		$adapter->client->responses['get_referrers'] = array(
			'days' => array(
				'2026-09-01' => array(
					'groups' => array(
						array(
							'group' => 'google.com',
							'name'  => 'Google Search',
							'total' => 40,
						),
						array(
							'group' => 'www.facebook.com',
							'name'  => 'www.facebook.com',
							'total' => 10,
						),
						array(
							'group' => 'facebook.com',
							'name'  => 'facebook.com',
							'total' => 5,
						),
					),
				),
			),
		);

		$items = $adapter->referrers( $this->range(), 5 );
		$this->assertSame( 'Google Search', $items[0]['source'] );
		$this->assertSame( 40, $items[0]['pageviews'] );
		$this->assertSame( 'facebook.com', $items[1]['source'] );
		$this->assertSame( 15, $items[1]['pageviews'] );
		$this->assertNull( $items[1]['visitors'] );
	}

	/**
	 * Second identical read is served from the transient, not the API.
	 */
	public function test_transient_cache(): void {
		$adapter = new Jetpack_Env_Adapter();
		$adapter->client->responses['get_visits'] = $this->visits();
		$adapter->totals( $this->range() );

		$again                                  = new Jetpack_Env_Adapter();
		$again->client->responses['get_visits'] = array();
		$this->assertSame( 30, $again->totals( $this->range() )['pageviews'] );
		$this->assertArrayNotHasKey( 'get_visits', $again->client->calls );
	}

	/**
	 * Reader and connection test report which plugin answered.
	 */
	public function test_reader_and_connection_test_name_provider(): void {
		$adapter = new Jetpack_Env_Adapter();
		$adapter->client->responses['get_visits'] = array(
			'fields' => array( 'period', 'views', 'visitors' ),
			'data'   => array( array( ( new \DateTimeImmutable( 'yesterday', wp_timezone() ) )->format( 'Y-m-d' ), 10, 4 ) ),
		);
		$reader  = new Local_Analytics_Reader( array( $adapter ) );

		$this->assertSame( 'jetpack-stats', $reader->detect() );
		$result = ( new Local_Analytics_Platform( $reader ) )->test_connection();
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Jetpack plugin', $result['message'] );

		$adapter->constants = array( 'JETPACK_STATS_PLUGIN__VERSION' );
		$this->assertSame( 'Jetpack Stats plugin', $adapter->provider() );

		$adapter->client->responses['get_top_posts'] = array();
		$page = $reader->get_top_pages( $this->range(), 5 );
		$this->assertSame( 'Jetpack Stats plugin', $page['source']['answered_by'] );
	}
}
