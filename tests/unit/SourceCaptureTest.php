<?php
/**
 * Tests for first-party source capture.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace {
	if ( ! function_exists( 'update_post_meta' ) ) {
		/**
		 * Recording stand-in for update_post_meta.
		 *
		 * @param int    $id    Post ID.
		 * @param string $key   Key.
		 * @param mixed  $value Value.
		 * @return bool
		 */
		function update_post_meta( $id, $key, $value ) {
			$GLOBALS['a0_writes'][] = array( 'post', $id, $key, $value );
			return true;
		}
	}
	if ( ! function_exists( 'edd_add_order_meta' ) ) {
		/**
		 * Recording stand-in for edd_add_order_meta.
		 *
		 * @param int    $id     Order ID.
		 * @param string $key    Key.
		 * @param mixed  $value  Value.
		 * @param bool   $unique Unique.
		 * @return int
		 */
		function edd_add_order_meta( $id, $key, $value, $unique = false ) {
			$GLOBALS['a0_writes'][] = array( 'edd', $id, $key, $value );
			return 1;
		}
	}
	if ( ! function_exists( 'wp_has_consent' ) ) {
		/**
		 * Stand-in for the WP Consent API.
		 *
		 * @param string $category Category.
		 * @return bool
		 */
		function wp_has_consent( $category ) {
			return ! isset( $GLOBALS['a0_consent'] ) || ( $GLOBALS['a0_consent'] && 'statistics' === $category );
		}
	}
}

namespace Specflux_Marketing_Analytics\Tests\unit {

	use PHPUnit\Framework\TestCase;
	use Specflux_Marketing_Analytics\Attribution\Attribution_Hooks;
	use Specflux_Marketing_Analytics\Attribution\Source_Capture;
	use Specflux_Marketing_Analytics\Commerce\Woo_Sales_Reader;

	/**
	 * Source capture tests.
	 */
	class SourceCaptureTest extends TestCase {

		/**
		 * Reset state.
		 */
		protected function setUp(): void {
			parent::setUp();
			global $mock_options;
			$mock_options          = array();
			$GLOBALS['a0_writes']  = array();
			$_COOKIE               = array();
			unset( $GLOBALS['a0_consent'] );
		}

		/**
		 * Reset state.
		 */
		protected function tearDown(): void {
			$_COOKIE = array();
			unset( $GLOBALS['a0_consent'], $GLOBALS['a0_writes'] );
			parent::tearDown();
		}

		/**
		 * Put a valid cookie in place.
		 *
		 * @param array $data Cookie data.
		 */
		private function set_cookie( array $data = array() ): void {
			$_COOKIE[ Source_Capture::COOKIE ] = wp_json_encode(
				array_merge(
					array(
						'source_type'  => 'utm',
						'source'       => 'x',
						'medium'       => 'email',
						'campaign'     => 'y',
						'landing_page' => '/pricing/?a=1',
					),
					$data
				)
			);
		}

		/**
		 * Parse keeps exactly the five keys and path-only landing page.
		 */
		public function test_parse_sanitizes_and_caps(): void {
			$out = Source_Capture::parse(
				wp_json_encode(
					array(
						'source_type'  => 'UTM',
						'source'       => str_repeat( 'a', 300 ),
						'medium'       => 'cpc',
						'campaign'     => 'y',
						'landing_page' => 'https://evil.test/pricing/?email=a@b.c#x',
						'email'        => 'a@b.c',
						'ip'           => '1.2.3.4',
					)
				)
			);

			$this->assertSame( Source_Capture::KEYS, array_keys( $out ) );
			$this->assertSame( 100, strlen( $out['source'] ) );
			$this->assertSame( '/pricing/', $out['landing_page'] );
			$this->assertStringNotContainsString( '@', wp_json_encode( $out ) );
		}

		/**
		 * Emails in values are dropped; unknown types and junk rejected.
		 */
		public function test_parse_rejects_bad_input(): void {
			$this->assertNull( Source_Capture::parse( '' ) );
			$this->assertNull( Source_Capture::parse( 'not json' ) );
			$this->assertNull( Source_Capture::parse( '{"source_type":"direct"}' ) );
			$out = Source_Capture::parse( '{"source_type":"typein","source":"me@x.com","medium":"(none)"}' );
			$this->assertSame( '', $out['source'] );
		}

		/**
		 * Source types are ones channel_for() understands.
		 */
		public function test_source_types_match_woo_vocabulary(): void {
			$reader = new Woo_Sales_Reader();
			$this->assertSame( Woo_Sales_Reader::CHANNEL_DIRECT, $reader->channel_for( 'typein', '(direct)', '(none)' ) );
			$this->assertSame( Woo_Sales_Reader::CHANNEL_ORGANIC_SEARCH, $reader->channel_for( 'organic', 'google', 'organic' ) );
			$this->assertSame( array( 'utm', 'organic', 'referral', 'typein' ), Source_Capture::SOURCE_TYPES );
		}

		/**
		 * URL-encoded cookie values parse.
		 */
		public function test_current_reads_cookie(): void {
			$this->set_cookie();
			$this->assertSame( 'x', Source_Capture::current()['source'] );
		}

		/**
		 * Consent denied stores nothing.
		 */
		public function test_consent_denied_stores_nothing(): void {
			$this->set_cookie();
			$GLOBALS['a0_consent'] = false;
			$this->assertNull( Source_Capture::current() );
			( new Attribution_Hooks() )->on_edd_order( 5 );
			( new Attribution_Hooks() )->on_ninja_forms( 7 );
			$this->assertSame( array(), $GLOBALS['a0_writes'] );
		}

		/**
		 * Disabled option stores nothing.
		 */
		public function test_disabled_option_stores_nothing(): void {
			global $mock_options;
			$mock_options[ Source_Capture::OPTION_ENABLED ] = 0;
			$this->set_cookie();
			( new Attribution_Hooks() )->on_edd_order( 5 );
			$this->assertSame( array(), $GLOBALS['a0_writes'] );
		}

		/**
		 * Consent granted writes the fixed-shape array.
		 */
		public function test_writes_attribution_with_exact_keys(): void {
			$this->set_cookie();
			$hooks = new Attribution_Hooks();
			$hooks->on_edd_order( 5 );
			$hooks->on_ninja_forms( 7 );
			$hooks->on_flamingo( 9, null, false );
			$hooks->on_flamingo( 10, null, true );

			$this->assertCount( 3, $GLOBALS['a0_writes'] );
			foreach ( $GLOBALS['a0_writes'] as $write ) {
				$this->assertSame( '_specflux_mac_attribution', $write[2] );
				$this->assertSame( Source_Capture::KEYS, array_keys( $write[3] ) );
			}
			$this->assertSame( '/pricing/', $GLOBALS['a0_writes'][0][3]['landing_page'] );
		}

		/**
		 * Missing form plugins never fatal.
		 */
		public function test_missing_plugins_do_not_fatal(): void {
			$this->set_cookie();
			$hooks = new Attribution_Hooks();
			$hooks->on_give_donation( 1 );
			$hooks->on_formidable( 1, 2, array() );
			$hooks->on_fluent_forms( 1, array(), null );
			$hooks->on_gravity_forms( array( 'id' => 1 ), array() );
			$hooks->on_wpforms( array(), array(), array(), 3 );
			$this->assertSame( array(), $GLOBALS['a0_writes'] );
		}

		/**
		 * WooCommerce is not hooked.
		 */
		public function test_woocommerce_is_skipped(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/attribution/class-attribution-hooks.php' );
			$this->assertDoesNotMatchRegularExpression( "/add_action\\(\\s*'woocommerce_/", $source );
		}
	}
}
