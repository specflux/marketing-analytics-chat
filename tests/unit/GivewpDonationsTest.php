<?php
/**
 * Tests for the GiveWP donations reader, platform, ability, weekly email and widget row.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Abilities\Abilities_Registrar;
use Specflux_Marketing_Analytics\Abilities\Givewp_Abilities;
use Specflux_Marketing_Analytics\Admin\Widget_Headline;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;
use Specflux_Marketing_Analytics\Donations\Givewp_Donations_Reader;
use Specflux_Marketing_Analytics\Donations\Givewp_Platform;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary;

/**
 * Reader fed from an in-memory donation list instead of the database.
 */
class Givewp_Reader_Double extends Givewp_Donations_Reader {

	/**
	 * Donations (each with a `date` Y-m-d key).
	 *
	 * @var array[]
	 */
	public $donations = array();

	/**
	 * Whether GiveWP counts as active.
	 *
	 * @var bool
	 */
	public $active = true;

	/**
	 * Number of fetches.
	 *
	 * @var int
	 */
	public $fetches = 0;

	/**
	 * Active flag.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return $this->active;
	}

	/**
	 * Donations in the range.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @return array[]
	 */
	protected function fetch_donations( string $start_date, string $end_date ): array {
		++$this->fetches;
		return array_values(
			array_filter(
				$this->donations,
				static function ( $d ) use ( $start_date, $end_date ) {
					return $d['date'] >= $start_date && $d['date'] <= $end_date;
				}
			)
		);
	}

	/**
	 * Form title without WordPress.
	 *
	 * @param int $id Form ID.
	 * @return string
	 */
	protected function form_label( int $id ): string {
		return 'Form ' . $id;
	}

	/**
	 * Campaign title without a database.
	 *
	 * @param int $id Campaign ID.
	 * @return string
	 */
	protected function campaign_label( int $id ): string {
		return 'Campaign ' . $id;
	}

	/**
	 * Run the real fetch_donations() against the injected $wpdb.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return array[]
	 */
	public function real_fetch( string $start, string $end ): array {
		return Givewp_Donations_Reader::fetch_donations( $start, $end );
	}
}

/**
 * Tests.
 */
class GivewpDonationsTest extends TestCase {

	const START = '2026-10-01';
	const END   = '2026-10-07';

	/**
	 * Reset shared state.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['mock_transients'] = array();
		Abilities_Registrar::reset();
		Platform_Registry::reset();
	}

	/**
	 * Restore shared state.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		$GLOBALS['mock_transients'] = array();
		Abilities_Registrar::reset();
		Platform_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Build a donation row.
	 *
	 * @param float      $amount      Amount in major units.
	 * @param array|null $attribution Attribution meta.
	 * @param array      $extra       Overrides.
	 * @return array
	 */
	private function donation( float $amount, ?array $attribution = null, array $extra = array() ): array {
		return array_merge(
			array(
				'date'             => '2026-10-03',
				'amount'           => $amount,
				'currency'         => 'USD',
				'form_id'          => 5,
				'give_campaign_id' => 0,
				'mode'             => 'live',
				'donor_id'         => 1,
				'recurring'        => false,
				'attribution'      => $attribution,
			),
			$extra
		);
	}

	/**
	 * Reader double.
	 *
	 * @param array[] $donations Donations.
	 * @return Givewp_Reader_Double
	 */
	private function reader( array $donations = array() ): Givewp_Reader_Double {
		$reader            = new Givewp_Reader_Double();
		$reader->donations = $donations;
		return $reader;
	}

	/**
	 * Index currency blocks by code.
	 *
	 * @param array $result Reader result.
	 * @return array
	 */
	private function by_currency( array $result ): array {
		$out = array();
		foreach ( $result['currencies'] as $row ) {
			$out[ $row['currency'] ] = $row;
		}
		return $out;
	}

	/**
	 * GiveWP missing: nothing is advertised or registered.
	 */
	public function test_givewp_missing(): void {
		$this->assertFalse( Givewp_Donations_Reader::is_available() );

		$off         = $this->reader();
		$off->active = false;
		( new Givewp_Abilities( $off ) )->register();
		$this->assertSame( array(), Abilities_Registrar::get_registered_tools() );

		$platform = new Givewp_Platform( $off );
		$this->assertSame( array(), $platform->register_platform( array() ) );
		$this->assertFalse( $platform->test_connection()['success'] );

		$this->assertNull( ( new Weekly_Summary( array(), array(), null, null, $off ) )->collect_donations( $this->range() ) );
	}

	/**
	 * Weekly range in the shape Weekly_Summary uses.
	 *
	 * @return array
	 */
	private function range(): array {
		return array(
			'current'  => array( self::START, self::END ),
			'previous' => array( '2026-09-24', '2026-09-30' ),
		);
	}

	/**
	 * No donations: empty currencies, no zero-filled currency block.
	 */
	public function test_empty(): void {
		$result = $this->reader()->get_donations_by_source( self::START, self::END );
		$this->assertSame( 0, $result['total_donations'] );
		$this->assertSame( array(), $result['currencies'] );
		$this->assertNull( ( new Weekly_Summary( array(), array(), null, null, $this->reader() ) )->collect_donations( $this->range() ) );
	}

	/**
	 * Currencies are never added together.
	 */
	public function test_multi_currency_kept_separate(): void {
		$result = $this->by_currency(
			$this->reader(
				array(
					$this->donation( 100.0, array( 'source_type' => 'typein' ) ),
					$this->donation( 50.0, array( 'source_type' => 'typein' ) ),
					$this->donation( 80.0, array( 'source_type' => 'typein' ), array( 'currency' => 'EUR', 'donor_id' => 2 ) ),
				)
			)->get_donations_by_source( self::START, self::END, 'channel', 20, false )
		);

		$this->assertSame( 150.0, $result['USD']['amount'] );
		$this->assertSame( 2, $result['USD']['donations'] );
		$this->assertSame( 80.0, $result['EUR']['amount'] );
		$this->assertSame( 1, $result['EUR']['donors'] );
		$this->assertCount( 2, $result );
	}

	/**
	 * Test-mode donations are excluded.
	 */
	public function test_test_mode_excluded(): void {
		$result = $this->reader(
			array(
				$this->donation( 100.0 ),
				$this->donation( 999.0, null, array( 'mode' => 'test' ) ),
			)
		)->get_donations_by_source( self::START, self::END, 'channel', 20, false );

		$this->assertSame( 1, $result['total_donations'] );
		$this->assertSame( 100.0, $result['currencies'][0]['amount'] );
	}

	/**
	 * Only completed statuses reach the aggregate: the SQL asks for publish and give_subscription only.
	 */
	public function test_only_complete_statuses_queried(): void {
		$this->assertSame( array( 'publish', 'give_subscription' ), Givewp_Donations_Reader::COMPLETE_STATUSES );
		foreach ( array( 'refunded', 'pending', 'failed', 'cancelled', 'abandoned', 'revoked', 'processing', 'preapproval' ) as $status ) {
			$this->assertNotContains( $status, Givewp_Donations_Reader::COMPLETE_STATUSES );
		}

		$wpdb = $this->install_wpdb( array() );
		$this->reader()->real_fetch( self::START, self::END );
		$this->assertStringContainsString( "post_type = 'give_payment'", $wpdb->prepared[0][0] );
		$this->assertSame( array( 'publish', 'give_subscription' ), array_slice( $wpdb->prepared[0][1], 0, 2 ) );
		$this->assertStringNotContainsString( 'refunded', wp_json_encode( $wpdb->prepared ) );
	}

	/**
	 * Missing attribution is Not tracked in every grouping, never zero-filled or invented.
	 */
	public function test_untracked_is_not_tracked(): void {
		$reader = $this->reader(
			array(
				$this->donation( 60.0, array( 'source_type' => 'utm', 'source' => 'google', 'medium' => 'cpc', 'campaign' => 'Spring', 'landing_page' => '/give' ) ),
				$this->donation( 40.0, null ),
				$this->donation( 10.0, array() ),
			)
		);

		$rows = array();
		foreach ( $reader->get_donations_by_source( self::START, self::END, 'channel', 20, false )['currencies'][0]['rows'] as $row ) {
			$rows[ $row['label'] ] = $row;
		}
		$this->assertSame( 50.0, $rows['Not tracked']['amount'] );
		$this->assertSame( 2, $rows['Not tracked']['donations'] );
		$this->assertSame( 60.0, $rows['Paid Search']['amount'] );

		foreach ( array( 'source', 'campaign' ) as $group ) {
			$labels = array_column( $reader->get_donations_by_source( self::START, self::END, $group, 20, false )['currencies'][0]['rows'], 'label' );
			$this->assertContains( 'Not tracked', $labels, $group );
		}

		$result = $reader->get_donations_by_source( self::START, self::END, 'channel', 20, false );
		$this->assertStringContainsString( '2 of 3 donations have no recorded source', $result['note'] );
	}

	/**
	 * Recurring share, donor count, previous-period comparison and form grouping.
	 */
	public function test_recurring_previous_period_and_forms(): void {
		$reader = $this->reader(
			array(
				$this->donation( 100.0, array( 'source_type' => 'typein' ), array( 'recurring' => true, 'donor_id' => 7 ) ),
				$this->donation( 100.0, array( 'source_type' => 'typein' ), array( 'donor_id' => 7, 'form_id' => 6 ) ),
				$this->donation( 100.0, array( 'source_type' => 'typein' ), array( 'date' => '2026-09-26', 'donor_id' => 8 ) ),
			)
		);

		$usd = $reader->get_donations_by_source( self::START, self::END, 'channel', 20, true )['currencies'][0];
		$this->assertSame( 2, $usd['donations'] );
		$this->assertSame( 1, $usd['donors'] );
		$this->assertSame( 50.0, $usd['recurring_share'] );
		$this->assertSame( 100.0, $usd['previous_amount'] );
		$this->assertSame( 100.0, $usd['amount_change'] );

		$forms = array_column( $reader->get_donations_by_source( self::START, self::END, 'form', 20, false )['currencies'][0]['rows'], 'label' );
		$this->assertEqualsCanonicalizing( array( 'Form 5', 'Form 6' ), $forms );
	}

	/**
	 * Results are cached.
	 */
	public function test_results_are_cached(): void {
		$reader = $this->reader( array( $this->donation( 10.0 ) ) );
		$reader->get_donations_by_source( self::START, self::END, 'channel', 20, false );
		$reader->get_donations_by_source( self::START, self::END, 'channel', 20, false );
		$this->assertSame( 1, $reader->fetches );
	}

	/**
	 * Personal-data meta keys are never requested, and none leak into the output.
	 */
	public function test_personal_data_keys_never_requested(): void {
		foreach ( Givewp_Donations_Reader::META_KEYS as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/email|first_name|last_name|_ip|ip_address|address|phone|name/i', $key, $key );
		}

		$wpdb = $this->install_wpdb(
			array(
				(object) array( 'donation_id' => 11, 'meta_key' => '_give_payment_total', 'meta_value' => '25.000000' ),
				(object) array( 'donation_id' => 11, 'meta_key' => '_give_payment_currency', 'meta_value' => 'USD' ),
				(object) array( 'donation_id' => 11, 'meta_key' => 'subscription_id', 'meta_value' => '4' ),
				(object) array( 'donation_id' => 11, 'meta_key' => '_specflux_mac_attribution', 'meta_value' => serialize( array( 'source_type' => 'typein' ) ) ),
			)
		);
		$donations = $this->reader()->real_fetch( self::START, self::END );

		$meta_query = $wpdb->prepared[1];
		foreach ( array( '_give_donor_billing_first_name', '_give_donor_billing_last_name', '_give_payment_donor_email', '_give_payment_user_ip', 'email', 'ip' ) as $banned ) {
			$this->assertNotContains( $banned, $meta_query[1], $banned );
		}
		$this->assertSame( Givewp_Donations_Reader::META_KEYS, array_slice( $meta_query[1], -count( Givewp_Donations_Reader::META_KEYS ) ) );

		$this->assertSame( 25.0, $donations[0]['amount'] );
		$this->assertTrue( $donations[0]['recurring'] );
		$this->assertSame( 'typein', $donations[0]['attribution']['source_type'] );

		$json = wp_json_encode( $this->reader( array( $this->donation( 10.0, array( 'source_type' => 'typein' ) ) ) )->get_donations_by_source( self::START, self::END, 'channel', 20, true ) );
		$this->assertDoesNotMatchRegularExpression( '/"[a-z_]*(email|name|address|ip|donation_id)[a-z_]*":/', $json );
	}

	/**
	 * Ability is read-only and returns the grouped result.
	 */
	public function test_ability_registered_and_readonly(): void {
		$reader = $this->reader( array( $this->donation( 10.0, array( 'source_type' => 'typein' ) ) ) );
		( new Givewp_Abilities( $reader ) )->register();
		$tools = Abilities_Registrar::get_registered_tools();
		$this->assertArrayHasKey( 'marketing-analytics/get-donations-by-source', $tools );
		$this->assertTrue( $tools['marketing-analytics/get-donations-by-source']['meta']['annotations']['readonly'] );

		$result = ( new Givewp_Abilities( $reader ) )->execute_get_donations_by_source( array( 'start_date' => self::START, 'end_date' => self::END ) );
		$this->assertStringContainsString( 'Direct', wp_json_encode( $result ) );
	}

	/**
	 * Platform registers only with GiveWP and passes its connection test.
	 */
	public function test_platform_registers_with_givewp(): void {
		$active = new Givewp_Platform( $this->reader() );
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = array( $active, 'register_platform' );
		Platform_Registry::reset();
		$definition = Platform_Registry::get( 'givewp' );
		$this->assertSame( 'none', $definition['auth'] );
		$this->assertStringContainsString( 'get-donations-by-source', $definition['prompt_hint'] );
		$this->assertTrue( $active->test_connection()['success'] );
	}

	/**
	 * Weekly collector gives one entry per currency; the widget row renders them.
	 */
	public function test_weekly_collect_and_widget_row(): void {
		$day     = gmdate( 'Y-m-d', strtotime( '-2 days' ) );
		$reader  = $this->reader(
			array(
				$this->donation( 100.0, null, array( 'date' => $day ) ),
				$this->donation( 70.0, null, array( 'date' => $day, 'currency' => 'EUR' ) ),
			)
		);
		$summary = new Weekly_Summary( array(), array(), null, null, $reader );
		$data    = $summary->collect_donations( $summary->get_ranges()['ga4'] );

		$this->assertCount( 2, $data['currencies'] );

		$html = Widget_Headline::render_donations( $data );
		$this->assertStringContainsString( 'Donations: 100.00 USD', $html );
		$this->assertStringContainsString( 'Donations: 70.00 EUR', $html );
		$this->assertSame( '', Widget_Headline::render_donations( 'none' ) );
		$this->assertSame( '', Widget_Headline::render_donations( null ) );

		$this->assertNull( Widget_Headline::cached_donations() );
		set_transient( Widget_Headline::DONATIONS_TRANSIENT, 'none', 60 );
		$this->assertSame( 'none', Widget_Headline::cached_donations() );
	}

	/**
	 * Swap in a recording $wpdb for the real fetch.
	 *
	 * @param array $meta_rows Rows returned for the meta query.
	 * @return object
	 */
	private function install_wpdb( array $meta_rows ) {
		$GLOBALS['wpdb'] = new class( $meta_rows ) {
			public $prefix   = 'wp_';
			public $posts    = 'wp_posts';
			public $prepared = array();
			/**
			 * Constructor.
			 *
			 * @param array $rows Meta rows.
			 */
			public function __construct( public array $rows ) {}
			/**
			 * Record a prepare call.
			 *
			 * @param string $query Query.
			 * @param mixed  $args  Args.
			 * @return string
			 */
			public function prepare( $query, $args = array() ) {
				$this->prepared[] = array( $query, $args );
				return $query;
			}
			/**
			 * Donation IDs.
			 *
			 * @return array
			 */
			public function get_col() {
				return array( 11 );
			}
			/**
			 * Meta rows.
			 *
			 * @return array
			 */
			public function get_results() {
				return $this->rows;
			}
		};

		return $GLOBALS['wpdb'];
	}
}
