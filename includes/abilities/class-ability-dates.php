<?php
/**
 * Ability Date Inputs
 *
 * Shared start_date / end_date / date_range handling for the GA4 and Search
 * Console abilities.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Builds the date input schema and resolves date arguments
 */
class Ability_Dates {

	/**
	 * Input-schema properties for the three date inputs.
	 *
	 * @param string $platform 'ga4' or 'gsc'.
	 * @return array Properties keyed start_date, end_date, date_range.
	 */
	public static function properties( $platform ) {
		$relative = 'gsc' === $platform
			? 'Relative form for quick checks: "7daysAgo", "28daysAgo", "30daysAgo", "yesterday", a single "YYYY-MM-DD" date, or "YYYY-MM-DD,YYYY-MM-DD". Search Console data lags about 3 days, so "7daysAgo" ends 3 days ago.'
			: 'Relative form for quick checks: "7daysAgo", "28daysAgo", "30daysAgo", "yesterday", "today", a single "YYYY-MM-DD" date, or "YYYY-MM-DD,YYYY-MM-DD".';

		return array(
			'start_date' => array(
				'type'        => 'string',
				'description' => 'Exact period start, YYYY-MM-DD (inclusive). Preferred over date_range for any specific period such as "last month". Takes precedence over date_range. If end_date is omitted the period ends ' . ( 'gsc' === $platform ? '3 days ago (latest complete Search Console data)' : 'yesterday' ) . '.',
			),
			'end_date'   => array(
				'type'        => 'string',
				'description' => 'Exact period end, YYYY-MM-DD (inclusive). Takes precedence over date_range. If start_date is omitted the period covers the 7 days up to and including end_date.',
			),
			'date_range' => array(
				'type'        => 'string',
				'description' => 'Legacy/relative date range, ignored when start_date or end_date is given. ' . $relative . ' Default "7daysAgo".',
				'default'     => '7daysAgo',
			),
		);
	}

	/**
	 * Resolve ability arguments into the date_range string the API clients take.
	 *
	 * The start_date and end_date inputs win over date_range; the result is "start,end" when
	 * either is supplied, otherwise the date_range argument (default 7daysAgo).
	 *
	 * @param array  $args     Ability arguments.
	 * @param string $platform 'ga4' or 'gsc' (decides the default end date).
	 * @return string Date range for the API client.
	 * @throws \InvalidArgumentException If a date is malformed or start is after end.
	 */
	public static function resolve( $args, $platform = 'ga4' ) {
		$start = isset( $args['start_date'] ) ? trim( (string) $args['start_date'] ) : '';
		$end   = isset( $args['end_date'] ) ? trim( (string) $args['end_date'] ) : '';

		if ( '' === $start && '' === $end ) {
			return isset( $args['date_range'] ) && '' !== $args['date_range'] ? (string) $args['date_range'] : '7daysAgo';
		}

		foreach ( array(
			'start_date' => $start,
			'end_date'   => $end,
		) as $name => $value ) {
			if ( '' !== $value && ! self::is_valid_date( $value ) ) {
				throw new \InvalidArgumentException( esc_html( $name . ' must be a real date in YYYY-MM-DD format.' ) );
			}
		}

		if ( '' === $end ) {
			$end = 'gsc' === $platform ? gmdate( 'Y-m-d', strtotime( '-3 days' ) ) : gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		}

		if ( '' === $start ) {
			$start = gmdate( 'Y-m-d', strtotime( $end . ' -6 days' ) );
		}

		if ( $start > $end ) {
			throw new \InvalidArgumentException( esc_html( 'start_date must not be after end_date.' ) );
		}

		return $start . ',' . $end;
	}

	/**
	 * Strictly validate a Y-m-d date.
	 *
	 * @param string $value Candidate date.
	 * @return bool True for a real calendar date.
	 */
	private static function is_valid_date( $value ) {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}
}
