<?php
/**
 * GiveWP Abilities
 *
 * Answers "where did my donations come from?" from the site's own GiveWP
 * donations. Only aggregates are returned, never donor or donation details.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

use Specflux_Marketing_Analytics\Donations\Givewp_Donations_Reader;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the GiveWP donation abilities.
 */
class Givewp_Abilities {

	/**
	 * Default period length in days.
	 */
	const DEFAULT_DAYS = 28;

	/**
	 * Donations reader.
	 *
	 * @var Givewp_Donations_Reader
	 */
	private $reader;

	/**
	 * Constructor.
	 *
	 * @param Givewp_Donations_Reader|null $reader Optional reader.
	 */
	public function __construct( $reader = null ) {
		$this->reader = $reader ? $reader : new Givewp_Donations_Reader();
	}

	/**
	 * Register the abilities.
	 */
	public function register() {
		// Nothing to read without GiveWP, so don't advertise the tool.
		if ( ! $this->reader->available() ) {
			return;
		}

		wp_register_ability(
			'marketing-analytics/get-donations-by-source',
			array(
				'label'               => __( 'Get Donations by Source', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Where did this site\'s GiveWP donations come from? Completed live-mode donations, donor count, amount, and recurring share, per currency (currencies are never added together), grouped by channel (Organic Search, Paid Search, Paid Social, Organic Social, Email, Referral, Direct, Other, Not tracked), source, UTM campaign, donation form or GiveWP campaign, with the change versus the previous period of the same length. Donations made before source tracking was on show as Not tracked. Only totals are returned, never donor details. Default period is the last 28 days.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'start_date' => array(
							'type'        => 'string',
							'description' => 'Period start, YYYY-MM-DD (inclusive). Default: 27 days before end_date.',
						),
						'end_date'   => array(
							'type'        => 'string',
							'description' => 'Period end, YYYY-MM-DD (inclusive). Default: today.',
						),
						'date_range' => array(
							'type'        => 'string',
							'description' => 'Relative alternative such as "7daysAgo" or "30daysAgo" (ending today), ignored when start_date or end_date is given. Default 28 days.',
						),
						'group_by'   => array(
							'type'        => 'string',
							'description' => 'How to group the donations.',
							'enum'        => Givewp_Donations_Reader::GROUPS,
							'default'     => 'channel',
						),
						'limit'      => array(
							'type'        => 'integer',
							'description' => 'Most rows to return per currency (1 to 100). Totals always cover every donation.',
							'default'     => 20,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'start_date' => array( 'type' => 'string' ),
						'end_date'   => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( $this, 'execute_get_donations_by_source' ),
				'permission_callback' => array( Abilities_Registrar::class, 'can_access' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute get-donations-by-source.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_donations_by_source( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args = (array) $args;
				list( $start, $end ) = $this->resolve_period( $args );

				return $this->reader->get_donations_by_source(
					$start,
					$end,
					isset( $args['group_by'] ) ? (string) $args['group_by'] : 'channel',
					isset( $args['limit'] ) ? (int) $args['limit'] : 20,
					true
				);
			}
		);
	}

	/**
	 * Resolve the period from tool arguments.
	 *
	 * @param array $args Tool arguments.
	 * @return array{0:string,1:string}
	 * @throws \Exception On invalid dates.
	 */
	private function resolve_period( array $args ) {
		foreach ( array( 'start_date', 'end_date' ) as $key ) {
			if ( ! empty( $args[ $key ] ) && ! self::is_date( $args[ $key ] ) ) {
				throw new \Exception( esc_html( $key . ' must be a valid date in Y-m-d format.' ) );
			}
		}

		$days = self::DEFAULT_DAYS;
		if ( empty( $args['start_date'] ) && empty( $args['end_date'] ) && ! empty( $args['date_range'] ) && preg_match( '/^(\d{1,4})daysago$/i', (string) $args['date_range'], $m ) ) {
			$days = max( 1, (int) $m[1] );
		}

		$end   = ! empty( $args['end_date'] ) ? $args['end_date'] : gmdate( 'Y-m-d' );
		$start = ! empty( $args['start_date'] ) ? $args['start_date'] : gmdate( 'Y-m-d', strtotime( $end . ' -' . ( $days - 1 ) . ' days' ) );

		if ( $start > $end ) {
			throw new \Exception( 'start_date must not be after end_date.' );
		}

		return array( $start, $end );
	}

	/**
	 * Strictly validate a Y-m-d date.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_date( $value ) {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value );

		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}
}
