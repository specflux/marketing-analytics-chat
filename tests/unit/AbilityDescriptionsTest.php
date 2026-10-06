<?php
/**
 * Tests for ability descriptions and schemas the model reads.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Abilities\Abilities_Registrar;
use Specflux_Marketing_Analytics\Abilities\Clarity_Abilities;
use Specflux_Marketing_Analytics\Abilities\Cross_Platform_Abilities;
use Specflux_Marketing_Analytics\Abilities\GA4_Abilities;
use Specflux_Marketing_Analytics\Abilities\GSC_Abilities;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Ability description test class.
 */
class AbilityDescriptionsTest extends TestCase {

	/**
	 * Registered abilities keyed by name.
	 *
	 * @var array
	 */
	private $abilities;

	/**
	 * Register every ability through its private register_* methods.
	 */
	protected function setUp(): void {
		parent::setUp();

		Abilities_Registrar::reset();

		foreach ( array( GA4_Abilities::class, GSC_Abilities::class, Clarity_Abilities::class, Cross_Platform_Abilities::class ) as $class_name ) {
			$reflection = new ReflectionClass( $class_name );
			$instance   = $reflection->newInstanceWithoutConstructor();

			foreach ( $reflection->getMethods( \ReflectionMethod::IS_PRIVATE ) as $method ) {
				if ( 0 === strpos( $method->getName(), 'register_' ) ) {
					$method->setAccessible( true );
					$method->invoke( $instance );
				}
			}
		}

		$this->abilities = array_merge( Abilities_Registrar::get_registered_tools(), Abilities_Registrar::get_registered_resources() );
	}

	/**
	 * Each ability says what it returns and is distinguishable from its siblings.
	 */
	public function test_every_description_explains_when_to_use_it(): void {
		$this->assertGreaterThanOrEqual( 15, count( $this->abilities ) );

		foreach ( $this->abilities as $name => $args ) {
			$this->assertGreaterThan( 120, strlen( $args['description'] ), "{$name} description is too thin" );
			$this->assertMatchesRegularExpression( '/\b(use|Use)\b/', $args['description'], "{$name} should say when to use it" );
		}
	}

	/**
	 * GA4 metric and dimension names are listed so the model does not guess.
	 */
	public function test_ga4_metrics_schema_lists_valid_names(): void {
		$props = $this->abilities['marketing-analytics/get-ga4-metrics']['input_schema']['properties'];

		foreach ( array( 'sessions', 'totalUsers', 'newUsers', 'screenPageViews', 'engagementRate', 'averageSessionDuration', 'keyEvents', 'totalRevenue' ) as $metric ) {
			$this->assertStringContainsString( $metric, $props['metrics']['description'] );
		}

		foreach ( array( 'date', 'sessionDefaultChannelGroup', 'sessionSource', 'sessionMedium', 'pagePath', 'deviceCategory', 'country', 'landingPage' ) as $dimension ) {
			$this->assertStringContainsString( $dimension, $props['dimensions']['description'] );
		}
	}

	/**
	 * GA4 and GSC date-bearing tools expose exact dates; Clarity states its limit.
	 */
	public function test_date_inputs_and_clarity_limit(): void {
		foreach ( array( 'get-ga4-metrics', 'get-ga4-events', 'get-traffic-sources', 'get-search-performance', 'get-top-queries' ) as $short ) {
			$props = $this->abilities[ 'marketing-analytics/' . $short ]['input_schema']['properties'];
			$this->assertArrayHasKey( 'start_date', $props, $short );
			$this->assertArrayHasKey( 'end_date', $props, $short );
			$this->assertArrayHasKey( 'date_range', $props, $short );
		}

		$clarity = $this->abilities['marketing-analytics/get-clarity-insights'];
		$this->assertStringContainsString( '1, 2 or 3 days', $clarity['description'] );
		$this->assertStringContainsString( 'API limit', $clarity['description'] );
	}
}
