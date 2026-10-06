<?php
/**
 * Tests for the per-post analytics column.
 *
 * @package Specflux_Marketing_Analytics
 */

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Admin\Post_Stats;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

/**
 * Test double: scripted credentials and API clients that count calls.
 */
class Post_Stats_Testable extends Post_Stats {

	/**
	 * Calls made, by platform.
	 *
	 * @var array
	 */
	public $calls = array(
		'ga4' => 0,
		'gsc' => 0,
	);

	/**
	 * GA4 response, or a Throwable to throw.
	 *
	 * @var mixed
	 */
	public $ga4_response = array( 'rows' => array() );

	/**
	 * GSC response, or a Throwable to throw.
	 *
	 * @var mixed
	 */
	public $gsc_response = array( 'rows' => array() );

	/**
	 * Last arguments passed to the clients.
	 *
	 * @var array
	 */
	public $args = array();

	/**
	 * Build a fake client from a closure.
	 *
	 * @param string   $platform Platform key.
	 * @param string   $method   Method name to expose.
	 * @param callable $handler  Handler.
	 * @return object
	 */
	private function fake( $platform, $method, $handler ) {
		return new class( $method, $handler ) {
			/**
			 * Method name.
			 *
			 * @var string
			 */
			private $method;

			/**
			 * Handler.
			 *
			 * @var callable
			 */
			private $handler;

			/**
			 * Constructor.
			 *
			 * @param string   $method  Method.
			 * @param callable $handler Handler.
			 */
			public function __construct( $method, $handler ) {
				$this->method  = $method;
				$this->handler = $handler;
			}

			/**
			 * Forward any call to the handler.
			 *
			 * @param string $name Method.
			 * @param array  $args Arguments.
			 * @return mixed
			 */
			public function __call( $name, $args ) {
				return ( $this->handler )( $args );
			}
		};
	}

	/**
	 * Fake GA4 client.
	 *
	 * @return object
	 */
	protected function make_ga4_client() {
		return $this->fake(
			'ga4',
			'run_report',
			function ( $args ) {
				++$this->calls['ga4'];
				$this->args['ga4'] = $args;
				if ( $this->ga4_response instanceof \Throwable ) {
					throw $this->ga4_response;
				}
				return $this->ga4_response;
			}
		);
	}

	/**
	 * Fake GSC client.
	 *
	 * @return object
	 */
	protected function make_gsc_client() {
		return $this->fake(
			'gsc',
			'query_search_analytics',
			function ( $args ) {
				++$this->calls['gsc'];
				$this->args['gsc'] = $args;
				if ( $this->gsc_response instanceof \Throwable ) {
					throw $this->gsc_response;
				}
				return $this->gsc_response;
			}
		);
	}
}

/**
 * Post stats tests.
 */
class PostStatsTest extends TestCase {

	/**
	 * Build a subject with the given connected platforms.
	 *
	 * @param string[] $connected Connected platforms.
	 * @return Post_Stats_Testable
	 */
	private function subject( $connected = array( 'ga4', 'gsc' ) ) {
		$credentials = $this->createMock( Credential_Manager::class );
		$credentials->method( 'has_credentials' )->willReturnCallback(
			function ( $platform ) use ( $connected ) {
				return in_array( $platform, $connected, true );
			}
		);
		return new Post_Stats_Testable( $credentials );
	}

	/**
	 * Reset globals.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_transients, $mock_user_can, $mock_posts, $mock_json_responses, $mock_nonce_valid;
		$mock_transients     = array();
		$mock_user_can       = true;
		$mock_json_responses = array();
		$mock_nonce_valid    = true;
		$mock_posts          = array(
			1 => array(
				'status' => 'publish',
				'url'    => 'https://example.com/blog/hello/',
				'title'  => 'Hello',
			),
			2 => array(
				'status' => 'publish',
				'url'    => 'https://example.com/',
				'title'  => 'Home',
			),
			3 => array(
				'status' => 'draft',
				'url'    => 'https://example.com/?p=3',
				'title'  => 'Draft',
			),
			4 => array(
				'status' => 'publish',
				'url'    => 'https://example.com/xss/',
				'title'  => '<script>alert(1)</script>"Quote"',
			),
		);
	}

	/**
	 * Restore globals.
	 */
	protected function tearDown(): void {
		global $mock_transients, $mock_user_can, $mock_posts, $mock_json_responses, $mock_nonce_valid;
		$mock_transients     = array();
		$mock_user_can       = true;
		$mock_posts          = array();
		$mock_json_responses = array();
		$mock_nonce_valid    = true;
		unset( $_POST['nonce'], $_POST['post_ids'] );
		parent::tearDown();
	}

	/**
	 * Path normalisation cases.
	 *
	 * @return array
	 */
	public static function path_provider() {
		return array(
			'full url'          => array( 'https://Example.com/Blog/Hello', '/blog/hello' ),
			'trailing slash'    => array( 'https://example.com/blog/hello/', '/blog/hello' ),
			'query string'      => array( 'https://example.com/blog/hello/?utm=1&x=2', '/blog/hello' ),
			'fragment'          => array( 'https://example.com/blog/hello#top', '/blog/hello' ),
			'home with slash'   => array( 'https://example.com/', '/' ),
			'home without path' => array( 'https://example.com', '/' ),
			'bare slash'        => array( '/', '/' ),
			'relative path'     => array( '/About-Us/', '/about-us' ),
			'encoded'           => array( '/caf%C3%A9/', '/caf%C3%A9' ),
			'raw utf-8'         => array( '/café/', '/caf%C3%A9' ),
			'truncated utf-8'   => array( "/xhs-\xE7\xB3", null ),
			'not set'           => array( '(not set)', null ),
			'empty'             => array( '', null ),
		);
	}

	/**
	 * Normalisation.
	 *
	 * @dataProvider path_provider
	 *
	 * @param string      $input    Input.
	 * @param string|null $expected Expected path.
	 */
	public function test_normalize_path( $input, $expected ) {
		$this->assertSame( $expected, Post_Stats::normalize_path( $input ) );
	}

	/**
	 * GA4 map building, including merging of variants.
	 */
	public function test_build_ga4_map() {
		$map = Post_Stats::build_ga4_map(
			array(
				'rows' => array(
					array(
						'pagePath'        => '/blog/hello/',
						'screenPageViews' => '1000',
					),
					array(
						'pagePath'        => '/Blog/Hello',
						'screenPageViews' => '204',
					),
					array(
						'pagePath'        => '/',
						'screenPageViews' => '50',
					),
					array(
						'pagePath'        => '(not set)',
						'screenPageViews' => '9',
					),
				),
			)
		);

		$this->assertSame(
			array(
				'/blog/hello' => 1204,
				'/'           => 50,
			),
			$map
		);
		$this->assertSame( array(), Post_Stats::build_ga4_map( null ) );
	}

	/**
	 * GSC map building with weighted position.
	 */
	public function test_build_gsc_map() {
		$map = Post_Stats::build_gsc_map(
			array(
				'rows' => array(
					array(
						'key'         => 'https://example.com/blog/hello/',
						'keys'        => array( 'https://example.com/blog/hello/' ),
						'clicks'      => 60,
						'impressions' => 300,
						'position'    => 10.0,
					),
					array(
						'key'         => 'http://www.example.com/blog/hello',
						'clicks'      => 27,
						'impressions' => 100,
						'position'    => 18.0,
					),
					array(
						'key'         => 'https://example.com/',
						'clicks'      => 5,
						'impressions' => 50,
						'position'    => 3.0,
					),
				),
			)
		);

		$this->assertSame( array( 87, 400, 12.0 ), $map['/blog/hello'] );
		$this->assertSame( array( 5, 50, 3.0 ), $map['/'] );
	}

	/**
	 * Rendering formats numbers and escapes output.
	 */
	public function test_cell_rendering_and_escaping() {
		$subject = $this->subject();
		$cache   = array(
			'platforms' => array( 'ga4', 'gsc' ),
			'ga4'       => array(
				'/blog/hello' => 1204,
				'/xss'        => 1,
			),
			'gsc'       => array(
				'/blog/hello' => array( 87, 900, 12.34 ),
				'/xss'        => array( 1, 5, 0.0 ),
			),
			'errors'    => array(),
		);

		$html = $subject->get_cell_html( 1, $cache );
		$this->assertStringContainsString( '1,204 views · 87 clicks', $html );
		$this->assertStringContainsString( 'avg pos 12.3', $html );
		$this->assertStringContainsString( 'specflux-mac-ai-assistant', $html );
		$this->assertStringContainsString( 'prompt=', $html );

		$home = $subject->get_cell_html( 2, array_merge( $cache, array( 'ga4' => array( '/' => 7 ) ) ) );
		$this->assertStringContainsString( '7 views', $home );

		$xss = $subject->get_cell_html( 4, $cache );
		$this->assertStringNotContainsString( '<script', $xss );
		$this->assertStringContainsString( '1 view · 1 click', $xss );
		$this->assertStringNotContainsString( 'avg pos', $xss );
	}

	/**
	 * Unpublished posts and a cold cache render safe placeholders.
	 */
	public function test_placeholders() {
		$subject = $this->subject();
		$cache   = array(
			'platforms' => array( 'ga4' ),
			'ga4'       => array( '/' => 99 ),
			'gsc'       => array(),
			'errors'    => array(),
		);

		$draft = $subject->get_cell_html( 3, $cache );
		$this->assertStringContainsString( '&mdash;', $draft );
		$this->assertStringNotContainsString( '99', $draft );

		$cold = $subject->get_cell_html( 1, null );
		$this->assertStringContainsString( 'smac-post-stats--loading', $cold );
		$this->assertStringContainsString( 'Loading', $cold );
		$this->assertStringContainsString( 'data-post-id="1"', $cold );
	}

	/**
	 * Column is gated on capability and connection.
	 */
	public function test_capability_and_connection_gate() {
		global $mock_user_can;

		$subject = $this->subject();
		$this->assertArrayHasKey( 'specflux_mac_analytics', $subject->add_column( array( 'title' => 'Title' ) ) );

		$mock_user_can = false;
		$this->assertSame( array( 'title' => 'Title' ), $subject->add_column( array( 'title' => 'Title' ) ) );
		ob_start();
		$subject->render_column( 'specflux_mac_analytics', 1 );
		$this->assertSame( '', ob_get_clean() );

		$mock_user_can = true;
		$none          = $this->subject( array() );
		$this->assertSame( array( 'title' => 'Title' ), $none->add_column( array( 'title' => 'Title' ) ) );
	}

	/**
	 * Column goes before the date column.
	 */
	public function test_column_position() {
		$columns = $this->subject()->add_column(
			array(
				'title' => 'T',
				'date'  => 'D',
			)
		);
		$this->assertSame( array( 'title', 'specflux_mac_analytics', 'date' ), array_keys( $columns ) );
	}

	/**
	 * Post type list is filtered and excludes attachments.
	 */
	public function test_post_types() {
		$this->assertSame( array( 'post', 'page' ), $this->subject()->get_post_types() );
	}

	/**
	 * build_cache queries each platform once, with the requested shape.
	 */
	public function test_build_cache_requests_and_ttl() {
		global $mock_transients;

		$subject                = $this->subject();
		$subject->ga4_response  = array(
			'rows' => array(
				array(
					'pagePath'        => '/blog/hello/',
					'screenPageViews' => '10',
				),
			),
		);
		$subject->gsc_response  = array(
			'rows' => array(
				array(
					'key'         => 'https://example.com/blog/hello/',
					'clicks'      => 2,
					'impressions' => 20,
					'position'    => 5.0,
				),
			),
		);
		$cache = $subject->build_cache();

		$this->assertSame( 1, $subject->calls['ga4'] );
		$this->assertSame( 1, $subject->calls['gsc'] );
		$this->assertSame( array( 'screenPageViews' ), $subject->args['ga4'][0] );
		$this->assertSame( array( 'pagePath' ), $subject->args['ga4'][1] );
		$this->assertSame( '28daysAgo', $subject->args['ga4'][2] );
		$this->assertSame( 2000, $subject->args['ga4'][3]['limit'] );
		$this->assertSame( array( 'page' ), $subject->args['gsc'][1] );
		$this->assertSame( 5000, $subject->args['gsc'][3]['row_limit'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2},\d{4}-\d{2}-\d{2}$/', $subject->args['gsc'][0] );
		$this->assertSame( array(), $cache['errors'] );
		$this->assertSame( 10, $cache['ga4']['/blog/hello'] );
		$this->assertEqualsWithDelta( 12 * HOUR_IN_SECONDS, $mock_transients['specflux_mac_post_stats']['expiration'] - time(), 5 );
	}

	/**
	 * Warm cache means rendering and the AJAX path make no API calls.
	 */
	public function test_transient_hit_avoids_api_calls() {
		global $mock_json_responses;

		$subject = $this->subject();
		set_transient(
			'specflux_mac_post_stats',
			array(
				'platforms' => array( 'ga4', 'gsc' ),
				'ga4'       => array( '/blog/hello' => 3 ),
				'gsc'       => array( '/blog/hello' => array( 1, 2, 4.0 ) ),
				'errors'    => array(),
			),
			HOUR_IN_SECONDS
		);

		ob_start();
		$subject->render_column( 'specflux_mac_analytics', 1 );
		$out = ob_get_clean();
		$this->assertStringContainsString( '3 views · 1 click', $out );

		$_POST['nonce']    = 'n';
		$_POST['post_ids'] = array( '1', '2', 'abc' );
		$subject->handle_ajax();

		$this->assertSame( 0, $subject->calls['ga4'] );
		$this->assertSame( 0, $subject->calls['gsc'] );
		$this->assertTrue( $mock_json_responses[0]['success'] );
		$this->assertSame( array( 1, 2 ), array_keys( $mock_json_responses[0]['data']['cells'] ) );
	}

	/**
	 * A cache built for different connections is treated as cold.
	 */
	public function test_cache_invalidated_when_connections_change() {
		set_transient(
			'specflux_mac_post_stats',
			array(
				'platforms' => array( 'ga4' ),
				'ga4'       => array(),
				'gsc'       => array(),
				'errors'    => array(),
			),
			HOUR_IN_SECONDS
		);
		$this->assertNull( $this->subject( array( 'ga4', 'gsc' ) )->get_cached() );
		$this->assertNotNull( $this->subject( array( 'ga4' ) )->get_cached() );
	}

	/**
	 * API failure caches an error for one hour and never throws.
	 */
	public function test_api_exception_caches_error() {
		global $mock_transients;

		$subject               = $this->subject();
		$subject->ga4_response = new \Exception( 'GA4 API error: boom' );
		$subject->gsc_response = array(
			'rows' => array(
				array(
					'key'         => 'https://example.com/blog/hello/',
					'clicks'      => 4,
					'impressions' => 10,
					'position'    => 2.0,
				),
			),
		);

		$cache = $subject->build_cache();

		$this->assertTrue( $cache['errors']['ga4'] );
		$this->assertArrayNotHasKey( 'gsc', $cache['errors'] );
		$this->assertEqualsWithDelta( HOUR_IN_SECONDS, $mock_transients['specflux_mac_post_stats']['expiration'] - time(), 5 );

		$html = $subject->get_cell_html( 1, $cache );
		$this->assertStringContainsString( '4 clicks', $html );
		$this->assertStringNotContainsString( 'view', $html );
		$this->assertStringContainsString( 'title="', $html );
		$this->assertStringNotContainsString( 'boom', $html );
	}

	/**
	 * Every connected platform failing yields a dash with an explanatory title.
	 */
	public function test_all_failed_cell() {
		$subject               = $this->subject();
		$subject->ga4_response = new \Exception( 'x' );
		$subject->gsc_response = new \Exception( 'y' );
		$cache                 = $subject->build_cache();

		$html = $subject->get_cell_html( 1, $cache );
		$this->assertStringContainsString( 'smac-post-stats--error', $html );
		$this->assertStringContainsString( '&mdash;', $html );
		$this->assertStringContainsString( 'title="', $html );
	}

	/**
	 * AJAX rejects bad nonces and unauthorised users before touching APIs.
	 */
	public function test_ajax_security() {
		global $mock_json_responses, $mock_user_can, $mock_nonce_valid;

		$subject        = $this->subject();
		$_POST['nonce'] = 'n';

		$mock_nonce_valid = false;
		$subject->handle_ajax();
		$this->assertFalse( $mock_json_responses[0]['success'] );

		$mock_nonce_valid = true;
		$mock_user_can    = false;
		$subject->handle_ajax();
		$this->assertFalse( $mock_json_responses[1]['success'] );

		$this->assertSame( 0, $subject->calls['ga4'] + $subject->calls['gsc'] );
	}

	/**
	 * Cold-cache AJAX builds the cache once and returns cells.
	 */
	public function test_ajax_builds_cache_when_cold() {
		global $mock_json_responses;

		$subject                = $this->subject();
		$subject->ga4_response  = array(
			'rows' => array(
				array(
					'pagePath'        => '/blog/hello/',
					'screenPageViews' => '1204',
				),
			),
		);
		$_POST['nonce']         = 'n';
		$_POST['post_ids']      = array( '1' );

		$subject->handle_ajax();

		$this->assertSame( 1, $subject->calls['ga4'] );
		$this->assertSame( 1, $subject->calls['gsc'] );
		$this->assertTrue( $mock_json_responses[0]['success'] );
		$this->assertStringContainsString( '1,204 views', $mock_json_responses[0]['data']['cells'][1] );
	}

	/**
	 * A capped report cannot prove a missing post has zero views.
	 */
	public function test_capped_ga4_map_shows_dash_for_missing_post() {
		$subject = $this->subject();
		$rows    = array();
		for ( $i = 0; $i < 2000; $i++ ) {
			$rows[] = array(
				'pagePath'        => '/other-' . $i . '/',
				'screenPageViews' => '1',
			);
		}
		$subject->ga4_response = array( 'rows' => $rows );

		$cache = $subject->build_cache();
		$this->assertTrue( $cache['capped']['ga4'] );
		$this->assertArrayNotHasKey( 'gsc', $cache['capped'] );

		$html = $subject->get_cell_html( 1, $cache );
		$this->assertStringContainsString( '— views', $html );
		$this->assertStringNotContainsString( '0 views', $html );
	}

	/**
	 * An uncapped report keeps the honest zero.
	 */
	public function test_uncapped_ga4_map_shows_zero_for_missing_post() {
		$subject               = $this->subject();
		$subject->ga4_response = array(
			'rows' => array(
				array(
					'pagePath'        => '/other/',
					'screenPageViews' => '5',
				),
			),
		);

		$cache = $subject->build_cache();
		$this->assertArrayNotHasKey( 'ga4', $cache['capped'] );
		$this->assertStringContainsString( '0 views', $subject->get_cell_html( 1, $cache ) );
	}

	/**
	 * Credentials are resolved a bounded number of times however many rows render.
	 */
	public function test_platform_and_cache_lookups_are_memoised() {
		$calls       = 0;
		$credentials = $this->createMock( Credential_Manager::class );
		$credentials->method( 'has_credentials' )->willReturnCallback(
			function () use ( &$calls ) {
				++$calls;
				return true;
			}
		);
		$subject = new Post_Stats_Testable( $credentials );
		$subject->build_cache();
		$after_build = $calls;

		ob_start();
		for ( $i = 0; $i < 25; $i++ ) {
			$subject->render_column( Post_Stats::COLUMN, 1 );
		}
		ob_end_clean();

		$this->assertSame( $after_build, $calls );
		$this->assertLessThanOrEqual( 2, $calls );
	}

	/**
	 * Entities in the title are decoded before they reach the prompt.
	 */
	public function test_chat_url_decodes_title_entities() {
		global $mock_posts;
		$mock_posts[1]['title'] = 'Q&amp;A &#8211; Tips';

		$subject = $this->subject();
		$html    = $subject->get_cell_html( 1, $subject->build_cache() );

		$this->assertStringContainsString( 'Q%26A%20', $html );
		$this->assertStringNotContainsString( 'amp%3B', $html );
	}

	/**
	 * Set the simulated editor screen and post.
	 *
	 * @param string $post_type Post type.
	 * @param int    $post_id   Post ID.
	 */
	private function editor_screen( $post_type, $post_id ) {
		$GLOBALS['mock_current_screen']   = (object) array( 'post_type' => $post_type );
		$GLOBALS['mock_current_post_id']  = $post_id;
		$GLOBALS['mock_enqueued_scripts'] = array();
		$GLOBALS['mock_inline_scripts']   = array();
	}

	/**
	 * The editor script loads only for connected users on eligible post types.
	 */
	public function test_editor_assets_enqueue_gating() {
		global $mock_user_can;
		$handle = 'specflux-mac-editor-stats-panel';

		$this->editor_screen( 'post', 1 );
		$this->subject()->enqueue_editor_assets();
		$this->assertArrayHasKey( $handle, $GLOBALS['mock_enqueued_scripts'] );
		$this->assertContains( 'wp-plugins', $GLOBALS['mock_enqueued_scripts'][ $handle ] );
		$this->assertSame( 'specflux-marketing-analytics-chat', $GLOBALS['mock_script_translations'][ $handle ] );
		$this->assertStringStartsWith( 'window.specfluxMacEditorStats = {', $GLOBALS['mock_inline_scripts'][ $handle ] );

		$this->editor_screen( 'attachment', 1 );
		$this->subject()->enqueue_editor_assets();
		$this->assertArrayNotHasKey( $handle, $GLOBALS['mock_enqueued_scripts'] );

		$this->editor_screen( 'post', 1 );
		$this->subject( array() )->enqueue_editor_assets();
		$this->assertArrayNotHasKey( $handle, $GLOBALS['mock_enqueued_scripts'] );

		$this->editor_screen( 'post', 1 );
		$mock_user_can = false;
		$this->subject()->enqueue_editor_assets();
		$this->assertArrayNotHasKey( $handle, $GLOBALS['mock_enqueued_scripts'] );

		unset( $GLOBALS['mock_current_screen'], $GLOBALS['mock_current_post_id'] );
	}

	/**
	 * Panel payload for warm, cold, unpublished and capped caches.
	 */
	public function test_panel_data_states() {
		$subject               = $this->subject();
		$subject->ga4_response = array(
			'rows' => array(
				array(
					'pagePath'        => '/blog/hello/',
					'screenPageViews' => '1204',
				),
			),
		);
		$subject->gsc_response = array(
			'rows' => array(
				array(
					'keys'        => array( 'https://example.com/blog/hello/' ),
					'clicks'      => 12,
					'impressions' => 340,
					'position'    => 8.26,
				),
			),
		);

		$cold = $subject->get_panel_data( 1, null );
		$this->assertSame( 'needs_build', $cold['state'] );
		$this->assertSame( 0, $subject->calls['ga4'] + $subject->calls['gsc'] );

		$warm = $subject->get_panel_data( 1, $subject->build_cache() );
		$this->assertSame( 'ready', $warm['state'] );
		$this->assertSame( '1,204', $warm['values']['views'] );
		$this->assertSame( '12', $warm['values']['clicks'] );
		$this->assertSame( '340', $warm['values']['impressions'] );
		$this->assertSame( '8.3', $warm['values']['position'] );
		$this->assertStringContainsString( 'page=specflux-mac-ai-assistant&prompt=', $warm['chatUrl'] );

		$draft = $subject->get_panel_data( 3, $subject->build_cache() );
		$this->assertSame( 'unpublished', $draft['state'] );

		$rows = array();
		for ( $i = 0; $i < 2000; $i++ ) {
			$rows[] = array(
				'pagePath'        => '/other-' . $i . '/',
				'screenPageViews' => '1',
			);
		}
		$capped_subject               = $this->subject();
		$capped_subject->ga4_response = array( 'rows' => $rows );
		$capped                       = $capped_subject->get_panel_data( 1, $capped_subject->build_cache() );
		$this->assertSame( '—', $capped['values']['views'] );
		$this->assertSame( '0', $capped['values']['clicks'] );

		$failed_subject               = $this->subject();
		$failed_subject->ga4_response = new \RuntimeException( 'boom' );
		$failed                       = $failed_subject->get_panel_data( 1, $failed_subject->build_cache() );
		$this->assertSame( '—', $failed['values']['views'] );
		$this->assertTrue( $failed['failed'] );
	}

	/**
	 * Panel endpoint rejects bad nonce and missing capability before any API call.
	 */
	public function test_panel_ajax_security() {
		global $mock_json_responses, $mock_user_can, $mock_nonce_valid;

		$subject             = $this->subject();
		$_POST['nonce']      = 'n';
		$_POST['post_id']    = '1';

		unset( $_POST['nonce'] );
		$subject->handle_panel_ajax();
		$this->assertFalse( $mock_json_responses[0]['success'] );

		$_POST['nonce']   = 'n';
		$mock_nonce_valid = false;
		$subject->handle_panel_ajax();
		$this->assertFalse( $mock_json_responses[1]['success'] );

		$mock_nonce_valid = true;
		$mock_user_can    = false;
		$subject->handle_panel_ajax();
		$this->assertFalse( $mock_json_responses[2]['success'] );

		$mock_user_can    = true;
		$_POST['post_id'] = '0';
		$subject->handle_panel_ajax();
		$this->assertFalse( $mock_json_responses[3]['success'] );

		$this->assertSame( 0, $subject->calls['ga4'] + $subject->calls['gsc'] );
		unset( $_POST['post_id'] );
	}

	/**
	 * Panel endpoint builds a cold cache once for a published post only.
	 */
	public function test_panel_ajax_builds_once() {
		global $mock_json_responses;

		$subject          = $this->subject();
		$_POST['nonce']   = 'n';
		$_POST['post_id'] = '3';
		$subject->handle_panel_ajax();
		$this->assertSame( 'unpublished', $mock_json_responses[0]['data']['state'] );
		$this->assertSame( 0, $subject->calls['ga4'] );

		$_POST['post_id'] = '1';
		$subject->handle_panel_ajax();
		$subject->handle_panel_ajax();
		$this->assertSame( 'ready', $mock_json_responses[2]['data']['state'] );
		$this->assertSame( 1, $subject->calls['ga4'] );
		$this->assertSame( 1, $subject->calls['gsc'] );
		unset( $_POST['post_id'] );
	}
}
