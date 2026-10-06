<?php
/**
 * Tests for the chat Tool_Policy and the safety guarantees built on it.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Abilities\Abilities_Registrar;
use Specflux_Marketing_Analytics\Abilities\Bing_Abilities;
use Specflux_Marketing_Analytics\Abilities\Clarity_Abilities;
use Specflux_Marketing_Analytics\Abilities\Content_Abilities;
use Specflux_Marketing_Analytics\Abilities\Cross_Platform_Abilities;
use Specflux_Marketing_Analytics\Abilities\GA4_Abilities;
use Specflux_Marketing_Analytics\Abilities\GSC_Abilities;
use Specflux_Marketing_Analytics\Abilities\PageSpeed_Abilities;
use Specflux_Marketing_Analytics\Abilities\Prompts;
use Specflux_Marketing_Analytics\Chat\Tool_Policy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tool_Policy test class.
 */
class ChatToolPolicyTest extends TestCase {

	/**
	 * Reset options and filters.
	 */
	protected function setUp(): void {
		parent::setUp();
		global $mock_options;
		$mock_options = array();
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_allow_write_abilities'], $GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_max_tools'] );
	}

	/**
	 * Reset filters.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_allow_write_abilities'], $GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_max_tools'] );
		parent::tearDown();
	}

	/**
	 * Build a tool definition.
	 *
	 * @param string $name        Ability name.
	 * @param mixed  $readonly    Readonly annotation (null omits it).
	 * @return array
	 */
	private function tool( $name, $readonly = true ) {
		return array(
			'name'        => $name,
			'label'       => 'Label of ' . $name,
			'annotations' => null === $readonly ? array() : array( 'readonly' => $readonly ),
		);
	}

	/**
	 * Only readonly === true is a read tool.
	 */
	public function test_classification(): void {
		$this->assertSame( 'read', Tool_Policy::classify( array( 'readonly' => true ) ) );
		$this->assertSame( 'write', Tool_Policy::classify( array( 'readonly' => false ) ) );
		$this->assertSame( 'write', Tool_Policy::classify( array( 'readonly' => null ) ) );
		$this->assertSame( 'write', Tool_Policy::classify( array() ) );
		$this->assertSame( 'write', Tool_Policy::classify( null ) );
		$this->assertSame( 'write', Tool_Policy::classify( array( 'readonly' => 'true' ) ) );
	}

	/**
	 * Provider is the namespace, including the AI Client name form.
	 */
	public function test_provider_slug_and_label(): void {
		$this->assertSame( 'rank-math', Tool_Policy::provider_slug( 'rank-math/get-settings' ) );
		$this->assertSame( 'rank-math', Tool_Policy::provider_slug( 'wpab__rank-math__get-settings' ) );
		$this->assertSame( 'Rank Math', Tool_Policy::provider_label( 'rank-math' ) );
	}

	/**
	 * Every ability this plugin registers is annotated readonly true.
	 */
	public function test_every_own_ability_is_readonly(): void {
		Abilities_Registrar::reset();

		foreach ( array( GA4_Abilities::class, GSC_Abilities::class, Clarity_Abilities::class, Cross_Platform_Abilities::class, Content_Abilities::class ) as $class_name ) {
			$reflection = new ReflectionClass( $class_name );
			$instance   = $reflection->newInstanceWithoutConstructor();
			foreach ( $reflection->getMethods( \ReflectionMethod::IS_PRIVATE ) as $method ) {
				if ( 0 === strpos( $method->getName(), 'register_' ) ) {
					$method->setAccessible( true );
					$method->invoke( $instance );
				}
			}
		}

		// Credential-gated registrars: call their single-ability helper directly.
		$bing = new ReflectionClass( Bing_Abilities::class );
		$m    = $bing->getMethod( 'register_ability' );
		$m->setAccessible( true );
		$m->invoke( $bing->newInstanceWithoutConstructor(), 'get-bing-test', 'Label', 'Desc', 'execute_get_top_pages', true );

		$speed = new ReflectionClass( PageSpeed_Abilities::class );
		$m     = $speed->getMethod( 'register_tool' );
		$m->setAccessible( true );
		$m->invoke( $speed->newInstanceWithoutConstructor(), 'marketing-analytics/get-page-speed-test', 'Label', 'Desc', array(), 'execute_get_page_speed' );

		$prompts = new ReflectionClass( Prompts::class );
		$m       = $prompts->getMethod( 'register_custom_prompt' );
		$m->setAccessible( true );
		$m->invoke(
			$prompts->newInstanceWithoutConstructor(),
			'marketing-analytics/prompt-test',
			array(
				'name'         => 'test',
				'instructions' => 'Do it',
			)
		);

		$all = array_merge( Abilities_Registrar::get_registered_tools(), Abilities_Registrar::get_registered_resources(), Abilities_Registrar::get_registered_prompts() );
		$this->assertGreaterThanOrEqual( 20, count( $all ) );

		foreach ( $all as $name => $config ) {
			$this->assertStringStartsWith( 'marketing-analytics/', $name );
			$annotations = $config['meta']['annotations'] ?? null;
			$this->assertTrue( $annotations['readonly'] ?? null, "{$name} must be annotated readonly true" );
			$this->assertFalse( $annotations['destructive'] ?? null, "{$name} must be annotated destructive false" );
			$this->assertSame( 'read', Tool_Policy::classify( $annotations ) );
		}
	}

	/**
	 * Write abilities are hidden unless the filter allows them.
	 */
	public function test_write_abilities_need_the_filter(): void {
		$tools = array(
			$this->tool( 'marketing-analytics/get-ga4-metrics' ),
			$this->tool( 'rank-math/update-settings', false ),
			$this->tool( 'rank-math/get-settings', true ),
			$this->tool( 'seo/unannotated', null ),
		);

		$names = array_column( Tool_Policy::select( $tools ), 'name' );
		$this->assertSame( array( 'marketing-analytics/get-ga4-metrics', 'rank-math/get-settings' ), $names );

		$GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_allow_write_abilities'] = '__return_true';
		if ( ! function_exists( '__return_true' ) ) {
			$GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_allow_write_abilities'] = static function () {
				return true;
			};
		}

		$this->assertCount( 4, Tool_Policy::select( $tools ) );
	}

	/**
	 * A disabled provider's tools are not offered; own and core cannot be disabled.
	 */
	public function test_provider_toggle_is_respected(): void {
		global $mock_options;
		$mock_options['specflux_mac_settings'] = array(
			'chat_providers' => array(
				'rank-math'           => false,
				'marketing-analytics' => false,
				'core'                => false,
			),
		);

		$tools = array(
			$this->tool( 'rank-math/get-settings' ),
			$this->tool( 'yoast/get-score' ),
			$this->tool( 'core/get-site-info' ),
			$this->tool( 'marketing-analytics/get-ga4-metrics' ),
		);

		$this->assertSame(
			array( 'marketing-analytics/get-ga4-metrics', 'core/get-site-info', 'yoast/get-score' ),
			array_column( Tool_Policy::select( $tools ), 'name' )
		);
	}

	/**
	 * The cap keeps our own tools first, then core, then others alphabetically.
	 */
	public function test_cap_and_order(): void {
		$GLOBALS['specflux_mac_test_filters']['specflux_mac_chat_max_tools'] = static function () {
			return 4;
		};

		$tools = array(
			$this->tool( 'zeta/a' ),
			$this->tool( 'alpha/a' ),
			$this->tool( 'core/a' ),
			$this->tool( 'marketing-analytics/a' ),
			$this->tool( 'marketing-analytics/b' ),
		);

		$this->assertSame(
			array( 'marketing-analytics/a', 'marketing-analytics/b', 'core/a', 'alpha/a' ),
			array_column( Tool_Policy::select( $tools ), 'name' )
		);
	}

	/**
	 * Provider counts split read from write.
	 */
	public function test_provider_counts(): void {
		$providers = Tool_Policy::providers(
			array(
				$this->tool( 'rank-math/a' ),
				$this->tool( 'rank-math/b', false ),
				$this->tool( 'rank-math/c', null ),
				$this->tool( 'marketing-analytics/a' ),
			)
		);

		$this->assertSame( array( 'marketing-analytics', 'rank-math' ), array_keys( $providers ) );
		$this->assertSame( 1, $providers['rank-math']['read'] );
		$this->assertSame( 2, $providers['rank-math']['write'] );
	}

	/**
	 * The call summary is bounded.
	 */
	public function test_summarize_call_is_capped(): void {
		$summary = Tool_Policy::summarize_call( $this->tool( 'x/y' ), array( 'text' => str_repeat( 'a', 500 ) ) );

		$this->assertLessThanOrEqual( 140, mb_strlen( $summary ) );
	}

	/**
	 * Saved provider choices only cover detected providers and keep older ones.
	 */
	public function test_sanitize_provider_choices(): void {
		$choices = Tool_Policy::sanitize_provider_choices(
			array( 'yoast', 'Evil Slug', 'core' ),
			array( 'yoast', 'rank-math', 'core', 'marketing-analytics' ),
			array( 'old-plugin' => false )
		);

		$this->assertSame(
			array(
				'old-plugin' => false,
				'yoast'      => true,
				'rank-math'  => false,
			),
			$choices
		);
	}
}
