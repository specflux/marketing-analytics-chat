<?php
/**
 * Ability callback signature tests.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Abilities\Clarity_Abilities;
use Specflux_Marketing_Analytics\Abilities\GA4_Abilities;
use Specflux_Marketing_Analytics\Abilities\GSC_Abilities;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Abilities registered without an input schema are executed by core with no arguments.
 */
class AbilityCallbackSignatureTest extends TestCase {

	/**
	 * Overview callbacks must accept being called with no arguments.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function overview_callbacks(): array {
		return array(
			'clarity-dashboard' => array( Clarity_Abilities::class, 'execute_clarity_dashboard' ),
			'ga4-overview'      => array( GA4_Abilities::class, 'execute_ga4_overview' ),
			'gsc-overview'      => array( GSC_Abilities::class, 'execute_gsc_overview' ),
		);
	}

	/**
	 * Test each schema-less overview callback has no required parameter.
	 *
	 * @dataProvider overview_callbacks
	 *
	 * @param string $class_name Abilities class.
	 * @param string $method     Callback method.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'overview_callbacks' )]
	public function test_overview_callback_needs_no_arguments( string $class_name, string $method ): void {
		$this->assertSame( 0, ( new ReflectionMethod( $class_name, $method ) )->getNumberOfRequiredParameters() );
	}
}
