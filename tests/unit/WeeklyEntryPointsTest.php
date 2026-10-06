<?php
/**
 * Tests for the AI chat entry points (prompt builder, email CTA, chips, preview).
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;
use Specflux_Marketing_Analytics\Reports\Weekly_Prompt_Builder;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary_Email;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary_Preview;

/**
 * Collector double for the preview tests.
 */
class Entry_Points_Fake_Summary extends Weekly_Summary {
	/**
	 * Connected platforms.
	 *
	 * @var string[]
	 */
	private $fake_connected;

	/**
	 * Constructor.
	 *
	 * @param string[] $connected Connected platforms.
	 */
	public function __construct( array $connected ) {
		parent::__construct();
		$this->fake_connected = $connected;
	}

	/**
	 * Connected platforms.
	 *
	 * @return string[]
	 */
	public function get_connected_platforms() {
		return $this->fake_connected;
	}
}

/**
 * Entry point tests.
 */
class WeeklyEntryPointsTest extends TestCase {

	/**
	 * Reset toggles.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_nonce_valid, $mock_user_can;
		$mock_nonce_valid = true;
		$mock_user_can    = true;
		$_GET             = array();
	}

	/**
	 * Weekly context with GA4 and GSC.
	 *
	 * @param array $overrides Platform overrides.
	 * @return array
	 */
	private function context( array $overrides = array() ) {
		return array(
			'ranges'    => array( 'ga4' => array( 'current' => array( '2026-09-28', '2026-10-04' ) ) ),
			'platforms' => array_merge(
				array(
					'ga4' => array(
						'status' => 'ok',
						'data'   => array( 'metrics' => array( array( 'key' => 'sessions', 'current' => 12480.0, 'change' => 8.4 ) ) ),
					),
					'gsc' => array(
						'status' => 'ok',
						'data'   => array( 'metrics' => array( array( 'key' => 'clicks', 'current' => 2181.0, 'change' => 12.1 ) ) ),
					),
				),
				$overrides
			),
		);
	}

	/**
	 * Full data mentions both metrics.
	 */
	public function test_prompt_with_full_data(): void {
		$prompt = Weekly_Prompt_Builder::from_context( $this->context() );

		$this->assertStringContainsString( 'GA4 sessions were 12,480 (up 8.4%)', $prompt );
		$this->assertStringContainsString( 'Search Console clicks were 2,181 (up 12.1%)', $prompt );
		$this->assertStringContainsString( 'What drove the change', $prompt );
	}

	/**
	 * Partial data only mentions what exists.
	 */
	public function test_prompt_with_partial_data(): void {
		$prompt = Weekly_Prompt_Builder::from_context( $this->context( array( 'gsc' => array( 'status' => 'error', 'data' => array() ) ) ) );

		$this->assertStringContainsString( 'GA4 sessions', $prompt );
		$this->assertStringNotContainsString( 'Search Console', $prompt );

		$prompt = Weekly_Prompt_Builder::from_headline(
			array(
				array( 'platform' => 'ga4', 'value' => 100.0, 'change' => null, 'has_change' => true ),
			)
		);
		$this->assertStringContainsString( 'GA4 sessions were 100.', $prompt );
		$this->assertStringNotContainsString( '(up', $prompt );
		$this->assertStringNotContainsString( '(down', $prompt );
	}

	/**
	 * No data gives the generic question.
	 */
	public function test_prompt_without_data_is_generic(): void {
		$expected = "Summarize last week's analytics and tell me what to fix first.";

		$this->assertSame( $expected, Weekly_Prompt_Builder::from_headline( null ) );
		$this->assertSame( $expected, Weekly_Prompt_Builder::from_headline( array() ) );
		$this->assertSame( $expected, Weekly_Prompt_Builder::from_context( array( 'platforms' => array() ) ) );
	}

	/**
	 * Length cap and no raw HTML.
	 */
	public function test_prompt_is_capped_and_plain_text(): void {
		$url = Weekly_Prompt_Builder::assistant_url( '<b>' . str_repeat( 'x', 900 ) . '</b><script>alert(1)</script>' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertLessThanOrEqual( 500, strlen( $query['prompt'] ) );
		$this->assertStringNotContainsString( '<', $query['prompt'] );
		$this->assertStringNotContainsString( '<', Weekly_Prompt_Builder::from_context( $this->context() ) );
	}

	/**
	 * The email CTA carries the encoded prompt.
	 */
	public function test_email_cta_url_contains_encoded_prompt(): void {
		$html = ( new Weekly_Summary_Email() )->render( $this->context(), array() );

		$this->assertStringContainsString( 'prompt=Last+week+GA4+sessions', $html );
		$this->assertStringNotContainsString( '%2520', $html );
	}

	/**
	 * Chips use cached numbers.
	 */
	public function test_chips_from_cache(): void {
		$chips = Weekly_Prompt_Builder::suggestions(
			array(
				array( 'platform' => 'gsc', 'value' => 80.0, 'change' => 12.1, 'has_change' => true ),
				array( 'platform' => 'ga4', 'value' => 90.0, 'change' => -3.0, 'has_change' => true ),
			)
		);

		$this->assertCount( 3, $chips );
		$this->assertSame( 'Why are Search Console clicks up 12.1% this week?', $chips[0] );
		$this->assertSame( 'Why are GA4 sessions down 3.0% this week?', $chips[1] );
	}

	/**
	 * Chips fall back to three generic suggestions.
	 */
	public function test_chips_generic_fallback(): void {
		foreach ( array( null, array() ) as $cache ) {
			$chips = Weekly_Prompt_Builder::suggestions( $cache );
			$this->assertCount( 3, $chips );
			$this->assertSame( 'What should I fix first on my site?', $chips[1] );
		}
	}

	/**
	 * Preview rejects a bad nonce.
	 */
	public function test_preview_rejects_bad_nonce(): void {
		global $mock_nonce_valid;
		$mock_nonce_valid = false;
		$_GET['_wpnonce'] = 'bad';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/expired/' );
		( new Weekly_Summary_Preview( new Entry_Points_Fake_Summary( array( 'ga4' ) ) ) )->handle();
	}

	/**
	 * Preview rejects a user without manage_options.
	 */
	public function test_preview_rejects_missing_capability(): void {
		global $mock_user_can;
		$mock_user_can    = false;
		$_GET['_wpnonce'] = 'ok';

		$this->expectException( \Exception::class );
		$this->expectExceptionMessageMatches( '/permission/' );
		( new Weekly_Summary_Preview( new Entry_Points_Fake_Summary( array( 'ga4' ) ) ) )->handle();
	}

	/**
	 * Nothing connected gives an escaped message page.
	 */
	public function test_preview_message_when_nothing_connected(): void {
		$html = ( new Weekly_Summary_Preview( new Entry_Points_Fake_Summary( array() ) ) )->get_html();

		$this->assertStringContainsString( 'nothing to preview', $html );
		$this->assertStringContainsString( 'noindex', $html );
	}
}
