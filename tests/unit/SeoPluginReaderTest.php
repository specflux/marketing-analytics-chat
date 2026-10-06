<?php
/**
 * Tests for the SEO plugin reader.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Content\Seo_Plugin_Reader;

/**
 * Reader whose "active plugin" signals are scripted.
 */
class Seo_Plugin_Reader_Testable extends Seo_Plugin_Reader {

	/**
	 * Constants treated as defined.
	 *
	 * @var string[]
	 */
	public $constants = array();

	/**
	 * Functions treated as existing.
	 *
	 * @var string[]
	 */
	public $functions = array();

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
	 * Scripted function check.
	 *
	 * @param string $name Function.
	 * @return bool
	 */
	protected function has_function( $name ) {
		return in_array( $name, $this->functions, true );
	}
}

/**
 * Seo_Plugin_Reader tests.
 */
class SeoPluginReaderTest extends TestCase {

	/**
	 * Original $wpdb.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Reset mocks.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_post_meta, $mock_posts, $mock_options, $wpdb;
		$mock_post_meta      = array();
		$mock_posts          = array();
		$mock_options        = array();
		$this->original_wpdb = $wpdb;
	}

	/**
	 * Restore $wpdb.
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/**
	 * Build a reader with the given constants.
	 *
	 * @param string[] $constants Constants to treat as defined.
	 * @param string[] $functions Functions to treat as existing.
	 * @return Seo_Plugin_Reader_Testable
	 */
	private function reader( array $constants = array(), array $functions = array() ) {
		$reader            = new Seo_Plugin_Reader_Testable();
		$reader->constants = $constants;
		$reader->functions = $functions;
		return $reader;
	}

	/**
	 * Install a fake $wpdb with scripted tables and aioseo_posts rows.
	 *
	 * @param string[] $tables Existing tables.
	 * @param array    $rows   Rows returned by get_results().
	 * @return object The fake.
	 */
	private function fake_wpdb( array $tables, array $rows = array() ) {
		global $wpdb;
		$wpdb = new class( $tables, $rows ) {
			public $prefix = 'wp_';
			public $tables;
			public $rows;
			public $prepared = array();

			public function __construct( $tables, $rows ) {
				$this->tables = $tables;
				$this->rows   = $rows;
			}

			public function esc_like( $text ) {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( $query, ...$args ) {
				$args = is_array( $args[0] ?? null ) ? $args[0] : $args;
				$sql  = preg_replace_callback(
					'/%[sd]/',
					static function ( $m ) use ( &$args ) {
						$value = array_shift( $args );
						return '%d' === $m[0] ? (string) (int) $value : "'" . $value . "'";
					},
					$query
				);
				$this->prepared[] = $sql;
				return $sql;
			}

			public function get_var( $query ) {
				foreach ( $this->tables as $table ) {
					if ( false !== strpos( $query, "'" . addcslashes( $table, '_%\\' ) . "'" ) ) {
						return $table;
					}
				}
				return null;
			}

			public function get_results( $query ) {
				return $this->rows;
			}
		};
		return $wpdb;
	}

	/**
	 * Detection returns each plugin from its constant.
	 */
	public function test_detects_each_plugin(): void {
		$this->assertSame( 'yoast', $this->reader( array( 'WPSEO_VERSION' ) )->detect() );
		$this->assertSame( 'rankmath', $this->reader( array( 'RANK_MATH_VERSION' ) )->detect() );
		$this->assertSame( 'aioseo', $this->reader( array( 'AIOSEO_VERSION' ) )->detect() );
		$this->assertSame( 'aioseo', $this->reader( array(), array( 'aioseo' ) )->detect() );
		$this->assertSame( 'seopress', $this->reader( array( 'SEOPRESS_VERSION' ) )->detect() );
		$this->assertSame( 'Yoast SEO', $this->reader( array( 'WPSEO_VERSION' ) )->get_label() );
	}

	/**
	 * No SEO plugin: empty plugin, empty fields, no errors.
	 */
	public function test_no_seo_plugin_fallback(): void {
		$reader = $this->reader();
		$this->assertSame( '', $reader->detect() );
		$this->assertSame( '', $reader->get_label() );

		$fields = $reader->read( 5 );
		$this->assertSame( '', $fields['plugin'] );
		$this->assertSame( '', $fields['title'] );
		$this->assertSame( 'default_template', $fields['title_source'] );
		$this->assertSame( array(), $fields['focus_keywords'] );
		$this->assertNull( $fields['noindex'] );
		$this->assertNull( $fields['seo_score'] );
	}

	/**
	 * The storage map exposes title and description locations for a write-back add-on.
	 */
	public function test_storage_map_exposes_title_and_description_locations(): void {
		$map = $this->reader()->get_storage_map();

		$this->assertSame( array( 'yoast', 'rankmath', 'aioseo', 'seopress' ), array_keys( $map ) );
		$this->assertSame( array( 'type' => 'meta', 'key' => '_yoast_wpseo_title' ), $map['yoast']['title'] );
		$this->assertSame( array( 'type' => 'meta', 'key' => '_yoast_wpseo_metadesc' ), $map['yoast']['description'] );
		$this->assertSame( 'rank_math_title', $map['rankmath']['title']['key'] );
		$this->assertSame( 'rank_math_description', $map['rankmath']['description']['key'] );
		$this->assertSame( '_seopress_titles_title', $map['seopress']['title']['key'] );
		$this->assertSame( '_seopress_titles_desc', $map['seopress']['description']['key'] );
		$this->assertSame(
			array(
				'type'      => 'table',
				'table'     => 'aioseo_posts',
				'column'    => 'title',
				'id_column' => 'post_id',
			),
			$map['aioseo']['title']
		);
		$this->assertSame( 'description', $map['aioseo']['description']['column'] );
	}

	/**
	 * Yoast fields come from _yoast_wpseo_* meta.
	 */
	public function test_reads_yoast_fields(): void {
		global $mock_post_meta;
		$mock_post_meta[7] = array(
			'_yoast_wpseo_title'                => '%%title%% %%sep%% %%sitename%%',
			'_yoast_wpseo_metadesc'             => 'A description',
			'_yoast_wpseo_focuskw'              => 'blue widgets',
			'_yoast_wpseo_linkdex'              => '72',
			'_yoast_wpseo_meta-robots-noindex'  => '1',
			'_yoast_wpseo_canonical'            => 'https://example.com/canon/',
		);

		$fields = $this->reader( array( 'WPSEO_VERSION' ) )->read( 7 );

		$this->assertSame( 'yoast', $fields['plugin'] );
		$this->assertSame( '%%title%% %%sep%% %%sitename%%', $fields['title'] );
		$this->assertTrue( $fields['uses_template'] );
		$this->assertSame( 'A description', $fields['description'] );
		$this->assertSame( 'blue widgets', $fields['focus_keyword'] );
		$this->assertSame( 72, $fields['seo_score'] );
		$this->assertTrue( $fields['noindex'] );
		$this->assertSame( 'https://example.com/canon/', $fields['canonical'] );
	}

	/**
	 * Yoast noindex: 1 = noindex, 2 = index, 0 = post type default from wpseo_titles.
	 */
	public function test_yoast_noindex_parsing(): void {
		global $mock_post_meta, $mock_posts, $mock_options;
		$reader                = $this->reader( array( 'WPSEO_VERSION' ) );
		$mock_post_meta[1]     = array( '_yoast_wpseo_meta-robots-noindex' => '1' );
		$mock_post_meta[2]     = array( '_yoast_wpseo_meta-robots-noindex' => '2' );
		$mock_post_meta[3]     = array( '_yoast_wpseo_meta-robots-noindex' => '0' );
		$mock_posts[3]['type'] = 'page';

		$this->assertTrue( $reader->read( 1 )['noindex'] );
		$this->assertFalse( $reader->read( 2 )['noindex'] );
		$this->assertNull( $reader->read( 3 )['noindex'] );

		$mock_options['wpseo_titles'] = array( 'noindex-page' => true );
		$this->assertTrue( $reader->read( 3 )['noindex'] );
	}

	/**
	 * Rank Math fields, comma-separated keywords and serialized robots.
	 */
	public function test_reads_rank_math_fields(): void {
		global $mock_post_meta;
		$mock_post_meta[9] = array(
			'rank_math_title'         => '%title% | %sitename%',
			'rank_math_description'   => '',
			'rank_math_focus_keyword' => 'seo audit, site audit , ',
			'rank_math_seo_score'     => '81',
			'rank_math_robots'        => array( 'index', 'noindex' ),
			'rank_math_canonical_url' => 'https://example.com/c/',
		);
		$mock_post_meta[10] = array( 'rank_math_robots' => array( 'index' ) );

		$reader = $this->reader( array( 'RANK_MATH_VERSION' ) );
		$fields = $reader->read( 9 );

		$this->assertSame( 'rankmath', $fields['plugin'] );
		$this->assertTrue( $fields['uses_template'] );
		$this->assertSame( array( 'seo audit', 'site audit' ), $fields['focus_keywords'] );
		$this->assertSame( 'seo audit', $fields['focus_keyword'] );
		$this->assertSame( 81, $fields['seo_score'] );
		$this->assertTrue( $fields['noindex'] );
		$this->assertSame( 'default_template', $fields['description_source'] );
		$this->assertNotSame( '', $fields['empty_fallback_message'] );
		$this->assertFalse( $reader->read( 10 )['noindex'] );
		$this->assertNull( $reader->read( 11 )['noindex'] );
	}

	/**
	 * SEOPress fields; noindex is 'yes'; no stored score.
	 */
	public function test_reads_seopress_fields(): void {
		global $mock_post_meta;
		$mock_post_meta[4] = array(
			'_seopress_titles_title'        => 'Plain title',
			'_seopress_titles_desc'         => 'Plain description',
			'_seopress_analysis_target_kw'  => 'espresso,coffee beans',
			'_seopress_robots_index'        => 'yes',
			'_seopress_robots_canonical'    => 'https://example.com/x/',
		);
		$mock_post_meta[5] = array( '_seopress_robots_index' => '' );

		$reader = $this->reader( array( 'SEOPRESS_VERSION' ) );
		$fields = $reader->read( 4 );

		$this->assertSame( array( 'espresso', 'coffee beans' ), $fields['focus_keywords'] );
		$this->assertTrue( $fields['noindex'] );
		$this->assertNull( $fields['seo_score'] );
		$this->assertFalse( $fields['uses_template'] );
		$this->assertFalse( $reader->read( 5 )['noindex'] );
	}

	/**
	 * All in One SEO fields come from the aioseo_posts row.
	 */
	public function test_reads_aioseo_table_row(): void {
		$row = (object) array(
			'post_id'        => 12,
			'title'          => '#post_title #separator_sa #site_title',
			'description'    => 'Row description',
			'focus_keyword'  => 'garden tools',
			'keyphrases'     => '{"focus":{"keyphrase":"ignored"}}',
			'seo_score'      => '64',
			'robots_default' => '0',
			'robots_noindex' => '1',
			'canonical_url'  => 'https://example.com/g/',
		);
		$wpdb = $this->fake_wpdb( array( 'wp_aioseo_posts' ), array( $row ) );

		$fields = $this->reader( array( 'AIOSEO_VERSION' ) )->read( 12 );

		$this->assertSame( 'aioseo', $fields['plugin'] );
		$this->assertSame( '#post_title #separator_sa #site_title', $fields['title'] );
		$this->assertTrue( $fields['uses_template'] );
		$this->assertSame( 'Row description', $fields['description'] );
		$this->assertSame( 'garden tools', $fields['focus_keyword'] );
		$this->assertSame( 64, $fields['seo_score'] );
		$this->assertTrue( $fields['noindex'] );
		$this->assertSame( 'https://example.com/g/', $fields['canonical'] );
		$this->assertStringContainsString( 'post_id IN (12)', end( $wpdb->prepared ) );
	}

	/**
	 * AIOSEO falls back to keyphrases JSON, and robots_default defers to the global default.
	 */
	public function test_aioseo_keyphrases_fallback_and_default_robots(): void {
		$row = (object) array(
			'post_id'        => 13,
			'title'          => '',
			'description'    => '',
			'keyphrases'     => '{"focus":{"keyphrase":"legacy phrase","score":50},"additional":[]}',
			'robots_default' => '1',
			'robots_noindex' => '1',
		);
		$this->fake_wpdb( array( 'wp_aioseo_posts' ), array( $row ) );

		$fields = $this->reader( array( 'AIOSEO_VERSION' ) )->read( 13 );

		$this->assertSame( 'legacy phrase', $fields['focus_keyword'] );
		$this->assertNull( $fields['noindex'] );
		$this->assertSame( 'default_template', $fields['title_source'] );
	}

	/**
	 * AIOSEO without its table or row yields empty fields, not errors.
	 */
	public function test_aioseo_missing_table_or_row(): void {
		$this->fake_wpdb( array() );
		$fields = $this->reader( array( 'AIOSEO_VERSION' ) )->read( 14 );
		$this->assertSame( '', $fields['title'] );
		$this->assertNull( $fields['noindex'] );

		$this->fake_wpdb( array( 'wp_aioseo_posts' ), array() );
		$fields = $this->reader( array( 'AIOSEO_VERSION' ) )->read( 14 );
		$this->assertSame( '', $fields['description'] );
	}

	/**
	 * Template detection across plugin syntaxes, without flagging hashtags.
	 */
	public function test_template_detection(): void {
		$this->assertTrue( Seo_Plugin_Reader::has_template( '%%title%% - Site' ) );
		$this->assertTrue( Seo_Plugin_Reader::has_template( '%title% | %sitename%' ) );
		$this->assertTrue( Seo_Plugin_Reader::has_template( '#post_title - #site_title' ) );
		$this->assertTrue( Seo_Plugin_Reader::has_template( '#title | Site' ) );
		$this->assertFalse( Seo_Plugin_Reader::has_template( 'Top #1 tips: 50% off' ) );
		$this->assertFalse( Seo_Plugin_Reader::has_template( 'Plain title' ) );
	}

	/**
	 * Rank Math 404 monitor is read only when its table exists.
	 */
	public function test_rank_math_404_table(): void {
		$this->fake_wpdb( array() );
		$this->assertNull( $this->reader( array( 'RANK_MATH_VERSION' ) )->get_rank_math_404s() );

		$this->fake_wpdb(
			array( 'wp_rank_math_404_logs' ),
			array(
				(object) array(
					'uri'            => '/old/',
					'times_accessed' => '9',
					'accessed'       => '2026-10-01 10:00:00',
				),
			)
		);
		$rows = $this->reader( array( 'RANK_MATH_VERSION' ) )->get_rank_math_404s();
		$this->assertSame( '/old/', $rows[0]['uri'] );
		$this->assertSame( 9, $rows[0]['hits'] );
	}
}
