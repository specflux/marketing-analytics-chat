<?php
/**
 * Bing Webmaster Tools Abilities
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

use Specflux_Marketing_Analytics\API_Clients\Bing_Webmaster_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers Bing Webmaster Tools MCP abilities
 */
class Bing_Abilities {

	/**
	 * Default window length in days
	 */
	const DEFAULT_DAYS = 28;

	/**
	 * Register Bing abilities
	 *
	 * Only registered once Bing is connected, like the other platform abilities.
	 */
	public function register() {
		$credential_manager = new Credential_Manager();
		if ( ! $credential_manager->has_credentials( 'bing' ) ) {
			return;
		}

		$this->register_ability(
			'get-bing-search-performance',
			__( 'Get Bing Search Performance', 'specflux-marketing-analytics-chat' ),
			__( 'Returns daily clicks and impressions from Bing Webmaster Tools for a date range, plus totals. Covers Bing and Microsoft Copilot search traffic. Use it alongside the Google Search Console tools to see total organic search, or to answer how Bing traffic is trending.', 'specflux-marketing-analytics-chat' ),
			'execute_get_search_performance',
			false
		);
		$this->register_ability(
			'get-bing-top-queries',
			__( 'Get Bing Top Queries', 'specflux-marketing-analytics-chat' ),
			__( 'Returns the top search queries from Bing Webmaster Tools with clicks, impressions, CTR and average position, sorted by clicks. Use it to find what people search on Bing and Copilot to reach the site; compare with Google Search Console queries.', 'specflux-marketing-analytics-chat' ),
			'execute_get_top_queries',
			true
		);
		$this->register_ability(
			'get-bing-top-pages',
			__( 'Get Bing Top Pages', 'specflux-marketing-analytics-chat' ),
			__( 'Returns the top pages from Bing Webmaster Tools with clicks, impressions, CTR and average position, sorted by clicks. Use it to see which URLs get Bing and Copilot search traffic.', 'specflux-marketing-analytics-chat' ),
			'execute_get_top_pages',
			true
		);
		$this->register_ability(
			'get-bing-crawl-stats',
			__( 'Get Bing Crawl Stats', 'specflux-marketing-analytics-chat' ),
			__( 'Returns daily Bing crawl statistics: pages crawled, crawl errors, pages in the index, inbound links, robots.txt blocks, malware flags and HTTP status code counts. Use it to diagnose indexing and crawl health on Bing. History is limited to about six months.', 'specflux-marketing-analytics-chat' ),
			'execute_get_crawl_stats',
			false
		);
	}

	/**
	 * Register one ability
	 *
	 * @param string $slug     Ability slug (after marketing-analytics/).
	 * @param string $label    Label.
	 * @param string $desc     Description.
	 * @param string $callback Execute method name.
	 * @param bool   $limited  Whether the ability accepts a limit.
	 */
	private function register_ability( $slug, $label, $desc, $callback, $limited ) {
		$properties = array(
			'start_date' => array(
				'type'        => 'string',
				'description' => 'Start date, Y-m-d. Defaults to 28 days before the end date.',
			),
			'end_date'   => array(
				'type'        => 'string',
				'description' => 'End date, Y-m-d. Defaults to yesterday.',
			),
		);

		if ( $limited ) {
			$properties['limit'] = array(
				'type'        => 'integer',
				'description' => 'Maximum rows to return',
				'default'     => 25,
				'minimum'     => 1,
				'maximum'     => 500,
			);
		}

		wp_register_ability(
			'marketing-analytics/' . $slug,
			array(
				'label'               => $label,
				'description'         => $desc,
				'category'            => 'marketing-analytics',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'period' => array(
							'type'        => 'object',
							'description' => 'Resolved start_date and end_date',
						),
						'rows'   => array(
							'type'        => 'array',
							'description' => 'Result rows',
						),
					),
				),
				'execute_callback'    => array( $this, $callback ),
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
	 * Execute get-bing-search-performance
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_search_performance( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$period = self::resolve_period( $args );
				$client = new Bing_Webmaster_Client();
				$rows   = $client->get_rank_and_traffic_stats( $period['start_date'], $period['end_date'] );

				$clicks      = array_sum( array_column( $rows, 'clicks' ) );
				$impressions = array_sum( array_column( $rows, 'impressions' ) );

				return array(
					'period'    => $period,
					'totals'    => array(
						'clicks'      => $clicks,
						'impressions' => $impressions,
						'ctr'         => $impressions > 0 ? round( $clicks / $impressions, 4 ) : 0,
					),
					'rows'      => $rows,
					'row_count' => count( $rows ),
				);
			}
		);
	}

	/**
	 * Execute get-bing-top-queries
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_top_queries( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$period = self::resolve_period( $args );
				$client = new Bing_Webmaster_Client();
				$rows   = $client->get_query_stats( $period['start_date'], $period['end_date'], self::resolve_limit( $args ) );

				return array(
					'period'    => $period,
					'rows'      => $rows,
					'row_count' => count( $rows ),
				);
			}
		);
	}

	/**
	 * Execute get-bing-top-pages
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_top_pages( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$period = self::resolve_period( $args );
				$client = new Bing_Webmaster_Client();
				$rows   = $client->get_page_stats( $period['start_date'], $period['end_date'], self::resolve_limit( $args ) );

				return array(
					'period'    => $period,
					'rows'      => $rows,
					'row_count' => count( $rows ),
				);
			}
		);
	}

	/**
	 * Execute get-bing-crawl-stats
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_crawl_stats( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$period = self::resolve_period( $args );
				$client = new Bing_Webmaster_Client();
				$rows   = $client->get_crawl_stats( $period['start_date'], $period['end_date'] );

				return array(
					'period'    => $period,
					'rows'      => $rows,
					'row_count' => count( $rows ),
				);
			}
		);
	}

	/**
	 * Resolve the requested window
	 *
	 * Defaults to the last 28 days ending yesterday in the site timezone.
	 *
	 * @param array $args Tool arguments.
	 * @return array Array with 'start_date' and 'end_date' (Y-m-d).
	 * @throws \Exception On malformed or inverted dates.
	 */
	public static function resolve_period( $args ) {
		$args = is_array( $args ) ? $args : array();
		$end  = isset( $args['end_date'] ) && '' !== $args['end_date'] ? (string) $args['end_date'] : null;
		$from = isset( $args['start_date'] ) && '' !== $args['start_date'] ? (string) $args['start_date'] : null;

		foreach ( array(
			'start_date' => $from,
			'end_date' => $end,
		) as $name => $value ) {
			if ( null !== $value && ! self::is_valid_date( $value ) ) {
				throw new \Exception( sprintf( '%s must be a valid date in Y-m-d format.', esc_html( $name ) ) );
			}
		}

		$timezone = wp_timezone();
		if ( null === $end ) {
			$end = ( new \DateTimeImmutable( 'yesterday', $timezone ) )->format( 'Y-m-d' );
		}
		if ( null === $from ) {
			$from = ( new \DateTimeImmutable( $end, $timezone ) )->modify( '-' . ( self::DEFAULT_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );
		}
		if ( $from > $end ) {
			throw new \Exception( 'start_date must not be after end_date.' );
		}

		return array(
			'start_date' => $from,
			'end_date'   => $end,
		);
	}

	/**
	 * Resolve the row limit
	 *
	 * @param array $args Tool arguments.
	 * @return int Limit between 1 and 500.
	 */
	private static function resolve_limit( $args ) {
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 25;
		return max( 1, min( 500, $limit ) );
	}

	/**
	 * Whether a string is a real Y-m-d calendar date
	 *
	 * @param string $value Date string.
	 * @return bool
	 */
	private static function is_valid_date( $value ) {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}
}
