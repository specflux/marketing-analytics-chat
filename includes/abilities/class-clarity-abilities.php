<?php
/**
 * Microsoft Clarity Abilities
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

use Specflux_Marketing_Analytics\API_Clients\Clarity_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Registers Microsoft Clarity MCP abilities
 */
class Clarity_Abilities {

	/**
	 * Register Clarity abilities
	 */
	public function register() {
		// Only register abilities if credentials are configured.
		$credential_manager = new Credential_Manager();
		if ( ! $credential_manager->has_credentials( 'clarity' ) ) {
			return;
		}

		$this->register_get_clarity_insights();
		$this->register_get_clarity_recordings();
		$this->register_analyze_clarity_heatmaps();
		$this->register_clarity_dashboard_resource();
	}

	/**
	 * Register get-clarity-insights tool
	 */
	private function register_get_clarity_insights() {
		wp_register_ability(
			'marketing-analytics/get-clarity-insights',
			array(
				'label'               => __( 'Get Microsoft Clarity Insights', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Returns Microsoft Clarity behaviour metrics (sessions, scroll depth, dead and rage clicks, quick-backs, excessive scrolling and similar friction signals) for only the last 1, 2 or 3 days, optionally split by up to three dimensions (such as Browser, Device, Country, OS, Source, URL). The 1-3 day window is a Clarity API limit and cannot be extended; for longer periods use GA4. Use it to find UX friction on the site. For traffic volumes or trends use get-ga4-metrics.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'num_of_days' => array(
							'type'        => 'integer',
							'description' => 'Trailing window in days: 1 (last 24h), 2 (last 48h) or 3 (last 72h). This is a hard Clarity API limit: longer periods and custom start/end dates are not available from Clarity, so use GA4 for those.',
							'enum'        => array( 1, 2, 3 ),
						),
						'dimension1'  => array(
							'type'        => 'string',
							'description' => 'First dimension to break down insights',
							'enum'        => array( 'OS', 'Browser', 'Device', 'Country' ),
						),
						'dimension2'  => array(
							'type'        => 'string',
							'description' => 'Second dimension (optional)',
							'enum'        => array( 'OS', 'Browser', 'Device', 'Country' ),
						),
						'dimension3'  => array(
							'type'        => 'string',
							'description' => 'Third dimension (optional)',
							'enum'        => array( 'OS', 'Browser', 'Device', 'Country' ),
						),
					),
					'required'   => array( 'num_of_days' ),
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'metrics'  => array(
							'type'        => 'object',
							'description' => 'Clarity dashboard metrics',
						),
						'insights' => array(
							'type'        => 'array',
							'description' => 'Array of insight objects',
						),
						'period'   => array(
							'type'        => 'string',
							'description' => 'Time period covered',
						),
					),
				),

				'execute_callback'    => array( $this, 'execute_get_clarity_insights' ),
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
	 * Register get-clarity-recordings tool
	 */
	private function register_get_clarity_recordings() {
		wp_register_ability(
			'marketing-analytics/get-clarity-recordings',
			array(
				'label'               => __( 'Get Clarity Session Recordings', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Returns links to individual Microsoft Clarity session recordings matching the filters. Use it only when the user wants to watch examples of real sessions after a friction problem has been found; it gives no aggregate numbers. For metrics use get-clarity-insights.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'filters' => array(
							'type'        => 'object',
							'description' => 'Filters for recordings (device, browser, country, etc.)',
						),
						'limit'   => array(
							'type'        => 'integer',
							'description' => 'Number of recordings to return (max 100)',
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'sort_by' => array(
							'type'        => 'string',
							'description' => 'Sort recordings by criteria',
							'enum'        => array( 'date', 'duration', 'pages_viewed' ),
							'default'     => 'date',
						),
					),
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'recordings'  => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'session_id' => array( 'type' => 'string' ),
									'url'        => array( 'type' => 'string' ),
									'duration'   => array( 'type' => 'integer' ),
									'device'     => array( 'type' => 'string' ),
								),
							),
						),
						'total_count' => array(
							'type'        => 'integer',
							'description' => 'Total recordings matching filters',
						),
					),
				),

				'execute_callback'    => array( $this, 'execute_get_clarity_recordings' ),
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
	 * Register analyze-clarity-heatmaps tool
	 */
	private function register_analyze_clarity_heatmaps() {
		wp_register_ability(
			'marketing-analytics/analyze-clarity-heatmaps',
			array(
				'label'               => __( 'Analyze Clarity Heatmaps', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Returns click, scroll or attention heatmap data and plain-language behaviour insights for a single page URL from Microsoft Clarity. Use it for how visitors interact with one specific page. For site-wide behaviour metrics use get-clarity-insights.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'page_url'     => array(
							'type'        => 'string',
							'description' => 'URL of the page to analyze',
						),
						'heatmap_type' => array(
							'type'        => 'string',
							'description' => 'Type of heatmap to retrieve',
							'enum'        => array( 'click', 'scroll' ),
							'default'     => 'click',
						),
					),
					'required'   => array( 'page_url' ),
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'heatmap_data' => array(
							'type'        => 'object',
							'description' => 'Heatmap data points',
						),
						'insights'     => array(
							'type'        => 'string',
							'description' => 'AI-friendly insights about user behavior',
						),
					),
				),

				'execute_callback'    => array( $this, 'execute_analyze_clarity_heatmaps' ),
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
	 * Register clarity-dashboard resource
	 */
	private function register_clarity_dashboard_resource() {
		wp_register_ability(
			'marketing-analytics/clarity-dashboard',
			array(
				'label'               => __( 'Clarity Dashboard Summary', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Returns a recent Microsoft Clarity project snapshot with session counts, active users and top insights. Use it as a quick first look at Clarity; it takes no inputs. For breakdowns by device, browser or page use get-clarity-insights (Clarity only covers the last 1-3 days).', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'project_id'   => array( 'type' => 'string' ),
						'sessions'     => array( 'type' => 'integer' ),
						'active_users' => array( 'type' => 'integer' ),
						'insights'     => array( 'type' => 'array' ),
					),
				),

				'execute_callback'    => array( $this, 'execute_clarity_dashboard' ),
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
	 * Get Clarity API client with credentials
	 *
	 * @return Clarity_Client|null Client instance or null if credentials not found.
	 * @throws \Exception If credentials are not configured.
	 */
	private function get_clarity_client() {
		return new Clarity_Client();
	}

	/**
	 * Execute get-clarity-insights tool
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_clarity_insights( $args ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$client = $this->get_clarity_client();

				// Build dimensions array.
				$dimensions = array();
				if ( ! empty( $args['dimension1'] ) ) {
					$dimensions[] = $args['dimension1'];
				}
				if ( ! empty( $args['dimension2'] ) ) {
					$dimensions[] = $args['dimension2'];
				}
				if ( ! empty( $args['dimension3'] ) ) {
					$dimensions[] = $args['dimension3'];
				}

				return $client->get_insights( $args['num_of_days'], $dimensions );
			}
		);
	}

	/**
	 * Execute get-clarity-recordings tool
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_clarity_recordings( $args ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$client = $this->get_clarity_client();

				return $client->get_session_recordings(
					$args['filters'] ?? array(),
					$args['limit'] ?? 10,
					$args['sort_by'] ?? 'date'
				);
			}
		);
	}

	/**
	 * Execute analyze-clarity-heatmaps tool
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_analyze_clarity_heatmaps( $args ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$this->get_clarity_client();

				// Note: This is a placeholder - Clarity API may not directly support heatmap data export.
				// You may need to use their web interface or alternative methods.
				return array(
					'page_url'     => $args['page_url'],
					'heatmap_type' => $args['heatmap_type'] ?? 'click',
					'note'         => 'Heatmap data retrieval may require Clarity web interface access',
				);
			}
		);
	}

	/**
	 * Execute clarity-dashboard resource
	 *
	 * @param array $args Resource arguments (optional, not used for this resource).
	 * @return array Resource result.
	 */
	public function execute_clarity_dashboard( $args = array() ) {
		return Ability_Response::resource(
			'clarity://dashboard',
			function () {
				$client = $this->get_clarity_client();

				// Get 1-day insights for dashboard summary.
				$data = $client->get_insights( 1 );

				return array(
					'project_id' => get_option( 'specflux_mac_clarity_project_id', 'Not configured' ),
					'period'     => 'Last 24 hours',
					'data'       => $data,
				);
			}
		);
	}
}
