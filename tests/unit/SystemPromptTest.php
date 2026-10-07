<?php
/**
 * Tests for the chat System_Prompt builder.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Chat\System_Prompt;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Credential manager reporting a fixed set of connected platforms.
 */
class Fake_Connected_Credentials extends Credential_Manager {

	/**
	 * Connected platform slugs.
	 *
	 * @var array
	 */
	private $connected;

	/**
	 * Constructor.
	 *
	 * @param array $connected Connected platform slugs.
	 */
	public function __construct( $connected ) {
		$this->connected = $connected;
	}

	/**
	 * Report connection state.
	 *
	 * @param string $platform Platform slug.
	 * @return bool
	 */
	public function has_credentials( $platform ) {
		return in_array( $platform, $this->connected, true );
	}
}

/**
 * System prompt test class.
 */
class SystemPromptTest extends TestCase {

	/**
	 * Reset filters.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_system_prompt'], $GLOBALS['specflux_mac_test_timezone'] );
		parent::tearDown();
	}

	/**
	 * Site facts and connection state are included.
	 */
	public function test_includes_site_date_and_connection_state(): void {
		$GLOBALS['specflux_mac_test_timezone'] = 'Australia/Sydney';

		$prompt = ( new System_Prompt( new Fake_Connected_Credentials( array( 'ga4' ) ) ) )->build();

		$this->assertStringContainsString( 'Test Blog', $prompt );
		$this->assertStringContainsString( 'https://example.com', $prompt );
		$this->assertStringContainsString( 'Australia/Sydney', $prompt );
		$this->assertMatchesRegularExpression( '/Today: \d{4}-\d{2}-\d{2}/', $prompt );
		$this->assertStringContainsString( 'Google Analytics 4: connected', $prompt );
		$this->assertStringContainsString( 'Google Search Console: NOT connected', $prompt );
		$this->assertStringContainsString( 'Microsoft Clarity: NOT connected', $prompt );
		$this->assertStringContainsString( 'specflux-mac-connections', $prompt );
		$this->assertStringContainsString( 'Never invent numbers', $prompt );
		$this->assertStringContainsString( 'next actions', $prompt );
	}

	/**
	 * Add-ons can append guidance through the filter and receive the context.
	 */
	public function test_prompt_is_filterable_with_context(): void {
		$seen                                                                  = null;
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_system_prompt'] = static function ( $prompt, $context ) use ( &$seen ) {
			$seen = $context;
			return $prompt . "\nPRO GUIDANCE";
		};

		$prompt = ( new System_Prompt( new Fake_Connected_Credentials( array() ) ) )->build();

		$this->assertStringEndsWith( 'PRO GUIDANCE', $prompt );
		$this->assertSame( 'Test Blog', $seen['site_name'] );
		$this->assertFalse( $seen['platforms']['ga4'] );
	}

	/**
	 * Registry platforms appear with their hint; credential-free ones count as connected.
	 */
	public function test_lists_registered_platforms_with_hints(): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] = static function ( $platforms ) {
			$platforms['wpcontent'] = array(
				'label'       => 'WordPress Content',
				'auth'        => 'none',
				'prompt_hint' => 'Posts and SEO metadata.',
			);
			$platforms['pagespeed'] = array(
				'label'           => 'PageSpeed Insights',
				'fields'          => array( 'api_key' ),
				'optional_fields' => array( 'api_key' ),
			);
			$platforms['bing']      = array(
				'label'  => 'Bing Webmaster Tools',
				'fields' => array( 'api_key', 'site_url' ),
			);
			return $platforms;
		};
		\Specflux_Marketing_Analytics\Credentials\Platform_Registry::reset();

		$prompt = ( new System_Prompt( new Fake_Connected_Credentials( array() ) ) )->build();

		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_platforms'] );
		\Specflux_Marketing_Analytics\Credentials\Platform_Registry::reset();

		$this->assertStringContainsString( 'WordPress Content: connected. Posts and SEO metadata.', $prompt );
		$this->assertStringContainsString( 'PageSpeed Insights: connected', $prompt );
		$this->assertStringContainsString( 'Bing Webmaster Tools: NOT connected', $prompt );
		$this->assertStringContainsString( 'Google Analytics 4: NOT connected. Traffic', $prompt );
	}

	/**
	 * A broken filter cannot blank the prompt.
	 */
	public function test_non_string_filter_result_falls_back(): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_system_prompt'] = static function () {
			return null;
		};

		$prompt = ( new System_Prompt( new Fake_Connected_Credentials( array() ) ) )->build();

		$this->assertStringContainsString( 'marketing analytics assistant', $prompt );
	}
}
