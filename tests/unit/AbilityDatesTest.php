<?php
/**
 * Tests for the shared GA4/GSC date inputs.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use Specflux_Marketing_Analytics\Abilities\Ability_Dates;
use PHPUnit\Framework\TestCase;

/**
 * Date input test class.
 */
class AbilityDatesTest extends TestCase {

	/**
	 * date_range alone is passed through; absent means 7daysAgo.
	 */
	public function test_date_range_is_backward_compatible(): void {
		$this->assertSame( '30daysAgo', Ability_Dates::resolve( array( 'date_range' => '30daysAgo' ) ) );
		$this->assertSame( '7daysAgo', Ability_Dates::resolve( array() ) );
	}

	/**
	 * start_date and end_date win over date_range.
	 */
	public function test_start_and_end_take_precedence(): void {
		$this->assertSame(
			'2026-09-01,2026-09-30',
			Ability_Dates::resolve(
				array(
					'date_range' => '7daysAgo',
					'start_date' => '2026-09-01',
					'end_date'   => '2026-09-30',
				)
			)
		);
	}

	/**
	 * Missing bounds are filled in sensibly.
	 */
	public function test_missing_bounds_are_filled(): void {
		$this->assertSame( '2026-09-24,2026-09-30', Ability_Dates::resolve( array( 'end_date' => '2026-09-30' ) ) );

		$ga4 = Ability_Dates::resolve( array( 'start_date' => '2026-01-01' ), 'ga4' );
		$this->assertSame( '2026-01-01,' . gmdate( 'Y-m-d', strtotime( '-1 day' ) ), $ga4 );

		$gsc = Ability_Dates::resolve( array( 'start_date' => '2026-01-01' ), 'gsc' );
		$this->assertSame( '2026-01-01,' . gmdate( 'Y-m-d', strtotime( '-3 days' ) ), $gsc );
	}

	/**
	 * Malformed or reversed dates raise an error (becomes a tool error).
	 */
	public function test_invalid_dates_throw(): void {
		foreach ( array( 'last week', '2026-02-30', '2026/09/01' ) as $bad ) {
			try {
				Ability_Dates::resolve( array( 'start_date' => $bad ) );
				$this->fail( "Expected an exception for {$bad}" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'start_date', $e->getMessage() );
			}
		}

		$this->expectException( \InvalidArgumentException::class );
		Ability_Dates::resolve(
			array(
				'start_date' => '2026-09-30',
				'end_date'   => '2026-09-01',
			)
		);
	}

	/**
	 * The schema documents all three inputs.
	 */
	public function test_schema_properties_document_all_inputs(): void {
		foreach ( array( 'ga4', 'gsc' ) as $platform ) {
			$props = Ability_Dates::properties( $platform );
			$this->assertSame( array( 'start_date', 'end_date', 'date_range' ), array_keys( $props ) );
			$this->assertSame( '7daysAgo', $props['date_range']['default'] );
		}
	}
}
