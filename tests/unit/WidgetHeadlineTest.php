<?php
/**
 * Tests for the dashboard widget headline row.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Admin\Ajax_Handler;
use Specflux_Marketing_Analytics\Admin\Widget_Headline;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary;

/**
 * Weekly_Summary double returning a canned context.
 */
class Fake_Weekly_Summary extends Weekly_Summary {
	/**
	 * Connected platforms.
	 *
	 * @var string[]
	 */
	private $fake_connected;

	/**
	 * Canned context, or an exception to throw.
	 *
	 * @var array|\Throwable
	 */
	private $fake_context;

	/**
	 * Constructor.
	 *
	 * @param string[]         $connected Connected platforms.
	 * @param array|\Throwable $context   Context or exception.
	 */
	public function __construct( array $connected, $context ) {
		parent::__construct();
		$this->fake_connected = $connected;
		$this->fake_context   = $context;
	}

	/**
	 * Connected platforms.
	 *
	 * @return string[]
	 */
	public function get_connected_platforms() {
		return $this->fake_connected;
	}

	/**
	 * Canned collect().
	 *
	 * @param int|null $now Ignored.
	 * @return array
	 * @throws \Throwable When constructed with an exception.
	 */
	public function collect( $now = null ) {
		if ( $this->fake_context instanceof \Throwable ) {
			throw $this->fake_context;
		}
		return $this->fake_context;
	}
}

/**
 * Widget headline tests.
 */
class WidgetHeadlineTest extends TestCase {

	/**
	 * Reset stored state.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_transients, $mock_options, $mock_json_responses, $mock_nonce_valid, $mock_user_can;
		$mock_transients     = array();
		$mock_options        = array();
		$mock_json_responses = array();
		$mock_nonce_valid    = true;
		$mock_user_can       = true;
		$_POST               = array();
	}

	/**
	 * A realistic collected context.
	 *
	 * @return array
	 */
	private function context() {
		return array(
			'platforms' => array(
				'ga4'     => array(
					'status' => 'ok',
					'data'   => array(
						'metrics' => array(
							array(
								'key'     => 'sessions',
								'current' => 1200.0,
								'change'  => 20.0,
							),
						),
					),
				),
				'gsc'     => array(
					'status' => 'ok',
					'data'   => array(
						'metrics' => array(
							array(
								'key'     => 'clicks',
								'current' => 80.0,
								'change'  => -12.5,
							),
						),
					),
				),
				'clarity' => array(
					'status' => 'ok',
					'data'   => array(
						'stats' => array(
							array(
								'key'   => 'sessions',
								'raw'   => 345.0,
								'label' => 'Sessions',
								'value' => '345',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Numbers come straight from the weekly summary result.
	 */
	public function test_headline_numbers_assembled_from_weekly_summary(): void {
		$items = Widget_Headline::build( new Fake_Weekly_Summary( array( 'ga4', 'gsc', 'clarity' ), $this->context() ) );

		$this->assertCount( 3, $items );
		$this->assertSame( 'ga4', $items[0]['platform'] );
		$this->assertSame( 1200.0, $items[0]['value'] );
		$this->assertSame( 20.0, $items[0]['change'] );
		$this->assertSame( -12.5, $items[1]['change'] );
		$this->assertSame( 345.0, $items[2]['value'] );
		$this->assertFalse( $items[2]['has_change'] );
		$this->assertNull( $items[2]['change'] );

		$html = Widget_Headline::render( $items );
		$this->assertStringContainsString( '1,200', $html );
		$this->assertStringContainsString( 'is-up', $html );
		$this->assertStringContainsString( 'is-down', $html );
	}

	/**
	 * Only connected platforms appear.
	 */
	public function test_only_connected_platforms_are_listed(): void {
		$items = Widget_Headline::build( new Fake_Weekly_Summary( array( 'gsc' ), $this->context() ) );

		$this->assertCount( 1, $items );
		$this->assertSame( 'gsc', $items[0]['platform'] );
	}

	/**
	 * A platform error yields a null number, not an exception.
	 */
	public function test_platform_error_yields_null_number(): void {
		$context                                = $this->context();
		$context['platforms']['ga4']['status']  = 'error';
		$context['platforms']['ga4']['data']    = array();

		$items = Widget_Headline::build( new Fake_Weekly_Summary( array( 'ga4', 'gsc' ), $context ) );

		$this->assertNull( $items[0]['value'] );
		$this->assertNull( $items[0]['change'] );
		$this->assertSame( 80.0, $items[1]['value'] );
		$this->assertStringContainsString( '&mdash;', Widget_Headline::render( $items ) );
	}

	/**
	 * A collector that throws outright still produces null numbers.
	 */
	public function test_collect_exception_yields_null_numbers(): void {
		$items = Widget_Headline::build( new Fake_Weekly_Summary( array( 'ga4', 'clarity' ), new \RuntimeException( 'boom' ) ) );

		$this->assertCount( 2, $items );
		$this->assertNull( $items[0]['value'] );
		$this->assertNull( $items[1]['value'] );
	}

	/**
	 * Nothing connected renders the empty state with a Connections button.
	 */
	public function test_empty_state_when_nothing_connected(): void {
		$html = Widget_Headline::render( Widget_Headline::build( new Fake_Weekly_Summary( array(), array() ) ) );

		$this->assertStringContainsString( 'Connect Google Analytics, Search Console or Clarity to see your numbers here.', $html );
		$this->assertStringContainsString( 'button-primary', $html );
		$this->assertStringContainsString( 'admin.php?page=specflux-mac-connections', $html );
	}

	/**
	 * The transient lasts three hours.
	 */
	public function test_ttl_is_three_hours(): void {
		$this->assertSame( 3 * HOUR_IN_SECONDS, Widget_Headline::ttl() );

		Widget_Headline::refresh( new Fake_Weekly_Summary( array( 'ga4' ), $this->context() ) );

		global $mock_transients;
		$this->assertEqualsWithDelta( time() + 3 * HOUR_IN_SECONDS, $mock_transients[ Widget_Headline::TRANSIENT ]['expiration'], 5 );
	}

	/**
	 * The refresh response carries the headline data and is cached for rendering.
	 */
	public function test_refresh_response_contains_headline_data(): void {
		$response = Widget_Headline::refresh( new Fake_Weekly_Summary( array( 'ga4' ), $this->context() ) );

		$this->assertSame( 1200.0, $response['headline'][0]['value'] );
		$this->assertStringContainsString( '1,200', $response['headline_html'] );
		$this->assertNotNull( Widget_Headline::cached() );
	}

	/**
	 * The AJAX handler itself returns headline data in its response.
	 */
	public function test_ajax_refresh_returns_headline(): void {
		global $mock_json_responses;
		$_POST['nonce'] = 'valid';

		( new Ajax_Handler() )->handle_refresh_widget_data();

		$this->assertTrue( $mock_json_responses[0]['success'] );
		$this->assertArrayHasKey( 'headline', $mock_json_responses[0]['data'] );
		$this->assertArrayHasKey( 'headline_html', $mock_json_responses[0]['data'] );
	}

	/**
	 * The email footer link reflects the on/off state.
	 */
	public function test_email_link_reflects_state(): void {
		$on  = Widget_Headline::render_email_link( true );
		$off = Widget_Headline::render_email_link( false );

		$this->assertStringContainsString( 'admin.php?page=specflux-mac-settings&tab=email', $on );
		$this->assertStringContainsString( 'Weekly email summary: on', preg_replace( '/\s+/', ' ', $on ) );
		$this->assertStringContainsString( 'Weekly email summary: off', preg_replace( '/\s+/', ' ', $off ) );
	}
}
