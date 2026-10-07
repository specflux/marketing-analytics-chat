<?php
/**
 * Tests for the site analytics plugin reader, platform and abilities.
 *
 * Row shapes are modelled on the verified plugin schemas (see the reader's file docblock).
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Abilities\Abilities_Registrar;
use Specflux_Marketing_Analytics\Abilities\Local_Analytics_Abilities;
use Specflux_Marketing_Analytics\Content\Burst_Statistics_Adapter;
use Specflux_Marketing_Analytics\Content\Independent_Analytics_Adapter;
use Specflux_Marketing_Analytics\Content\Jetpack_Stats_Adapter;
use Specflux_Marketing_Analytics\Content\Koko_Analytics_Adapter;
use Specflux_Marketing_Analytics\Content\Local_Analytics_Platform;
use Specflux_Marketing_Analytics\Content\Local_Analytics_Reader;

/**
 * Scripted wpdb: tables that exist, and rows returned for queries containing a needle.
 */
class Local_Analytics_Db_Double {

	/**
	 * Prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Existing tables (full names).
	 *
	 * @var string[]
	 */
	public $tables = array();

	/**
	 * Map of SQL needle => rows.
	 *
	 * @var array
	 */
	public $results = array();

	/**
	 * Map of SQL needle => scalar.
	 *
	 * @var array
	 */
	public $vars = array();

	/**
	 * Every SQL string run.
	 *
	 * @var string[]
	 */
	public $log = array();

	/**
	 * Escape LIKE.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Substitute placeholders so tests can assert the bound values.
	 *
	 * @param string $query Query.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		$args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		$i    = 0;

		return preg_replace_callback(
			'/%[sd]/',
			static function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $value : "'" . $value . "'";
			},
			$query
		);
	}

	/**
	 * Scalar lookup.
	 *
	 * @param string $query Query.
	 * @return mixed
	 */
	public function get_var( $query ) {
		$this->log[] = $query;
		if ( 0 === strpos( $query, 'SHOW TABLES LIKE' ) ) {
			$query = str_replace( '\\', '', $query );
			foreach ( $this->tables as $table ) {
				if ( false !== strpos( $query, "'" . $table . "'" ) ) {
					return $table;
				}
			}
			return null;
		}
		foreach ( $this->vars as $needle => $value ) {
			if ( false !== strpos( $query, $needle ) ) {
				return $value;
			}
		}
		return null;
	}

	/**
	 * Rows lookup.
	 *
	 * @param string $query Query.
	 * @return array
	 */
	public function get_results( $query ) {
		$this->log[] = $query;
		foreach ( $this->results as $needle => $rows ) {
			if ( false !== strpos( $query, $needle ) ) {
				return array_map(
					static function ( $row ) {
						return (object) $row;
					},
					$rows
				);
			}
		}
		return array();
	}
}

/**
 * Scripted constants for adapters.
 */
trait Local_Analytics_Constants {

	/**
	 * Active constant names.
	 *
	 * @var string[]
	 */
	public static $active = array();

	/**
	 * Scripted constant check.
	 *
	 * @param string $name Constant.
	 * @return bool
	 */
	protected function has_constant( $name ) {
		return in_array( $name, self::$active, true );
	}
}

/**
 * Koko with scripted constants.
 */
class Koko_Test_Adapter extends Koko_Analytics_Adapter {
	use Local_Analytics_Constants;
}

/**
 * Burst with scripted constants.
 */
class Burst_Test_Adapter extends Burst_Statistics_Adapter {
	use Local_Analytics_Constants;
}

/**
 * IAWP with scripted constants.
 */
class Iawp_Test_Adapter extends Independent_Analytics_Adapter {
	use Local_Analytics_Constants;
}

/**
 * Jetpack with scripted constants.
 */
class Jetpack_Test_Adapter extends Jetpack_Stats_Adapter {
	use Local_Analytics_Constants;
}

/**
 * Local analytics tests.
 */
class LocalAnalyticsTest extends TestCase {

	/**
	 * Range used by adapter tests (2026-09-01 to 2026-09-07, UTC site).
	 *
	 * @return array
	 */
	private function range() {
		return Local_Analytics_Reader::build_range(
			array(
				'start_date' => '2026-09-01',
				'end_date'   => '2026-09-07',
			)
		);
	}

	/**
	 * Reset scripted state.
	 */
	protected function setUp(): void {
		parent::setUp();
		Abilities_Registrar::reset();
		Koko_Test_Adapter::$active    = array();
		Burst_Test_Adapter::$active   = array();
		Iawp_Test_Adapter::$active    = array();
		Jetpack_Test_Adapter::$active = array();
	}

	/**
	 * Koko database with all tables and one hit.
	 *
	 * @return Local_Analytics_Db_Double
	 */
	private function koko_db() {
		$db         = new Local_Analytics_Db_Double();
		$db->tables = array(
			'wp_koko_analytics_site_stats',
			'wp_koko_analytics_post_stats',
			'wp_koko_analytics_paths',
			'wp_koko_analytics_referrer_stats',
			'wp_koko_analytics_referrer_labels',
		);
		$db->vars   = array( 'SELECT date FROM wp_koko_analytics_site_stats LIMIT 1' => '2026-09-01' );

		return $db;
	}

	/**
	 * Default range ends yesterday and spans 28 days; invalid input throws.
	 */
	public function test_build_range_defaults_and_validation(): void {
		$range = Local_Analytics_Reader::build_range( array() );
		$this->assertSame( 28, $range['days'] );
		$this->assertSame( gmdate( 'Y-m-d', strtotime( '-1 day' ) ), $range['end'] );

		$this->expectException( \InvalidArgumentException::class );
		Local_Analytics_Reader::build_range( array( 'start_date' => '2026-13-45' ) );
	}

	/**
	 * Start after end throws.
	 */
	public function test_build_range_rejects_reversed_dates(): void {
		$this->expectException( \InvalidArgumentException::class );
		Local_Analytics_Reader::build_range(
			array(
				'start_date' => '2026-09-10',
				'end_date'   => '2026-09-01',
			)
		);
	}

	/**
	 * Detection needs the constant AND recorded data.
	 */
	public function test_detection_requires_constant_and_data(): void {
		$db   = $this->koko_db();
		$koko = new Koko_Test_Adapter( $db );

		$this->assertFalse( $koko->is_active() );

		Koko_Test_Adapter::$active = array( 'KOKO_ANALYTICS_VERSION' );
		$this->assertTrue( $koko->is_active() );
		$this->assertTrue( $koko->has_data() );
		$this->assertSame( 'koko-analytics', ( new Local_Analytics_Reader( array( $koko ) ) )->detect() );

		$empty = new Koko_Test_Adapter( new Local_Analytics_Db_Double() );
		$this->assertFalse( $empty->has_data(), 'No table means no data.' );
		$this->assertSame( '', ( new Local_Analytics_Reader( array( $empty ) ) )->detect() );
	}

	/**
	 * Burst, IAWP and Koko markers are the constants verified in the plugin sources.
	 */
	public function test_each_plugin_uses_its_verified_constant(): void {
		$map = array(
			Burst_Test_Adapter::class => 'BURST_VERSION',
			Iawp_Test_Adapter::class  => 'IAWP_VERSION',
			Koko_Test_Adapter::class  => 'KOKO_ANALYTICS_VERSION',
		);
		foreach ( $map as $class_name => $constant ) {
			$class_name::$active = array( $constant );
			$this->assertTrue( ( new $class_name( new Local_Analytics_Db_Double() ) )->is_active(), $constant );
		}
	}

	/**
	 * Jetpack is detected but unsupported and never becomes the active adapter.
	 */
	public function test_jetpack_is_detected_but_not_supported(): void {
		Jetpack_Test_Adapter::$active = array( 'JETPACK__VERSION' );
		$jetpack                      = new Jetpack_Test_Adapter( new Local_Analytics_Db_Double() );
		$reader                       = new Local_Analytics_Reader( array( $jetpack ) );

		$this->assertTrue( $jetpack->is_active() );
		$this->assertFalse( $jetpack->is_supported() );
		$this->assertSame( '', $reader->detect() );
		$this->assertArrayHasKey( 'Jetpack Stats', $reader->get_unsupported() );
		$this->assertStringContainsString( 'not supported yet', $reader->get_unsupported()['Jetpack Stats'] );
	}

	/**
	 * Koko daily, pages and referrers: shape, SQL and host merging.
	 */
	public function test_koko_adapter_shapes(): void {
		$db                        = $this->koko_db();
		$db->results               = array(
			'FROM wp_koko_analytics_site_stats s'  => array(),
			'FROM wp_koko_analytics_site_stats'    => array(
				array(
					'date'      => '2026-09-01',
					'visitors'  => '5',
					'pageviews' => '9',
				),
				array(
					'date'      => '2026-09-03',
					'visitors'  => '2',
					'pageviews' => '3',
				),
			),
			'FROM wp_koko_analytics_post_stats s'  => array(
				array(
					'path'      => '/about/',
					'pageviews' => '7',
					'visitors'  => '4',
				),
			),
			'FROM wp_koko_analytics_referrer_stats s' => array(
				array(
					'source'    => 'https://www.google.com/',
					'pageviews' => '4',
					'visitors'  => '3',
				),
				array(
					'source'    => 'google.com',
					'pageviews' => '2',
					'visitors'  => '1',
				),
				array(
					'source'    => 'https://news.ycombinator.com/item?id=1',
					'pageviews' => '5',
					'visitors'  => '5',
				),
			),
		);
		Koko_Test_Adapter::$active = array( 'KOKO_ANALYTICS_VERSION' );
		$adapter                   = new Koko_Test_Adapter( $db );
		$range                     = $this->range();

		$daily = $adapter->daily( $range );
		$this->assertSame(
			array(
				'date'      => '2026-09-01',
				'visitors'  => 5,
				'pageviews' => 9,
				'sessions'  => null,
			),
			$daily[0]
		);
		$totals = $adapter->totals( $range );
		$this->assertSame( 7, $totals['visitors'] );
		$this->assertSame( 12, $totals['pageviews'] );
		$this->assertSame( 'sum_of_daily_visitors', $totals['visitors_basis'] );

		$pages = $adapter->top_pages( $range, 5 );
		$this->assertSame( '/about/', $pages[0]['path'] );
		$this->assertSame( 7, $pages[0]['pageviews'] );
		$page_sql = end( $db->log );
		$this->assertStringContainsString( "BETWEEN '2026-09-01' AND '2026-09-07'", $page_sql );
		$this->assertStringContainsString( 'LIMIT 5', $page_sql );

		$sources = $adapter->referrers( $range, 5 );
		$this->assertSame( 'google.com', $sources[0]['source'] );
		$this->assertSame( 6, $sources[0]['pageviews'], 'www.google.com and google.com merge.' );
		$this->assertSame( 4, $sources[0]['visitors'] );
		$this->assertSame( 'news.ycombinator.com', $sources[1]['source'] );
	}

	/**
	 * Koko queries return empty arrays when its tables are missing.
	 */
	public function test_koko_missing_tables_return_empty(): void {
		$adapter = new Koko_Test_Adapter( new Local_Analytics_Db_Double() );
		$range   = $this->range();

		$this->assertSame( array(), $adapter->daily( $range ) );
		$this->assertSame( array(), $adapter->top_pages( $range, 5 ) );
		$this->assertSame( array(), $adapter->referrers( $range, 5 ) );
	}

	/**
	 * Burst: unix-time bounds, 404 exclusion, session based referrers.
	 */
	public function test_burst_adapter_shapes(): void {
		$db          = new Local_Analytics_Db_Double();
		$db->tables  = array( 'wp_burst_statistics', 'wp_burst_sessions' );
		$db->results = array(
			'FLOOR((s.time'               => array(
				array(
					'day_index' => '20697',
					'pageviews' => '12',
					'visitors'  => '4',
					'sessions'  => '5',
				),
			),
			'COUNT(s.ID) AS pageviews, COUNT(DISTINCT NULLIF(s.uid_id, 0)) AS visitors, COUNT(DISTINCT s.session_id)' => array(
				array(
					'pageviews' => '30',
					'visitors'  => '9',
					'sessions'  => '11',
				),
			),
			'GROUP BY s.page_url'         => array(
				array(
					'path'      => '/pricing/',
					'pageviews' => '8',
					'visitors'  => '3',
				),
			),
			'FROM wp_burst_sessions se'   => array(
				array(
					'source'   => 'https://duckduckgo.com/',
					'sessions' => '6',
					'visitors' => '5',
				),
			),
		);
		Burst_Test_Adapter::$active = array( 'BURST_VERSION' );
		$adapter                    = new Burst_Test_Adapter( $db );
		$range                      = $this->range();

		$totals = $adapter->totals( $range );
		$this->assertSame( 30, $totals['pageviews'] );
		$this->assertSame( 9, $totals['visitors'] );
		$this->assertSame( 11, $totals['sessions'] );
		$this->assertStringContainsString( "page_type <> '404'", end( $db->log ) );
		$this->assertStringContainsString( 's.time >= ' . $range['start_ts'], end( $db->log ) );

		$daily = $adapter->daily( $range );
		$this->assertSame( gmdate( 'Y-m-d', 20697 * 86400 ), $daily[0]['date'] );
		$this->assertSame( 5, $daily[0]['sessions'] );

		$this->assertSame( '/pricing/', $adapter->top_pages( $range, 3 )[0]['path'] );

		$sources = $adapter->referrers( $range, 3 );
		$this->assertSame( 'duckduckgo.com', $sources[0]['source'] );
		$this->assertSame( 6, $sources[0]['sessions'] );
		$this->assertNull( $sources[0]['pageviews'] );
	}

	/**
	 * Independent Analytics: UTC bounds, path from cached URL, Direct excluded.
	 */
	public function test_independent_analytics_adapter_shapes(): void {
		$db          = new Local_Analytics_Db_Double();
		$db->tables  = array(
			'wp_independent_analytics_views',
			'wp_independent_analytics_sessions',
			'wp_independent_analytics_resources',
			'wp_independent_analytics_referrers',
			'wp_independent_analytics_referrer_types',
		);
		$db->results = array(
			'GROUP BY day'                   => array(
				array(
					'day'       => '2026-09-02',
					'pageviews' => '10',
					'visitors'  => '4',
					'sessions'  => '6',
				),
			),
			'FROM wp_independent_analytics_resources' => array(),
			'JOIN wp_independent_analytics_resources r' => array(
				array(
					'resource_id' => '3',
					'url'         => 'https://example.com/blog/hello/?utm=1',
					'title'       => 'Hello',
					'pageviews'   => '14',
					'visitors'    => '9',
				),
				array(
					'resource_id' => '4',
					'url'         => '',
					'title'       => 'Home',
					'pageviews'   => '2',
					'visitors'    => '2',
				),
			),
			'independent_analytics_referrer_types rt' => array(
				array(
					'source'   => 'reddit.com',
					'sessions' => '3',
					'visitors' => '3',
				),
			),
		);
		Iawp_Test_Adapter::$active = array( 'IAWP_VERSION' );
		$adapter                   = new Iawp_Test_Adapter( $db );
		$range                     = $this->range();

		$daily = $adapter->daily( $range );
		$this->assertSame( '2026-09-02', $daily[0]['date'] );
		$this->assertStringContainsString( "viewed_at >= '2026-09-01 00:00:00' AND v.viewed_at < '2026-09-08 00:00:00'", end( $db->log ) );

		$pages = $adapter->top_pages( $range, 5 );
		$this->assertSame( '/blog/hello/?utm=1', $pages[0]['path'] );
		$this->assertSame( 'Home', $pages[1]['path'] );

		$sources = $adapter->referrers( $range, 5 );
		$this->assertSame( 'reddit.com', $sources[0]['source'] );
		$this->assertStringContainsString( "referrer_type <> 'Direct'", end( $db->log ) );
	}

	/**
	 * Reader fills missing days, computes change vs the previous period, and names the plugin.
	 */
	public function test_reader_traffic_shape_and_change(): void {
		$stub   = new class( new Local_Analytics_Db_Double() ) extends Koko_Analytics_Adapter {
			/**
			 * Active.
			 *
			 * @return bool
			 */
			public function is_active() {
				return true;
			}

			/**
			 * Has data.
			 *
			 * @return bool
			 */
			public function has_data() {
				return true;
			}

			/**
			 * Totals: current period 200 views, previous 100.
			 *
			 * @param array $range Range.
			 * @return array
			 */
			public function totals( array $range ) {
				return array(
					'visitors'       => $range['end'] === '2026-09-07' ? 60 : 50,
					'pageviews'      => $range['end'] === '2026-09-07' ? 200 : 100,
					'sessions'       => null,
					'visitors_basis' => 'sum_of_daily_visitors',
				);
			}

			/**
			 * Daily: one day only.
			 *
			 * @param array $range Range.
			 * @return array
			 */
			public function daily( array $range ) {
				unset( $range );
				return array(
					array(
						'date'      => '2026-09-02',
						'visitors'  => 7,
						'pageviews' => 20,
						'sessions'  => null,
					),
				);
			}
		};
		$reader = new Local_Analytics_Reader( array( $stub ) );
		$data   = $reader->get_traffic( $this->range() );

		$this->assertSame( 'Koko Analytics', $data['source']['plugin'] );
		$this->assertCount( 7, $data['daily'] );
		$this->assertSame( 0, $data['daily'][0]['pageviews'] );
		$this->assertSame( 20, $data['daily'][1]['pageviews'] );
		$this->assertSame( 100.0, $data['change_percent']['pageviews'] );
		$this->assertSame( 20.0, $data['change_percent']['visitors'] );
		$this->assertNull( $data['change_percent']['sessions'] );
		$this->assertSame( '2026-08-25 to 2026-08-31', $data['previous_period'] );
	}

	/**
	 * Nothing is registered, and the platform is absent, without a supported plugin.
	 */
	public function test_not_registered_without_a_plugin(): void {
		$reader = new Local_Analytics_Reader( array( new Koko_Test_Adapter( new Local_Analytics_Db_Double() ) ) );

		( new Local_Analytics_Abilities( $reader ) )->register();
		$this->assertSame( array(), Abilities_Registrar::get_registered_tools() );

		$platforms = ( new Local_Analytics_Platform( $reader ) )->register_platform( array( 'ga4' => array() ) );
		$this->assertSame( array( 'ga4' ), array_keys( $platforms ) );
	}

	/**
	 * With a plugin: platform definition, three abilities, all read-only annotated with the shared permission callback.
	 */
	public function test_registered_with_a_plugin(): void {
		Koko_Test_Adapter::$active = array( 'KOKO_ANALYTICS_VERSION' );
		$reader                    = new Local_Analytics_Reader( array( new Koko_Test_Adapter( $this->koko_db() ) ) );

		$platforms = ( new Local_Analytics_Platform( $reader ) )->register_platform( array() );
		$this->assertSame( 'none', $platforms['localstats']['auth'] );
		$this->assertStringContainsString( 'Koko Analytics', $platforms['localstats']['description'] );
		$this->assertStringContainsString( 'not GA4', $platforms['localstats']['prompt_hint'] );
		$this->assertTrue( ( new Local_Analytics_Platform( $reader ) )->test_connection()['success'] );

		( new Local_Analytics_Abilities( $reader ) )->register();
		$tools = Abilities_Registrar::get_registered_tools();
		foreach ( array( 'get-site-traffic', 'get-site-top-pages', 'get-site-referrers' ) as $name ) {
			$this->assertArrayHasKey( 'marketing-analytics/' . $name, $tools );
			$config = $tools[ 'marketing-analytics/' . $name ];
			$this->assertTrue( $config['meta']['annotations']['readonly'], $name );
			$this->assertFalse( $config['meta']['annotations']['destructive'], $name );
			$this->assertTrue( $config['meta']['annotations']['idempotent'], $name );
			$this->assertSame( array( Abilities_Registrar::class, 'can_access' ), $config['permission_callback'] );
			$this->assertStringContainsString( 'Koko Analytics', $config['description'] );
			$this->assertStringContainsString( 'Google Analytics 4 is not connected', $config['description'] );
		}
	}

	/**
	 * An ability reports a clear error for a bad date instead of throwing.
	 */
	public function test_ability_returns_error_for_bad_dates(): void {
		Koko_Test_Adapter::$active = array( 'KOKO_ANALYTICS_VERSION' );
		$reader                    = new Local_Analytics_Reader( array( new Koko_Test_Adapter( $this->koko_db() ) ) );
		$result                    = ( new Local_Analytics_Abilities( $reader ) )->execute_traffic( array( 'start_date' => 'nope' ) );

		$this->assertTrue( $result['isError'] );
	}
}
