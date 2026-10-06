<?php
/**
 * Tests for the WordPress content and SEO abilities and platform.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Abilities\Abilities_Registrar;
use Specflux_Marketing_Analytics\Abilities\Content_Abilities;
use Specflux_Marketing_Analytics\Admin\Post_Stats;
use Specflux_Marketing_Analytics\Content\Content_Inventory;
use Specflux_Marketing_Analytics\Content\Content_Platform;
use Specflux_Marketing_Analytics\Content\Seo_Plugin_Reader;
use Specflux_Marketing_Analytics\Credentials\Connection_Tester;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;

/**
 * Inventory returning scripted items and recording the query arguments.
 */
class Content_Inventory_Double extends Content_Inventory {

	/**
	 * Items returned (before include filtering).
	 *
	 * @var array[]
	 */
	public $items = array();

	/**
	 * Last arguments received.
	 *
	 * @var array
	 */
	public $last_args = array();

	/**
	 * Return scripted items.
	 *
	 * @param array $args Arguments.
	 * @return array[]
	 */
	public function query( array $args = array() ) {
		$this->last_args = $args;
		$items           = $this->items;
		if ( ! empty( $args['include'] ) ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( $item ) use ( $args ) {
						return in_array( $item['post_id'], $args['include'], true );
					}
				)
			);
		}
		return $items;
	}
}

/**
 * Post_Stats with scripted connections and a warm cache; no API calls.
 */
class Post_Stats_Double extends Post_Stats {

	/**
	 * Connected platforms.
	 *
	 * @var string[]
	 */
	public $platforms = array( 'ga4', 'gsc' );

	/**
	 * Cache payload.
	 *
	 * @var array|null
	 */
	public $cache = null;

	/**
	 * Constructor without credentials.
	 */
	public function __construct() {}

	/**
	 * Scripted platforms.
	 *
	 * @return string[]
	 */
	public function get_connected_platforms() {
		return $this->platforms;
	}

	/**
	 * Scripted cache.
	 *
	 * @return array|null
	 */
	public function get_cached() {
		return $this->cache;
	}

	/**
	 * Build never calls Google in tests.
	 *
	 * @return array
	 */
	public function build_cache() {
		return $this->cache ?? array(
			'platforms' => array(),
			'ga4'       => array(),
			'gsc'       => array(),
			'errors'    => array(),
			'capped'    => array(),
		);
	}
}

/**
 * Reader whose "active plugin" constants are scripted.
 */
class Content_Reader_Double extends Seo_Plugin_Reader {

	/**
	 * Constants treated as defined.
	 *
	 * @var string[]
	 */
	public $constants = array();

	/**
	 * Scripted constant check.
	 *
	 * @param string $name Constant.
	 * @return bool
	 */
	protected function has_constant( $name ) {
		return in_array( $name, $this->constants, true );
	}

	/**
	 * No SEO plugin functions exist in tests.
	 *
	 * @param string $name Function.
	 * @return bool
	 */
	protected function has_function( $name ) {
		return false;
	}
}

/**
 * Content abilities tests.
 */
class ContentAbilitiesTest extends TestCase {

	/**
	 * Reset mocks.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_posts, $mock_post_meta;
		$mock_posts     = array();
		$mock_post_meta = array();
		Abilities_Registrar::reset();
		Platform_Registry::reset();
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		Platform_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Build an inventory item and register the post in the mocks.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $path     URL path.
	 * @param string $modified Modified date.
	 * @return array
	 */
	private function item( $post_id, $path, $modified = '2026-09-01' ) {
		global $mock_posts;
		$url                  = 'https://example.com' . $path;
		$mock_posts[ $post_id ] = array(
			'status' => 'publish',
			'url'    => $url,
			'title'  => 'Post ' . $post_id,
		);

		return array(
			'post_id'       => $post_id,
			'title'         => 'Post ' . $post_id,
			'url'           => $url,
			'path'          => rtrim( $path, '/' ),
			'type'          => 'post',
			'published'     => '2026-01-01',
			'modified'      => $modified,
			'author'        => 'Ann',
			'categories'    => array(),
			'word_count'    => 500,
			'comment_count' => 0,
		);
	}

	/**
	 * Decode a tool response.
	 *
	 * @param array $response Tool result.
	 * @return array
	 */
	private function decode( array $response ) {
		$this->assertArrayNotHasKey( 'isError', $response );
		return json_decode( $response['content'][0]['text'], true );
	}

	/**
	 * Warm cache with GA4 and GSC data.
	 *
	 * @return array
	 */
	private function warm_cache() {
		return array(
			'platforms' => array( 'ga4', 'gsc' ),
			'ga4'       => array(
				'/a' => 900,
				'/b' => 100,
				'/c' => 5,
			),
			'gsc'       => array(
				'/a' => array( 10, 5000, 6.0 ),
				'/b' => array( 40, 600, 4.0 ),
				'/c' => array( 0, 20, 12.0 ),
			),
			'errors'    => array(),
			'capped'    => array(),
		);
	}

	/**
	 * Build abilities around the doubles.
	 *
	 * @param Content_Inventory_Double $inventory Inventory.
	 * @param Post_Stats_Double        $stats     Stats.
	 * @param Seo_Plugin_Reader|null   $reader    Reader.
	 * @param callable|null            $gsc       GSC client factory.
	 * @return Content_Abilities
	 */
	private function abilities( $inventory, $stats, $reader = null, $gsc = null ) {
		return new Content_Abilities( $inventory, $reader ? $reader : new Content_Reader_Double(), $stats, $gsc );
	}

	/**
	 * All four abilities register with a permission callback and read-only annotations.
	 */
	public function test_registers_four_abilities(): void {
		( new Content_Abilities() )->register();
		$tools = Abilities_Registrar::get_registered_tools();

		foreach ( array( 'get-content-inventory', 'get-content-performance', 'get-seo-metadata', 'find-seo-issues' ) as $name ) {
			$this->assertArrayHasKey( 'marketing-analytics/' . $name, $tools );
			$config = $tools[ 'marketing-analytics/' . $name ];
			$this->assertSame( array( Abilities_Registrar::class, 'can_access' ), $config['permission_callback'] );
			$this->assertTrue( $config['meta']['annotations']['readonly'] );
			$this->assertNotSame( '', $config['description'] );
		}
	}

	/**
	 * Inventory passes filters through and states the period.
	 */
	public function test_inventory_filters_and_period(): void {
		$inventory        = new Content_Inventory_Double();
		$inventory->items = array( $this->item( 1, '/a/' ) );
		$abilities        = $this->abilities( $inventory, new Post_Stats_Double() );

		$data = $this->decode( $abilities->execute_get_content_inventory( array( 'post_type' => 'page', 'start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'limit' => 5 ) ) );

		$this->assertSame( 'published 2026-01-01 to 2026-06-30', $data['period'] );
		$this->assertSame( 'page', $inventory->last_args['post_type'] );
		$this->assertSame( 1, $data['count'] );
	}

	/**
	 * A malformed date returns the standard error response.
	 */
	public function test_invalid_date_is_an_error(): void {
		$abilities = $this->abilities( new Content_Inventory_Double(), new Post_Stats_Double() );
		$response  = $abilities->execute_get_content_inventory( array( 'start_date' => '01/02/2026' ) );

		$this->assertTrue( $response['isError'] );
		$this->assertStringContainsString( 'Y-m-d', $response['content'][0]['text'] );
	}

	/**
	 * Performance joins GA4 and GSC per path through Post_Stats and sorts.
	 */
	public function test_performance_joins_post_stats_and_sorts(): void {
		$inventory        = new Content_Inventory_Double();
		$inventory->items = array( $this->item( 1, '/c/' ), $this->item( 2, '/a/' ), $this->item( 3, '/b/' ) );
		$stats            = new Post_Stats_Double();
		$stats->cache     = $this->warm_cache();
		$abilities        = $this->abilities( $inventory, $stats );

		$by_views  = $this->decode( $abilities->execute_get_content_performance( array() ) );
		$by_clicks = $this->decode( $abilities->execute_get_content_performance( array( 'sort_by' => 'clicks' ) ) );

		$this->assertSame( array( 2, 3, 1 ), array_column( $by_views['items'], 'post_id' ) );
		$top = $by_views['items'][0]['metrics'];
		$this->assertSame( 900, $top['views'] );
		$this->assertSame( 10, $top['clicks'] );
		$this->assertSame( 5000, $top['impressions'] );
		$this->assertEquals( 0.002, $top['ctr'] );
		$this->assertEquals( 6.0, $top['position'] );
		$this->assertSame( array( 3, 2, 1 ), array_column( $by_clicks['items'], 'post_id' ) );
		$this->assertStringContainsString( 'last 28 days', $by_views['period'] );
		$this->assertSame( 'ok', $by_views['data_status']['gsc'] );
	}

	/**
	 * stale_days keeps only content not modified within the window.
	 */
	public function test_performance_stale_days_filter(): void {
		$inventory        = new Content_Inventory_Double();
		$inventory->items = array(
			$this->item( 1, '/a/', gmdate( 'Y-m-d', strtotime( '-10 days' ) ) ),
			$this->item( 2, '/b/', gmdate( 'Y-m-d', strtotime( '-400 days' ) ) ),
		);
		$stats            = new Post_Stats_Double();
		$stats->cache     = $this->warm_cache();

		$data = $this->decode( $this->abilities( $inventory, $stats )->execute_get_content_performance( array( 'stale_days' => 365 ) ) );

		$this->assertSame( array( 2 ), array_column( $data['items'], 'post_id' ) );
		$this->assertSame( 365, $data['stale_days'] );
	}

	/**
	 * With no analytics connected the metrics are null rather than zero.
	 */
	public function test_performance_without_analytics(): void {
		$inventory        = new Content_Inventory_Double();
		$inventory->items = array( $this->item( 1, '/a/' ) );
		$stats            = new Post_Stats_Double();
		$stats->platforms = array();

		$data = $this->decode( $this->abilities( $inventory, $stats )->execute_get_content_performance( array() ) );

		$this->assertNull( $data['items'][0]['metrics']['views'] );
		$this->assertNull( $data['items'][0]['metrics']['ctr'] );
		$this->assertSame( 'not_connected', $data['data_status']['ga4'] );
	}

	/**
	 * get-seo-metadata reports the plugin, per-post fields and skipped IDs.
	 */
	public function test_seo_metadata_for_post_ids(): void {
		global $mock_post_meta;
		$mock_post_meta[1] = array(
			'_yoast_wpseo_title'    => 'Custom title',
			'_yoast_wpseo_metadesc' => '',
		);
		$inventory         = new Content_Inventory_Double();
		$inventory->items  = array( $this->item( 1, '/a/' ) );
		$reader            = new Content_Reader_Double();
		$reader->constants = array( 'WPSEO_VERSION' );

		$data = $this->decode( $this->abilities( $inventory, new Post_Stats_Double(), $reader )->execute_get_seo_metadata( array( 'post_ids' => array( 1, 99 ) ) ) );

		$this->assertSame( 'Yoast SEO', $data['seo_plugin']['label'] );
		$this->assertSame( 'Custom title', $data['items'][0]['seo']['title'] );
		$this->assertSame( 'default_template', $data['items'][0]['seo']['description_source'] );
		$this->assertSame( array( 99 ), $data['skipped_not_published_or_not_found'] );
		$this->assertArrayNotHasKey( 'post_id', $data['items'][0]['seo'] );
	}

	/**
	 * No SEO plugin: the ability still answers, says so, and returns no fields as set.
	 */
	public function test_seo_metadata_without_seo_plugin(): void {
		$inventory        = new Content_Inventory_Double();
		$inventory->items = array( $this->item( 1, '/a/' ) );

		$data = $this->decode( $this->abilities( $inventory, new Post_Stats_Double() )->execute_get_seo_metadata( array( 'post_ids' => array( 1 ) ) ) );

		$this->assertNull( $data['seo_plugin'] );
		$this->assertStringContainsString( 'No supported SEO plugin', $data['note'] );
		$this->assertSame( '', $data['items'][0]['seo']['title'] );
	}

	/**
	 * find-seo-issues joins SEO fields, analytics and GSC page+query data.
	 */
	public function test_find_seo_issues_end_to_end(): void {
		global $mock_post_meta;
		$mock_post_meta[2] = array(
			'_yoast_wpseo_title'    => 'Title two',
			'_yoast_wpseo_metadesc' => '',
			'_yoast_wpseo_focuskw'  => 'blue widgets',
		);
		$mock_post_meta[3] = array(
			'_yoast_wpseo_title'               => 'Title three',
			'_yoast_wpseo_metadesc'            => str_repeat( 'd', 100 ),
			'_yoast_wpseo_focuskw'             => 'x',
			'_yoast_wpseo_meta-robots-noindex' => '1',
		);
		$inventory        = new Content_Inventory_Double();
		$inventory->items = array( $this->item( 2, '/a/' ), $this->item( 3, '/b/' ) );
		$stats            = new Post_Stats_Double();
		$stats->cache     = $this->warm_cache();
		$reader           = new Content_Reader_Double();
		$reader->constants = array( 'WPSEO_VERSION' );

		$gsc = static function () {
			return new class() {
				public $args = array();

				public function query_search_analytics( $range, $dimensions, $filters, $options ) {
					$this->args = $dimensions;
					return array(
						'rows' => array(
							array( 'keys' => array( 'https://example.com/a/', 'red gadgets' ), 'impressions' => 900 ),
							array( 'keys' => array( 'https://example.com/a/', 'gadget reviews' ), 'impressions' => 100 ),
						),
					);
				}
			};
		};

		$data = $this->decode( $this->abilities( $inventory, $stats, $reader, $gsc )->execute_find_seo_issues( array() ) );

		$by_post = array();
		foreach ( $data['issues'] as $issue ) {
			$by_post[ $issue['post_id'] ][] = $issue['issue'];
		}
		$this->assertContains( 'missing_meta_description', $by_post[2] );
		$this->assertContains( 'focus_keyword_not_in_top_queries', $by_post[2] );
		$this->assertContains( 'high_impressions_low_ctr', $by_post[2] );
		$this->assertContains( 'noindex_with_traffic', $by_post[3] );
		$this->assertSame( 'ran', $data['queries_check'] );
		$this->assertSame( 'Yoast SEO', $data['seo_plugin'] );
		$this->assertSame( 2, $data['pages_scanned'] );
		$this->assertStringContainsString( 'last 28 days', $data['period'] );
		$this->assertSame( 'high', $data['issues'][0]['severity'] );
	}

	/**
	 * Without GSC the query check is reported as skipped.
	 */
	public function test_find_seo_issues_without_gsc_skips_query_check(): void {
		$inventory         = new Content_Inventory_Double();
		$inventory->items  = array( $this->item( 1, '/a/' ) );
		$stats             = new Post_Stats_Double();
		$stats->platforms  = array();

		$data = $this->decode( $this->abilities( $inventory, $stats )->execute_find_seo_issues( array() ) );

		$this->assertStringContainsString( 'skipped', $data['queries_check'] );
		$this->assertStringContainsString( 'No supported SEO plugin', $data['note'] );
	}

	/**
	 * The platform registers as keyless and names the detected plugin.
	 */
	public function test_platform_registration_and_connection_test(): void {
		$reader            = new Content_Reader_Double();
		$reader->constants = array( 'RANK_MATH_VERSION' );
		$platform          = new Content_Platform( $reader );

		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = array( $platform, 'register_platform' );
		Platform_Registry::reset();

		$definition = Platform_Registry::get( 'wpcontent' );
		$this->assertSame( 'none', $definition['auth'] );
		$this->assertSame( 'WordPress Content & SEO', $definition['label'] );
		$this->assertStringContainsString( 'Rank Math SEO', $definition['description'] );
		$this->assertTrue( Platform_Registry::is_registered( 'gsc' ), 'Core platforms are preserved.' );

		$result = ( new Connection_Tester() )->test_connection( 'wpcontent' );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Rank Math SEO', $result['message'] );
		$this->assertSame( 'rankmath', $result['seo_plugin'] );

		$none = ( new Content_Platform( new Content_Reader_Double() ) )->test_connection();
		$this->assertTrue( $none['success'] );
		$this->assertStringContainsString( 'No supported SEO plugin', $none['message'] );
	}
}
