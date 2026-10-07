<?php
/**
 * Google Analytics 4 Abilities
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

use Specflux_Marketing_Analytics\API_Clients\GA4_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Registers Google Analytics 4 MCP abilities
 */
class GA4_Abilities {

	/**
	 * Register GA4 abilities
	 */
	public function register() {
		// Only register abilities if credentials are configured.
		$credential_manager = new Credential_Manager();
		if ( ! $credential_manager->has_credentials( 'ga4' ) ) {
			return;
		}

		$this->register_get_ga4_metrics();
		$this->register_get_ga4_events();
		$this->register_get_ga4_realtime();
		$this->register_get_traffic_sources();
		$this->register_ga4_overview_resource();
	}

	/**
	 * Register get-ga4-metrics tool
	 */
	private function register_get_ga4_metrics() {
		wp_register_ability(
			'marketing-analytics/get-ga4-metrics',
			array(
				'label'               => __( 'Get GA4 Metrics', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'The general-purpose GA4 report: returns rows of any metrics you name, optionally broken down by dimensions (date, channel, page, device, country...), plus totals and the resolved period. Use it for trends over time, top pages, device or country splits and custom metric combinations. For a quick headline snapshot use ga4-overview; for source/medium/campaign use get-traffic-sources; for event counts use get-ga4-events; for a cross-platform write-up use generate-summary-report.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'metrics'    => array(
							'type'        => 'array',
							'description' => 'GA4 metric API names. Common valid names: sessions, totalUsers, activeUsers, newUsers, screenPageViews, engagementRate, engagedSessions, bounceRate, averageSessionDuration, sessionsPerUser, eventCount, conversions, keyEvents, totalRevenue, transactions. Names are case-sensitive; do not invent others.',
							'items'       => array( 'type' => 'string' ),
						),
						...Ability_Dates::properties( 'ga4' ),
						'dimensions' => array(
							'type'        => 'array',
							'description' => 'GA4 dimension API names to break the metrics down by. Common valid names: date, sessionDefaultChannelGroup, sessionSource, sessionMedium, sessionCampaignName, pagePath, pageTitle, landingPage, deviceCategory, country, city, eventName. Use date for a trend over time. Omit for a single totals row.',
							'items'       => array( 'type' => 'string' ),
						),
						'limit'      => array(
							'type'        => 'integer',
							'description' => 'Maximum number of rows to return',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 10000,
						),
					),
					'required'   => array( 'metrics' ),
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'rows'      => array(
							'type'        => 'array',
							'description' => 'Data rows',
						),
						'totals'    => array(
							'type'        => 'object',
							'description' => 'Total values for metrics',
						),
						'row_count' => array(
							'type'        => 'integer',
							'description' => 'Number of rows returned',
						),
					),
				),

				'execute_callback'    => array( $this, 'execute_get_ga4_metrics' ),
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
	 * Register get-ga4-events tool
	 */
	private function register_get_ga4_events() {
		wp_register_ability(
			'marketing-analytics/get-ga4-events',
			array(
				'label'               => __( 'Get GA4 Events', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Returns GA4 event counts (eventName and eventCount) for a period, or for one event when event_name is given. Use it to check whether a specific event or conversion is firing and how often. For anything beyond event counts (users, sessions, revenue by event) use get-ga4-metrics instead.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'event_name' => array(
							'type'        => 'string',
							'description' => 'Specific event name to filter (optional)',
						),
						...Ability_Dates::properties( 'ga4' ),
						'limit'      => array(
							'type'        => 'integer',
							'description' => 'Maximum number of events to return',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 1000,
						),
					),
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'rows'        => array( 'type' => 'array' ),
						'event_count' => array( 'type' => 'integer' ),
					),
				),

				'execute_callback'    => array( $this, 'execute_get_ga4_events' ),
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
	 * Register get-ga4-realtime tool
	 */
	private function register_get_ga4_realtime() {
		wp_register_ability(
			'marketing-analytics/get-ga4-realtime',
			array(
				'label'               => __( 'Get GA4 Real-time Data', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Returns GA4 real-time figures for roughly the last 30 minutes (default: activeUsers). Use it only for questions about right now, such as "is anyone on the site" or checking a launch or campaign send. It has no date range and is not suitable for any historical question.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'metrics' => array(
							'type'        => 'array',
							'description' => 'GA4 real-time metric names (default activeUsers; also screenPageViews, eventCount, conversions, keyEvents)',
							'items'       => array( 'type' => 'string' ),
							'default'     => array( 'activeUsers' ),
						),
					),
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'active_users' => array( 'type' => 'integer' ),
						'data'         => array( 'type' => 'object' ),
					),
				),

				'execute_callback'    => array( $this, 'execute_get_ga4_realtime' ),
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
	 * Register get-traffic-sources tool
	 */
	private function register_get_traffic_sources() {
		wp_register_ability(
			'marketing-analytics/get-traffic-sources',
			array(
				'label'               => __( 'Get Traffic Sources', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Returns GA4 sessions and active users by sessionSource, sessionMedium and sessionCampaignName for a period. Use it to answer where traffic comes from and which campaigns drive visits. For channel-group level views or other metrics (engagement, conversions, revenue by channel) use get-ga4-metrics with the sessionDefaultChannelGroup dimension.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						...Ability_Dates::properties( 'ga4' ),
						'limit'      => array(
							'type'        => 'integer',
							'description' => 'Maximum number of sources to return',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 1000,
						),
					),
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'sources'  => array( 'type' => 'array' ),
						'channels' => array( 'type' => 'object' ),
					),
				),

				'execute_callback'    => array( $this, 'execute_get_traffic_sources' ),
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
	 * Register ga4-overview resource
	 */
	private function register_ga4_overview_resource() {
		wp_register_ability(
			'marketing-analytics/ga4-overview',
			array(
				'label'               => __( 'GA4 Overview', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Returns a fixed snapshot of the last 7 days (activeUsers, sessions, screenPageViews, bounceRate) for the GA4 property. Use it as a quick first look or to orient yourself. It takes no inputs, so for any other period, breakdown or metric use get-ga4-metrics.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'property_id' => array( 'type' => 'string' ),
						'key_metrics' => array( 'type' => 'object' ),
						'period'      => array( 'type' => 'string' ),
					),
				),

				'execute_callback'    => array( $this, 'execute_ga4_overview' ),
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
	 * Execute get-ga4-metrics tool
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_ga4_metrics( $args ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$client = new GA4_Client();

				return $client->run_report(
					$args['metrics'],
					$args['dimensions'] ?? array(),
					Ability_Dates::resolve( $args, 'ga4' ),
					array( 'limit' => $args['limit'] ?? 100 )
				);
			}
		);
	}

	/**
	 * Execute get-ga4-events tool
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_ga4_events( $args ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$client = new GA4_Client();

				return $client->get_event_data(
					$args['event_name'] ?? null,
					Ability_Dates::resolve( $args, 'ga4' ),
					$args['limit'] ?? 100
				);
			}
		);
	}

	/**
	 * Execute get-ga4-realtime tool
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_ga4_realtime( $args ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$client = new GA4_Client();

				return $client->get_realtime_data(
					$args['metrics'] ?? array( 'activeUsers' )
				);
			}
		);
	}

	/**
	 * Execute get-traffic-sources tool
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_traffic_sources( $args ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$client = new GA4_Client();

				return $client->get_traffic_sources(
					Ability_Dates::resolve( $args, 'ga4' ),
					$args['limit'] ?? 100
				);
			}
		);
	}

	/**
	 * Execute ga4-overview resource
	 *
	 * @param array $args Resource arguments (optional, not used for this resource).
	 * @return array Resource result.
	 */
	public function execute_ga4_overview( $args = array() ) {
		return Ability_Response::resource(
			'ga4://overview',
			function () {
				$client = new GA4_Client();

				// Get key metrics for last 7 days.
				$metrics = array( 'activeUsers', 'sessions', 'screenPageViews', 'bounceRate' );
				$data    = $client->run_report( $metrics, array(), '7daysAgo' );

				return array(
					'property_id' => $client->get_property_id(),
					'period'      => 'Last 7 days',
					// A report without dimensions returns its figures as a single row, not as totals.
					'key_metrics' => ! empty( $data['totals'] ) ? $data['totals'] : ( $data['rows'][0] ?? array() ),
					'row_count'   => $data['row_count'] ?? 0,
				);
			}
		);
	}
}
