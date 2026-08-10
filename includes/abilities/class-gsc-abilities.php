<?php
/**
 * Google Search Console Abilities
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

use Specflux_Marketing_Analytics\API_Clients\GSC_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Registers Google Search Console MCP abilities
 */
class GSC_Abilities {

	/**
	 * Register GSC abilities
	 */
	public function register() {
		// Only register abilities if credentials are configured.
		$credential_manager = new Credential_Manager();
		if ( ! $credential_manager->has_credentials( 'gsc' ) ) {
			return;
		}

		$this->register_get_search_performance();
		$this->register_get_top_queries();
		$this->register_get_indexing_status();
		$this->register_gsc_overview_resource();
	}

	/**
	 * Register get-search-performance tool
	 */
	private function register_get_search_performance() {
		wp_register_ability(
			'marketing-analytics/get-search-performance',
			array(
				'label'               => __( 'Get Search Performance', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Retrieve search performance data from Google Search Console including clicks, impressions, CTR, and position.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'date_range' => array(
							'type'        => 'string',
							'description' => 'Date range (e.g., "7daysAgo", "30daysAgo")',
							'default'     => '7daysAgo',
						),
						'dimensions' => array(
							'type'        => 'array',
							'description' => 'Dimensions to group by (query, page, country, device, searchAppearance)',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'query', 'page', 'country', 'device', 'searchAppearance' ),
							),
						),
						'filters'    => array(
							'type'        => 'object',
							'description' => 'Filters to apply to the query',
						),
						'limit'      => array(
							'type'        => 'integer',
							'description' => 'Maximum number of rows to return',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 25000,
						),
					),
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'rows'      => array(
							'type'        => 'array',
							'description' => 'Search performance data rows',
						),
						'row_count' => array(
							'type'        => 'integer',
							'description' => 'Number of rows returned',
						),
					),
				),

				'execute_callback'    => array( $this, 'execute_get_search_performance' ),
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
	 * Register get-top-queries tool
	 */
	private function register_get_top_queries() {
		wp_register_ability(
			'marketing-analytics/get-top-queries',
			array(
				'label'               => __( 'Get Top Queries', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Get top-performing search queries from Google Search Console.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'date_range'      => array(
							'type'        => 'string',
							'description' => 'Date range (e.g., "7daysAgo", "30daysAgo")',
							'default'     => '7daysAgo',
						),
						'limit'           => array(
							'type'        => 'integer',
							'description' => 'Maximum number of queries to return',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 1000,
						),
						'min_impressions' => array(
							'type'        => 'integer',
							'description' => 'Minimum number of impressions to include',
							'default'     => 10,
							'minimum'     => 0,
						),
					),
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'rows'      => array(
							'type'        => 'array',
							'description' => 'Top queries with metrics',
						),
						'row_count' => array(
							'type'        => 'integer',
							'description' => 'Number of queries returned',
						),
					),
				),

				'execute_callback'    => array( $this, 'execute_get_top_queries' ),
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
	 * Register get-indexing-status tool
	 */
	private function register_get_indexing_status() {
		wp_register_ability(
			'marketing-analytics/get-indexing-status',
			array(
				'label'               => __( 'Get Indexing Status', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Check page indexing status and coverage issues in Google Search Console.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'page_url' => array(
							'type'        => 'string',
							'description' => 'Specific page URL to inspect (optional)',
						),
					),
				),

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'coverage' => array(
							'type'        => 'object',
							'description' => 'Indexing coverage information',
						),
						'errors'   => array(
							'type'        => 'array',
							'description' => 'Indexing errors',
						),
						'warnings' => array(
							'type'        => 'array',
							'description' => 'Indexing warnings',
						),
					),
				),

				'execute_callback'    => array( $this, 'execute_get_indexing_status' ),
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
	 * Register gsc-overview resource
	 */
	private function register_gsc_overview_resource() {
		wp_register_ability(
			'marketing-analytics/gsc-overview',
			array(
				'label'               => __( 'Search Console Overview', 'specflux-marketing-analytics-chat' ),
				'description'         => __( 'Get Google Search Console site summary with verification status, indexed pages, and top queries.', 'specflux-marketing-analytics-chat' ),
				'category'            => 'marketing-analytics',

				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'site_url'            => array( 'type' => 'string' ),
						'verification_status' => array( 'type' => 'string' ),
						'summary'             => array( 'type' => 'object' ),
					),
				),

				'execute_callback'    => array( $this, 'execute_gsc_overview' ),
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
	 * Execute get-search-performance tool
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_search_performance( $args ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$client = new GSC_Client();

				return $client->query_search_analytics(
					$args['date_range'] ?? '7daysAgo',
					$args['dimensions'] ?? array(),
					$args['filters'] ?? array(),
					array( 'row_limit' => $args['limit'] ?? 100 )
				);
			}
		);
	}

	/**
	 * Execute get-top-queries tool
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_top_queries( $args ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$client = new GSC_Client();

				return $client->get_top_queries(
					$args['date_range'] ?? '7daysAgo',
					$args['limit'] ?? 100,
					$args['min_impressions'] ?? 10
				);
			}
		);
	}

	/**
	 * Execute get-indexing-status tool
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_indexing_status( $args ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$client = new GSC_Client();

				if ( ! empty( $args['page_url'] ) ) {
					// Get URL inspection data.
					return $client->get_url_inspection( $args['page_url'] );
				}

				// Get sitemap status.
				return $client->get_sitemap_status();
			}
		);
	}

	/**
	 * Execute gsc-overview resource
	 *
	 * @param array $args Resource arguments.
	 * @return array Resource result.
	 */
	public function execute_gsc_overview( $args ) {
		return Ability_Response::resource(
			'gsc://overview',
			function () {
				$client = new GSC_Client();

				// Get top queries for overview.
				$top_queries = $client->get_top_queries( '7daysAgo', 10, 5 );

				// Get search performance summary.
				$performance = $client->query_search_analytics( '7daysAgo', array(), array(), array( 'row_limit' => 1 ) );

				return array(
					'site_url'    => $client->get_site_url(),
					'period'      => 'Last 7 days',
					'top_queries' => $top_queries,
					'performance' => $performance,
				);
			}
		);
	}
}
