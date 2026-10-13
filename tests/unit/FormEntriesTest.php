<?php
/**
 * Tests for the form entries reader, platform and abilities.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Abilities\Abilities_Registrar;
use Specflux_Marketing_Analytics\Abilities\Form_Abilities;
use Specflux_Marketing_Analytics\Admin\Post_Stats;
use Specflux_Marketing_Analytics\Content\Form_Entries_Reader;
use Specflux_Marketing_Analytics\Content\Forms_Platform;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;

/**
 * Reader with scripted "active plugin" signals.
 */
class Form_Entries_Reader_Testable extends Form_Entries_Reader {

	/**
	 * Constants treated as defined.
	 *
	 * @var string[]
	 */
	public $constants = array();

	/**
	 * Classes treated as existing.
	 *
	 * @var string[]
	 */
	public $classes = array();

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
	 * Scripted class check.
	 *
	 * @param string $name Class.
	 * @return bool
	 */
	protected function has_class( $name ) {
		return in_array( $name, $this->classes, true );
	}
}

/**
 * Post_Stats with scripted connections and cache; never calls Google.
 */
class Form_Post_Stats_Double extends Post_Stats {

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
}

/**
 * Form entries tests.
 */
class FormEntriesTest extends TestCase {

	/**
	 * Original $wpdb.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Reset state.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $wpdb, $mock_post_meta, $mock_options;
		$this->original_wpdb = $wpdb;
		$mock_post_meta      = array();
		$mock_options        = array();
		Abilities_Registrar::reset();
		Platform_Registry::reset();
	}

	/**
	 * Restore state.
	 */
	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		Abilities_Registrar::reset();
		Platform_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Reader with the given constants and classes.
	 *
	 * @param string[] $constants Constants.
	 * @param string[] $classes   Classes.
	 * @return Form_Entries_Reader_Testable
	 */
	private function reader( array $constants = array(), array $classes = array() ) {
		$reader            = new Form_Entries_Reader_Testable();
		$reader->constants = $constants;
		$reader->classes   = $classes;
		return $reader;
	}

	/**
	 * Install a fake $wpdb. Results are matched by a substring of the SQL.
	 *
	 * @param string[] $tables  Existing tables.
	 * @param array    $results Map of SQL substring => rows.
	 * @return object The fake.
	 */
	private function fake_wpdb( array $tables, array $results ) {
		global $wpdb;
		$wpdb = new class( $tables, $results ) {
			public $prefix              = 'wp_';
			public $posts               = 'wp_posts';
			public $postmeta            = 'wp_postmeta';
			public $term_relationships  = 'wp_term_relationships';
			public $term_taxonomy       = 'wp_term_taxonomy';
			public $terms               = 'wp_terms';
			public $tables;
			public $results;
			public $prepared = array();

			public function __construct( $tables, $results ) {
				$this->tables  = $tables;
				$this->results = $results;
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

			public function get_results( $query, $output = null ) {
				foreach ( $this->results as $needle => $rows ) {
					if ( false !== strpos( $query, $needle ) ) {
						return $rows;
					}
				}
				return array();
			}
		};
		return $wpdb;
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
	 * Each supported plugin is detected from its constant or class.
	 */
	public function test_detects_each_plugin(): void {
		$this->assertSame( array( 'wpforms' ), $this->reader( array( 'WPFORMS_VERSION' ) )->detect() );
		$this->assertSame( array( 'fluentform' ), $this->reader( array( 'FLUENTFORM' ) )->detect() );
		$this->assertSame( array( 'gravityforms' ), $this->reader( array(), array( 'GFForms' ) )->detect() );
		$this->assertSame( array( 'formidable' ), $this->reader( array(), array( 'FrmAppHelper' ) )->detect() );
		$this->assertSame( array( 'ninjaforms' ), $this->reader( array(), array( 'Ninja_Forms' ) )->detect() );
		$this->assertSame( array( 'cf7' ), $this->reader( array( 'WPCF7_VERSION', 'FLAMINGO_VERSION' ) )->detect() );
		$this->assertSame( array(), $this->reader( array( 'WPCF7_VERSION' ) )->detect(), 'Contact Form 7 alone stores nothing.' );
		$this->assertSame( array(), $this->reader()->detect() );
	}

	/**
	 * Fluent Forms: grouped counts from fluentform_submissions, page and UTM from source_url.
	 */
	public function test_fluent_forms_counts_and_prepared_sql(): void {
		$wpdb = $this->fake_wpdb(
			array( 'wp_fluentform_submissions', 'wp_fluentform_forms' ),
			array(
				'FROM wp_fluentform_forms'       => array(
					array(
						'id'    => '3',
						'title' => 'Contact us',
					),
				),
				'FROM wp_fluentform_submissions' => array(
					array(
						'form_id' => '3',
						'day'     => '2026-10-01',
						'url'     => 'https://example.test/pricing/?utm_source=Google&utm_medium=cpc',
						'count'   => '4',
					),
					array(
						'form_id' => '3',
						'day'     => '2026-10-02',
						'url'     => 'https://example.test/pricing/',
						'count'   => '2',
					),
				),
			)
		);

		$reader = $this->reader( array( 'FLUENTFORM' ) );
		$rows   = $reader->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'Contact us', $rows[0]['title'] );
		$this->assertSame( 4, $rows[0]['count'] );

		$sql = $this->grouped_sql( $wpdb );
		$this->assertStringContainsString( "created_at >= '2026-10-01 00:00:00'", $sql );
		$this->assertStringContainsString( "created_at < '2026-10-08 00:00:00'", $sql );
		$this->assertStringContainsString( "status NOT IN ('trashed','spam')", $sql );

		$pages = Form_Entries_Reader::group_by_page( $rows );
		$this->assertSame( 6, $pages['pages']['/pricing']['entries'] );

		$sources = Form_Entries_Reader::group_by_source( $rows );
		$this->assertSame( 4, $sources['sources']['google|cpc|']['entries'] );
		$this->assertSame( 2, $sources['without_utm'] );
	}

	/**
	 * A missing plugin table means no rows, not an error.
	 */
	public function test_missing_table_returns_nothing(): void {
		$this->fake_wpdb( array(), array() );

		$this->assertSame( array(), $this->reader( array( 'FLUENTFORM' ) )->get_rows( '2026-10-01', '2026-10-07' ) );
		$this->assertSame( array(), $this->reader( array(), array( 'GFForms' ) )->get_rows( '2026-10-01', '2026-10-07' ) );
	}

	/**
	 * Gravity Forms reads gf_entry (active entries only).
	 */
	public function test_gravity_forms_counts(): void {
		$wpdb = $this->fake_wpdb(
			array( 'wp_gf_entry', 'wp_gf_form' ),
			array(
				'FROM wp_gf_form'  => array(
					array(
						'id'    => '1',
						'title' => 'Quote',
					),
				),
				'FROM wp_gf_entry' => array(
					array(
						'form_id' => '1',
						'day'     => '2026-10-03',
						'url'     => 'https://example.test/services/',
						'count'   => '5',
					),
				),
			)
		);

		$rows = $this->reader( array(), array( 'GFForms' ) )->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertSame( 'Quote', $rows[0]['title'] );
		$this->assertSame( 5, $rows[0]['count'] );
		$this->assertStringContainsString( "status = 'active'", $this->grouped_sql( $wpdb ) );
	}

	/**
	 * Formidable: page comes from the referrer in the description JSON; browser is dropped.
	 */
	public function test_formidable_counts_use_referrer_only(): void {
		$this->fake_wpdb(
			array( 'wp_frm_items', 'wp_frm_forms' ),
			array(
				'FROM wp_frm_forms' => array(
					array(
						'id'    => '2',
						'title' => 'Newsletter',
					),
				),
				'FROM wp_frm_items' => array(
					array(
						'form_id'     => '2',
						'day'         => '2026-10-04',
						'description' => '{"browser":"Mozilla SECRET-UA","referrer":"https://example.test/blog/post-a/"}',
					),
					array(
						'form_id'     => '2',
						'day'         => '2026-10-04',
						'description' => '{"browser":"Mozilla SECRET-UA","referrer":"https://example.test/blog/post-a/"}',
					),
				),
			)
		);

		$rows = $this->reader( array(), array( 'FrmAppHelper' ) )->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 2, $rows[0]['count'] );
		$this->assertSame( 'https://example.test/blog/post-a/', $rows[0]['url'] );
		$this->assertStringNotContainsString( 'SECRET-UA', wp_json_encode( $rows ) );
	}

	/**
	 * Ninja Forms: counted per form and day; attribution meta gives page, source and channel.
	 */
	public function test_ninja_forms_counts_by_form_with_attribution(): void {
		$attr = serialize(
			array(
				'source_type'  => 'utm',
				'source'       => 'Google',
				'medium'       => 'cpc',
				'campaign'     => 'Spring',
				'landing_page' => 'https://example.test/landing/',
				'email'        => 'secret@example.test',
			)
		);
		$this->fake_wpdb(
			array( 'wp_nf3_forms' ),
			array(
				'FROM wp_nf3_forms' => array(
					array(
						'id'    => '3',
						'title' => 'Contact me',
					),
				),
				'nf_sub'            => array(
					array(
						'day'         => '2026-10-04',
						'form_id'     => '3',
						'attribution' => $attr,
					),
					array(
						'day'         => '2026-10-04',
						'form_id'     => '3',
						'attribution' => $attr,
					),
					array(
						'day'         => '2026-10-05',
						'form_id'     => '3',
						'attribution' => null,
					),
				),
			)
		);

		$rows = $this->reader( array(), array( 'Ninja_Forms' ) )->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'Contact me', $rows[0]['title'] );
		$this->assertSame( 2, $rows[0]['count'] );
		$this->assertSame( 'https://example.test/landing/', $rows[0]['url'] );
		$this->assertStringNotContainsString( 'secret@example.test', wp_json_encode( $rows ) );

		$grouped = Form_Entries_Reader::group_by_source( $rows );
		$this->assertSame( 2, $grouped['with_utm'] );
		$this->assertSame( 1, $grouped['without_utm'] );
		$this->assertSame( 'Paid Search', $grouped['sources']['google|cpc|spring']['channel'] );
		$this->assertSame( 1, $grouped['channels']['Not tracked'] );
	}

	/**
	 * Ninja Forms: the SQL never selects field values.
	 */
	public function test_ninja_forms_never_reads_field_values(): void {
		$db = $this->fake_wpdb( array(), array() );
		$this->reader( array(), array( 'Ninja_Forms' ) )->get_rows( '2026-10-01', '2026-10-07' );

		$sql = implode( ' ', $db->prepared );
		$this->assertStringContainsString( "'_form_id'", $sql );
		$this->assertStringNotContainsString( '_field_', $sql );
		$this->assertStringNotContainsString( 'post_content', $sql );
	}

	/**
	 * Ninja Forms: no submissions gives no rows, and the plugin missing gives no rows.
	 */
	public function test_ninja_forms_empty_and_missing(): void {
		$this->fake_wpdb( array(), array() );
		$this->assertSame( array(), $this->reader( array(), array( 'Ninja_Forms' ) )->get_rows( '2026-10-01', '2026-10-07' ) );
		$this->assertSame( array(), $this->reader()->get_rows( '2026-10-01', '2026-10-07' ) );
	}

	/**
	 * Attribution meta parsing ignores malformed values and unknown keys.
	 */
	public function test_parse_attribution_is_defensive(): void {
		$this->assertNull( Form_Entries_Reader::parse_attribution( '' ) );
		$this->assertNull( Form_Entries_Reader::parse_attribution( 'not serialized' ) );
		$this->assertNull( Form_Entries_Reader::parse_attribution( serialize( array( 'x' => 'y' ) ) ) );
		$parsed = Form_Entries_Reader::parse_attribution( serialize( array( 'source' => 'Bing', 'ip' => '1.2.3.4' ) ) );
		$this->assertSame( 'bing', $parsed['source'] );
		$this->assertArrayNotHasKey( 'ip', $parsed );
	}

	/**
	 * Formidable: malformed or missing description JSON counts the entry with no page.
	 */
	public function test_formidable_malformed_description_has_no_page(): void {
		$this->fake_wpdb(
			array( 'wp_frm_items' ),
			array(
				'FROM wp_frm_items' => array(
					array(
						'form_id'     => '2',
						'day'         => '2026-10-04',
						'description' => '{not json',
					),
					array(
						'form_id'     => '2',
						'day'         => '2026-10-04',
						'description' => '',
					),
					array(
						'form_id'     => '2',
						'day'         => '2026-10-04',
						'description' => '{"referrer":["array"]}',
					),
				),
			)
		);

		$rows = $this->reader( array(), array( 'FrmAppHelper' ) )->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 3, $rows[0]['count'] );
		$this->assertSame( '', $rows[0]['url'] );
		$this->assertSame( 3, Form_Entries_Reader::group_by_source( $rows )['channels']['Not tracked'] );
	}

	/**
	 * Contact Form 7 + Flamingo: counts per channel form; only url is read from _meta.
	 */
	public function test_flamingo_counts_read_only_the_url(): void {
		$meta = serialize(
			array(
				'url'        => 'https://example.test/contact/?utm_source=newsletter',
				'remote_ip'  => '203.0.113.9',
				'user_email' => 'secret@example.test',
			)
		);
		$this->fake_wpdb(
			array(),
			array(
				'flamingo_inbound' => array(
					array(
						'day'          => '2026-10-05',
						'meta'         => $meta,
						'channel_slug' => 'contact-form-1',
						'channel_name' => 'Contact form 1',
					),
					array(
						'day'          => '2026-10-05',
						'meta'         => $meta,
						'channel_slug' => 'contact-form-1',
						'channel_name' => 'Contact form 1',
					),
				),
				'wpcf7_contact_form' => array(),
			)
		);

		$rows = $this->reader( array( 'WPCF7_VERSION', 'FLAMINGO_VERSION' ) )->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 2, $rows[0]['count'] );
		$this->assertSame( 'Contact form 1', $rows[0]['title'] );
		$encoded = wp_json_encode( $rows );
		$this->assertStringNotContainsString( 'secret@example.test', $encoded );
		$this->assertStringNotContainsString( '203.0.113.9', $encoded );
		$this->assertSame( 'newsletter', Form_Entries_Reader::group_by_source( $rows )['sources']['newsletter||']['source'] );
	}

	/**
	 * WPForms Lite has no entries table: only the site-wide rolling window is available.
	 */
	public function test_wpforms_lite_uses_rolling_window_and_says_so(): void {
		global $mock_options;
		$mock_options['wpforms_entries_count_daily'] = array(
			'since' => 1,
			'days'  => array(
				'2026-10-02' => 3,
				'2026-09-01' => 9,
			),
		);
		$this->fake_wpdb( array(), array() );

		$reader = $this->reader( array( 'WPFORMS_VERSION' ) );
		$rows   = $reader->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 3, $rows[0]['count'] );
		$this->assertSame( '', $rows[0]['url'] );
		$this->assertStringContainsString( 'WPForms Lite does not store entries', implode( ' ', $reader->get_notes() ) );
		$this->assertFalse( $reader->stores_page_urls() );
	}

	/**
	 * Forms list carries WPForms Lite's lifetime count.
	 */
	public function test_list_forms_includes_lifetime_count_for_wpforms(): void {
		global $mock_post_meta;
		$mock_post_meta[7]['wpforms_entries_count'] = '12';
		$this->fake_wpdb(
			array(),
			array(
				"post_type = 'wpforms'" => array(
					array(
						'id'    => '7',
						'title' => 'Lite form',
					),
				),
			)
		);

		$forms = $this->reader( array( 'WPFORMS_VERSION' ) )->list_forms();

		$this->assertSame(
			array(
				array(
					'plugin'           => 'wpforms',
					'form_id'          => '7',
					'title'            => 'Lite form',
					'lifetime_entries' => 12,
				),
			),
			$forms
		);
	}

	/**
	 * Fluent-backed reader with scripted rows for the ability tests.
	 *
	 * @return Form_Entries_Reader_Testable
	 */
	private function fluent_reader_with_rows() {
		$today = gmdate( 'Y-m-d' );
		$this->fake_wpdb(
			array( 'wp_fluentform_submissions', 'wp_fluentform_forms' ),
			array(
				'FROM wp_fluentform_forms'       => array(
					array(
						'id'    => '3',
						'title' => 'Contact us',
					),
				),
				'FROM wp_fluentform_submissions' => array(
					array(
						'form_id' => '3',
						'day'     => $today,
						'url'     => 'https://example.test/pricing/',
						'count'   => '10',
					),
					array(
						'form_id' => '3',
						'day'     => $today,
						'url'     => 'https://example.test/about/',
						'count'   => '1',
					),
					array(
						'form_id' => '3',
						'day'     => $today,
						'url'     => '',
						'count'   => '2',
					),
				),
			)
		);

		return $this->reader( array( 'FLUENTFORM' ) );
	}

	/**
	 * The page join yields views and a conversion rate, sorted by entries.
	 */
	public function test_leads_by_page_joins_ga4_and_computes_conversion_rate(): void {
		$stats        = new Form_Post_Stats_Double();
		$stats->cache = array(
			'platforms' => array( 'ga4', 'gsc' ),
			'ga4'       => array(
				'/pricing' => 400,
				'/about'   => 0,
			),
			'gsc'       => array( '/pricing' => array( 30, 900, 5.0 ) ),
			'errors'    => array(),
			'capped'    => array(),
		);

		$abilities = new Form_Abilities( $this->fluent_reader_with_rows(), $stats );
		$data      = $this->decode( $abilities->execute_get_leads_by_page() );

		$this->assertSame( '/pricing', $data['pages'][0]['path'] );
		$this->assertSame( 10, $data['pages'][0]['entries'] );
		$this->assertSame( 400, $data['pages'][0]['ga4_views'] );
		$this->assertSame( 2.5, $data['pages'][0]['conversion_rate_pct'] );
		$this->assertSame( 30, $data['pages'][0]['search_clicks'] );
		$this->assertNull( $data['pages'][1]['conversion_rate_pct'], 'Zero views gives no rate.' );
		$this->assertSame( 2, $data['entries_without_a_page'] );
		$this->assertSame( 'ok', $data['data_status']['ga4'] );
	}

	/**
	 * Without GA4 the rate and views are null, not zero.
	 */
	public function test_leads_by_page_without_ga4(): void {
		$stats            = new Form_Post_Stats_Double();
		$stats->platforms = array();

		$abilities = new Form_Abilities( $this->fluent_reader_with_rows(), $stats );
		$data      = $this->decode( $abilities->execute_get_leads_by_page() );

		$this->assertNull( $data['pages'][0]['ga4_views'] );
		$this->assertNull( $data['pages'][0]['conversion_rate_pct'] );
		$this->assertSame( 'not_connected', $data['data_status']['ga4'] );
	}

	/**
	 * Totals, series and change versus the previous period.
	 */
	public function test_get_form_entries_totals_and_change(): void {
		$day      = '2026-10-07';
		$prev_day = '2026-09-30';
		$this->fake_wpdb(
			array( 'wp_fluentform_submissions', 'wp_fluentform_forms' ),
			array(
				'FROM wp_fluentform_forms'       => array(
					array(
						'id'    => '3',
						'title' => 'Contact us',
					),
				),
				"created_at >= '2026-10-01" => array(
					array(
						'form_id' => '3',
						'day'     => $day,
						'url'     => '',
						'count'   => '6',
					),
				),
				"created_at >= '2026-09-24" => array(
					array(
						'form_id' => '3',
						'day'     => $prev_day,
						'url'     => '',
						'count'   => '3',
					),
				),
			)
		);

		$abilities = new Form_Abilities( $this->reader( array( 'FLUENTFORM' ) ), new Form_Post_Stats_Double() );
		$data      = $this->decode(
			$abilities->execute_get_form_entries(
				array(
					'start_date' => '2026-10-01',
					'end_date'   => '2026-10-07',
					'group_by'   => 'week',
				)
			)
		);

		$this->assertSame( '2026-09-24 to 2026-09-30', $data['previous_period'] );
		$this->assertSame( 6, $data['total_entries'] );
		$this->assertSame( 3, $data['previous_entries'] );
		$this->assertEquals( 100, $data['change_pct'] );
		$this->assertSame( '2026-10-05', $data['forms'][0]['series'][0]['week_starting'] );
	}

	/**
	 * Malformed dates are rejected as a tool error.
	 */
	public function test_bad_date_is_an_error(): void {
		$abilities = new Form_Abilities( $this->reader( array( 'FLUENTFORM' ) ), new Form_Post_Stats_Double() );
		$response  = $abilities->execute_get_form_entries( array( 'start_date' => 'soon' ) );

		$this->assertTrue( $response['isError'] );
	}

	/**
	 * Lead sources explains when nothing carries UTM data.
	 */
	public function test_lead_sources_states_when_not_stored(): void {
		$abilities = new Form_Abilities( $this->fluent_reader_with_rows(), new Form_Post_Stats_Double() );
		$data      = $this->decode( $abilities->execute_get_lead_sources() );

		$this->assertSame( array(), $data['sources'] );
		$this->assertSame( 13, $data['entries_without_utm'] );
		$this->assertStringContainsString( 'No entries in this period carry UTM', $data['note'] );

		$wpforms   = new Form_Abilities( $this->reader( array( 'WPFORMS_VERSION' ) ), new Form_Post_Stats_Double() );
		$this->fake_wpdb( array(), array() );
		$data = $this->decode( $wpforms->execute_get_lead_sources() );
		$this->assertStringContainsString( 'do not store UTM', $data['note'] );
	}

	/**
	 * No output of any ability or reader method contains personal-data keys.
	 */
	public function test_no_pii_in_output(): void {
		$stats        = new Form_Post_Stats_Double();
		$stats->cache = array(
			'platforms' => array( 'ga4' ),
			'ga4'       => array( '/pricing' => 100 ),
			'gsc'       => array(),
			'errors'    => array(),
			'capped'    => array(),
		);
		$abilities    = new Form_Abilities( $this->fluent_reader_with_rows(), $stats );

		$outputs = array(
			$abilities->execute_get_form_entries(),
			$abilities->execute_get_leads_by_page(),
			$abilities->execute_get_lead_sources(),
		);

		foreach ( $outputs as $response ) {
			$json = strtolower( $response['content'][0]['text'] );
			foreach ( array( '"email', '"name"', '"phone', '"message', '"ip"', 'ip_address', 'user_agent', 'from_email', 'remote_ip', '"response"' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $json, "Output must not contain {$needle}" );
			}
		}

		$forms = $this->reader( array( 'FLUENTFORM' ) )->list_forms();
		$this->assertSame( array( 'plugin', 'form_id', 'title', 'lifetime_entries' ), array_keys( $forms[0] ) );
	}

	/**
	 * Every ability is read-only, non-destructive, idempotent and uses the shared permission check.
	 */
	public function test_abilities_are_annotated_and_permissioned(): void {
		( new Form_Abilities( $this->reader( array( 'FLUENTFORM' ) ) ) )->register();
		$tools = Abilities_Registrar::get_registered_tools();

		foreach ( array( 'get-form-entries', 'get-leads-by-page', 'get-lead-sources' ) as $name ) {
			$this->assertArrayHasKey( 'marketing-analytics/' . $name, $tools );
			$config = $tools[ 'marketing-analytics/' . $name ];
			$this->assertSame( array( Abilities_Registrar::class, 'can_access' ), $config['permission_callback'] );
			$this->assertSame(
				array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				$config['meta']['annotations']
			);
			$this->assertNotSame( '', $config['description'] );
		}
	}

	/**
	 * Without a form plugin no ability is registered.
	 */
	public function test_abilities_not_registered_without_form_plugin(): void {
		( new Form_Abilities( $this->reader() ) )->register();

		$this->assertSame( array(), Abilities_Registrar::get_registered_tools() );
	}

	/**
	 * The platform registers (keyless, naming the plugins) only when a form plugin is active.
	 */
	public function test_platform_registers_only_with_form_plugin(): void {
		$active = new Forms_Platform( $this->reader( array( 'FLUENTFORM' ) ) );
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = array( $active, 'register_platform' );
		Platform_Registry::reset();

		$definition = Platform_Registry::get( 'forms' );
		$this->assertSame( 'none', $definition['auth'] );
		$this->assertSame( 'Form entries', $definition['label'] );
		$this->assertStringContainsString( 'Fluent Forms', $definition['description'] );
		$this->assertNotSame( '', $definition['prompt_hint'] );
		$this->assertTrue( $active->test_connection()['success'] );
		$this->assertTrue( Platform_Registry::is_registered( 'gsc' ), 'Core platforms are preserved.' );

		$inactive = new Forms_Platform( $this->reader() );
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = array( $inactive, 'register_platform' );
		Platform_Registry::reset();

		$this->assertFalse( Platform_Registry::is_registered( 'forms' ) );
		$this->assertTrue( Platform_Registry::is_registered( 'gsc' ) );
		$this->assertFalse( $inactive->test_connection()['success'] );
	}
	/**
	 * The grouped-count query (the first non-SHOW TABLES statement).
	 *
	 * @param object $wpdb Fake.
	 * @return string
	 */
	private function grouped_sql( $wpdb ) {
		foreach ( $wpdb->prepared as $q ) {
			if ( 0 !== strpos( $q, 'SHOW TABLES' ) && false !== strpos( $q, 'COUNT(*)' ) ) {
				return $q;
			}
		}
		return '';
	}

	/**
	 * A0 attribution array used by the per-plugin tests.
	 *
	 * @return array<string,string>
	 */
	private function a0_attribution() {
		return array(
			'source_type'  => 'utm',
			'source'       => 'Google',
			'medium'       => 'cpc',
			'campaign'     => 'Spring',
			'landing_page' => 'https://example.test/landing/',
			'email'        => 'secret@example.test',
		);
	}

	/**
	 * Assert A0 attribution reached the rows and the grouped source/channel output.
	 *
	 * @param array[] $rows        Reader rows.
	 * @param int     $attributed  Expected attributed entries.
	 * @param int     $untracked   Expected entries without any source.
	 */
	private function assert_a0_rows( array $rows, $attributed, $untracked ): void {
		$this->assertStringNotContainsString( 'secret@example.test', wp_json_encode( $rows ) );
		$grouped = Form_Entries_Reader::group_by_source( $rows );
		$this->assertSame( $attributed, $grouped['with_utm'] );
		$this->assertSame( $untracked, $grouped['without_utm'] );
		$this->assertSame( 'Paid Search', $grouped['sources']['google|cpc|spring']['channel'] );
		$pages = Form_Entries_Reader::group_by_page( $rows );
		$this->assertSame( $attributed, $pages['pages']['/landing']['entries'] );
	}

	/**
	 * Fluent Forms: serialized submission meta wins; malformed meta keeps the source_url fallback.
	 */
	public function test_fluent_forms_reads_a0_attribution(): void {
		$db = $this->fake_wpdb(
			array( 'wp_fluentform_submissions', 'wp_fluentform_submission_meta' ),
			array(
				'wp_fluentform_submission_meta' => array(
					array(
						'entry_id'    => '1',
						'form_id'     => '3',
						'day'         => '2026-10-01',
						'url'         => 'https://example.test/pricing/',
						'attribution' => serialize( $this->a0_attribution() ),
					),
					array(
						'entry_id'    => '2',
						'form_id'     => '3',
						'day'         => '2026-10-01',
						'url'         => 'https://example.test/pricing/',
						'attribution' => 'garbage',
					),
				),
				'FROM wp_fluentform_submissions' => array(
					array(
						'form_id' => '3',
						'day'     => '2026-10-01',
						'url'     => 'https://example.test/pricing/',
						'count'   => '2',
					),
				),
			)
		);

		$rows = $this->reader( array( 'FLUENTFORM' ) )->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertCount( 2, $rows );
		$this->assertSame( 1, $rows[0]['count'] );
		$this->assertSame( 'https://example.test/pricing/', $rows[0]['url'] );
		$this->assertArrayNotHasKey( 'attr', $rows[0] );
		$this->assertSame( 'https://example.test/landing/', $rows[1]['url'] );
		$this->assert_a0_rows( $rows, 1, 1 );

		$meta_sql = implode( ' ', $db->prepared );
		$this->assertStringContainsString( "m.meta_key = '_specflux_mac_attribution'", $meta_sql );
		$this->assertStringContainsString( 'm.value AS attribution', $meta_sql );
		$this->assertStringNotContainsString( 'SELECT *', $meta_sql );
		$this->assertStringNotContainsString( 'e.fields', $meta_sql );
	}

	/**
	 * Gravity Forms: gf_entry_meta value (serialized or JSON) is read; field values never are.
	 */
	public function test_gravity_forms_reads_a0_attribution(): void {
		$db = $this->fake_wpdb(
			array( 'wp_gf_entry', 'wp_gf_entry_meta' ),
			array(
				'wp_gf_entry_meta' => array(
					array(
						'entry_id'    => '9',
						'form_id'     => '1',
						'day'         => '2026-10-02',
						'url'         => 'https://example.test/x/',
						'attribution' => wp_json_encode( $this->a0_attribution() ),
					),
				),
				'FROM wp_gf_entry' => array(
					array(
						'form_id' => '1',
						'day'     => '2026-10-02',
						'url'     => 'https://example.test/x/',
						'count'   => '1',
					),
				),
			)
		);

		$rows = $this->reader( array(), array( 'GFForms' ) )->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertCount( 1, $rows );
		$this->assert_a0_rows( $rows, 1, 0 );
		$sql = implode( ' ', $db->prepared );
		$this->assertStringContainsString( "m.meta_key = '_specflux_mac_attribution'", $sql );
		$this->assertStringNotContainsString( 'wp_gf_entry_notes', $sql );
	}

	/**
	 * WPForms Pro: JSON entry meta of type _specflux_mac_attribution; page comes from it.
	 */
	public function test_wpforms_pro_reads_a0_attribution(): void {
		$db = $this->fake_wpdb(
			array( 'wp_wpforms_entries', 'wp_wpforms_entry_meta' ),
			array(
				'wp_wpforms_entry_meta' => array(
					array(
						'entry_id'    => '5',
						'form_id'     => '7',
						'day'         => '2026-10-03',
						'url'         => '',
						'attribution' => wp_json_encode( $this->a0_attribution() ),
					),
					array(
						'entry_id'    => '6',
						'form_id'     => '7',
						'day'         => '2026-10-03',
						'url'         => '',
						'attribution' => '{broken',
					),
				),
				'FROM wp_wpforms_entries' => array(
					array(
						'form_id' => '7',
						'day'     => '2026-10-03',
						'count'   => '2',
					),
				),
			)
		);

		$rows = $this->reader( array( 'WPFORMS_VERSION' ) )->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertCount( 2, $rows );
		$this->assertSame( 1, $rows[0]['count'] );
		$this->assertSame( '', $rows[0]['url'] );
		$this->assert_a0_rows( $rows, 1, 1 );
		$sql = implode( ' ', $db->prepared );
		$this->assertStringContainsString( "m.type = '_specflux_mac_attribution'", $sql );
		$this->assertStringNotContainsString( 'e.fields', $sql );
		$this->assertStringNotContainsString( 'ip_address', $sql );
	}

	/**
	 * Formidable: the field_id 0 value is unwrapped; without it the referrer fallback applies.
	 */
	public function test_formidable_unwraps_a0_attribution(): void {
		$wrapped = serialize( array( '_specflux_mac_attribution' => $this->a0_attribution() ) );
		$desc    = '{"browser":"Mozilla SECRET-UA","referrer":"https://example.test/blog/post-a/"}';
		$db      = $this->fake_wpdb(
			array( 'wp_frm_items', 'wp_frm_item_metas' ),
			array(
				'FROM wp_frm_items' => array(
					array(
						'entry_id'    => '1',
						'form_id'     => '2',
						'day'         => '2026-10-04',
						'description' => $desc,
						'attribution' => $wrapped,
					),
					array(
						'entry_id'    => '1',
						'form_id'     => '2',
						'day'         => '2026-10-04',
						'description' => $desc,
						'attribution' => $wrapped,
					),
					array(
						'entry_id'    => '2',
						'form_id'     => '2',
						'day'         => '2026-10-04',
						'description' => $desc,
						'attribution' => serialize( $this->a0_attribution() ),
					),
					array(
						'entry_id'    => '3',
						'form_id'     => '2',
						'day'         => '2026-10-04',
						'description' => $desc,
						'attribution' => null,
					),
				),
			)
		);

		$rows = $this->reader( array(), array( 'FrmAppHelper' ) )->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertCount( 2, $rows );
		$by_url = array_column( $rows, 'count', 'url' );
		$this->assertSame( 1, $by_url['https://example.test/landing/'] );
		$this->assertSame( 2, $by_url['https://example.test/blog/post-a/'] );
		$this->assertStringNotContainsString( 'SECRET-UA', wp_json_encode( $rows ) );
		$this->assert_a0_rows( $rows, 1, 2 );

		$sql = implode( ' ', $db->prepared );
		$this->assertStringContainsString( 'm.field_id = 0', $sql );
		$this->assertStringContainsString( 'm.meta_value AS attribution', $sql );
		$this->assertStringNotContainsString( 'SELECT m.', $sql );
	}

	/**
	 * Flamingo: attribution post meta is joined into the one query; garbage falls back to _meta url.
	 */
	public function test_flamingo_reads_a0_attribution(): void {
		$meta = serialize(
			array(
				'url'        => 'https://example.test/contact/',
				'user_email' => 'secret@example.test',
			)
		);
		$db   = $this->fake_wpdb(
			array(),
			array(
				'flamingo_inbound'   => array(
					array(
						'day'          => '2026-10-05',
						'meta'         => $meta,
						'attribution'  => serialize( $this->a0_attribution() ),
						'channel_slug' => 'cf',
						'channel_name' => 'CF',
					),
					array(
						'day'          => '2026-10-05',
						'meta'         => $meta,
						'attribution'  => 'junk',
						'channel_slug' => 'cf',
						'channel_name' => 'CF',
					),
				),
				'wpcf7_contact_form' => array(),
			)
		);

		$rows = $this->reader( array( 'WPCF7_VERSION', 'FLAMINGO_VERSION' ) )->get_rows( '2026-10-01', '2026-10-07' );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'https://example.test/landing/', $rows[0]['url'] );
		$this->assertSame( 'https://example.test/contact/', $rows[1]['url'] );
		$this->assertArrayNotHasKey( 'attr', $rows[1] );
		$this->assert_a0_rows( $rows, 1, 1 );

		$flamingo_sql = '';
		foreach ( $db->prepared as $q ) {
			if ( false !== strpos( $q, 'flamingo_inbound' ) ) {
				$flamingo_sql = $q;
			}
		}
		$this->assertStringContainsString( "am.meta_key = '_specflux_mac_attribution'", $flamingo_sql );
		$this->assertStringNotContainsString( 'post_content', $flamingo_sql );
	}

	/**
	 * Malformed attribution values of every shape are ignored; wrapped and JSON forms parse.
	 */
	public function test_parse_attribution_formats(): void {
		$a = array(
			'source'       => 'Bing',
			'landing_page' => 'https://example.test/p/',
		);
		$this->assertNull( Form_Entries_Reader::parse_attribution( '{broken' ) );
		$this->assertNull( Form_Entries_Reader::parse_attribution( '[1,2]' ) );
		$this->assertNull( Form_Entries_Reader::parse_attribution( serialize( $a ), true ) );
		$this->assertNull( Form_Entries_Reader::parse_attribution( serialize( array( '_specflux_mac_attribution' => 'x' ) ), true ) );
		$this->assertSame( 'bing', Form_Entries_Reader::parse_attribution( wp_json_encode( $a ) )['source'] );
		$this->assertSame( 'bing', Form_Entries_Reader::parse_attribution( serialize( array( '_specflux_mac_attribution' => $a ) ), true )['source'] );
	}
}
