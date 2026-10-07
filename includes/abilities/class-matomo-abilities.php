<?php
/**
 * Matomo Abilities
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

use Specflux_Marketing_Analytics\API_Clients\Matomo_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers Matomo MCP abilities
 */
class Matomo_Abilities {

	/**
	 * Register Matomo abilities
	 *
	 * Only registered once Matomo is connected, like the other platform abilities.
	 */
	public function register() {
		$credential_manager = new Credential_Manager();
		if ( ! $credential_manager->has_credentials( 'matomo' ) ) {
			return;
		}

		$this->register_ability(
			'get-matomo-traffic',
			__( 'Get Matomo Traffic', 'specflux-marketing-analytics-chat' ),
			__( 'Returns Matomo visits, unique visitors (when Matomo provides them for the range), actions, bounce rate, average time on site and converted visits for a date range, with the change against the previous period of equal length. Use it instead of the Google Analytics traffic tools when the site is measured with Matomo.', 'specflux-marketing-analytics-chat' ),
			'execute_get_traffic',
			false
		);
		$this->register_ability(
			'get-matomo-top-pages',
			__( 'Get Matomo Top Pages', 'specflux-marketing-analytics-chat' ),
			__( 'Returns the most viewed pages in Matomo (full URLs) with pageviews, visits, entrances, average time on page, bounce rate and exit rate. Use it instead of the Google Analytics top pages tools when the site is measured with Matomo.', 'specflux-marketing-analytics-chat' ),
			'execute_get_top_pages',
			true
		);
		$this->register_ability(
			'get-matomo-sources',
			__( 'Get Matomo Traffic Sources', 'specflux-marketing-analytics-chat' ),
			__( 'Returns where Matomo visits come from: referrer types (search, social, direct, websites, campaigns), referring websites, search engines and campaigns, each with visits, actions, bounce rate and converted visits. Use it instead of the Google Analytics traffic source tools when the site is measured with Matomo.', 'specflux-marketing-analytics-chat' ),
			'execute_get_sources',
			true
		);
		$this->register_ability(
			'get-matomo-goals',
			__( 'Get Matomo Goals', 'specflux-marketing-analytics-chat' ),
			__( 'Returns Matomo goal conversions: overall conversions, converted visits, revenue and conversion rate, plus conversions, revenue and conversion rate for each goal. Use it instead of the Google Analytics conversion tools when the site is measured with Matomo.', 'specflux-marketing-analytics-chat' ),
			'execute_get_goals',
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
	 * Execute get-matomo-traffic
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_traffic( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$period = Bing_Abilities::resolve_period( $args );
				$client = new Matomo_Client();
				$data   = $client->get_traffic( $period['start_date'], $period['end_date'] );

				return array(
					'period'   => $period,
					'totals'   => $data['current'],
					'previous' => $data['previous'],
					'change'   => $data['change'],
				);
			}
		);
	}

	/**
	 * Execute get-matomo-top-pages
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_top_pages( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$period = Bing_Abilities::resolve_period( $args );
				$client = new Matomo_Client();
				$rows   = $client->get_top_pages( $period['start_date'], $period['end_date'], self::resolve_limit( $args ) );

				return array(
					'period'    => $period,
					'rows'      => $rows,
					'row_count' => count( $rows ),
				);
			}
		);
	}

	/**
	 * Execute get-matomo-sources
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_sources( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$period = Bing_Abilities::resolve_period( $args );
				$client = new Matomo_Client();
				$data   = $client->get_sources( $period['start_date'], $period['end_date'], self::resolve_limit( $args ) );

				return array_merge( array( 'period' => $period ), $data );
			}
		);
	}

	/**
	 * Execute get-matomo-goals
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_goals( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$period = Bing_Abilities::resolve_period( $args );
				$client = new Matomo_Client();
				$data   = $client->get_goals( $period['start_date'], $period['end_date'] );

				return array_merge( array( 'period' => $period ), $data );
			}
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
}
