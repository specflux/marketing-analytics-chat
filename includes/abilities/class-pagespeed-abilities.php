<?php
/**
 * PageSpeed Insights Abilities
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

use Specflux_Marketing_Analytics\Admin\Post_Stats;
use Specflux_Marketing_Analytics\API_Clients\PageSpeed_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers PageSpeed Insights / Core Web Vitals MCP abilities
 */
class PageSpeed_Abilities {

	/**
	 * Seconds reserved for one uncached Lighthouse run when budgeting time.
	 */
	const RUN_HEADROOM = 45;

	/**
	 * Register abilities (only when an API key is saved).
	 */
	public function register() {
		if ( ! ( new Credential_Manager() )->has_credentials( 'pagespeed' ) ) {
			return;
		}

		$this->register_tool(
			'marketing-analytics/get-page-speed',
			__( 'Get Page Speed', 'specflux-marketing-analytics-chat' ),
			__( 'Run PageSpeed Insights (Lighthouse) for a page and return its performance score (0-100), lab metrics (LCP, CLS, TBT, FCP, Speed Index, TTI), real-user field data when available, and the top 5 fixes with estimated savings. Use for "why is my site/page slow" or "how fast is /pricing/". Takes 10-40 seconds when not cached; results are cached for 24 hours.', 'specflux-marketing-analytics-chat' ),
			array(
				'url'      => array(
					'type'        => 'string',
					'description' => 'Page URL on this site, or a path such as /pricing/. Defaults to the home page.',
				),
				'strategy' => array(
					'type'        => 'string',
					'description' => 'Device to simulate',
					'enum'        => array( 'mobile', 'desktop' ),
					'default'     => 'mobile',
				),
			),
			'execute_get_page_speed'
		);

		$this->register_tool(
			'marketing-analytics/get-core-web-vitals',
			__( 'Get Core Web Vitals', 'specflux-marketing-analytics-chat' ),
			__( 'Return real-user Core Web Vitals field data (Chrome UX Report: LCP, INP, CLS, FCP, TTFB with FAST/AVERAGE/SLOW) for a page and its whole origin, plus a pass/fail assessment. Use for "do we pass Core Web Vitals" questions. PageSpeed Insights always runs Lighthouse too, so this reuses a cached run when available; otherwise it takes 10-40 seconds.', 'specflux-marketing-analytics-chat' ),
			array(
				'url'      => array(
					'type'        => 'string',
					'description' => 'Page URL on this site, or a path such as /pricing/. Defaults to the home page.',
				),
				'strategy' => array(
					'type'        => 'string',
					'description' => 'Device to report field data for',
					'enum'        => array( 'mobile', 'desktop' ),
					'default'     => 'mobile',
				),
			),
			'execute_get_core_web_vitals'
		);

		$this->register_tool(
			'marketing-analytics/audit-top-pages-speed',
			__( 'Audit Top Pages Speed', 'specflux-marketing-analytics-chat' ),
			__( 'Check mobile page speed for the site\'s top pages (by Google Analytics 4 views when connected, otherwise the home page and latest posts) and return a table ranked slowest first with score, LCP, CLS, TBT and Core Web Vitals status. Use for "which of my pages are slowest" or a site-wide speed review. Runs sequentially and may stop early with a partial note; re-run to continue using cached results.', 'specflux-marketing-analytics-chat' ),
			array(
				'limit' => array(
					'type'        => 'integer',
					'description' => 'Number of pages to audit',
					'default'     => 3,
					'minimum'     => 1,
					'maximum'     => 10,
				),
			),
			'execute_audit_top_pages_speed'
		);
	}

	/**
	 * Register one read-only tool.
	 *
	 * @param string $name       Ability name.
	 * @param string $label      Label.
	 * @param string $description Description.
	 * @param array  $properties Input schema properties.
	 * @param string $callback   Method name on this class.
	 */
	private function register_tool( $name, $label, $description, array $properties, $callback ) {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'marketing-analytics',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => $properties,
				),
				'output_schema'       => array( 'type' => 'object' ),
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
	 * Build the client (overridable in tests).
	 *
	 * @return PageSpeed_Client
	 */
	protected function make_client() {
		return new PageSpeed_Client();
	}

	/**
	 * Execute get-page-speed.
	 *
	 * @param array|null $args Input.
	 * @return array Tool result.
	 */
	public function execute_get_page_speed( $args = null ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args = is_array( $args ) ? $args : array();

				return $this->make_client()->get_summary( $args['url'] ?? '', $args['strategy'] ?? 'mobile' );
			}
		);
	}

	/**
	 * Execute get-core-web-vitals.
	 *
	 * @param array|null $args Input.
	 * @return array Tool result.
	 */
	public function execute_get_core_web_vitals( $args = null ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args    = is_array( $args ) ? $args : array();
				$summary = $this->make_client()->get_summary( $args['url'] ?? '', $args['strategy'] ?? 'mobile' );

				return array(
					'url'                => $summary['url'],
					'strategy'           => $summary['strategy'],
					'page_field_data'    => $summary['field'],
					'origin_field_data'  => $summary['origin'],
					'page_assessment'    => PageSpeed_Client::assess( $summary['field'] ),
					'origin_assessment'  => PageSpeed_Client::assess( $summary['origin'] ),
					'data_as_of'         => $summary['fetched_at'],
					'note'               => 'Field data is the 28-day Chrome UX Report; pages with too little traffic have none, in which case use the origin data.',
				);
			}
		);
	}

	/**
	 * Execute audit-top-pages-speed.
	 *
	 * @param array|null $args Input.
	 * @return array Tool result.
	 */
	public function execute_audit_top_pages_speed( $args = null ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args  = is_array( $args ) ? $args : array();
				$limit = max( 1, min( 10, (int) ( $args['limit'] ?? 3 ) ) );

				$client = $this->make_client();
				$pages  = $this->pick_pages( $limit );

				$max_exec = (int) ini_get( 'max_execution_time' );
				$budget   = $max_exec > 0 ? max( 0, $max_exec - 5 ) : 300;
				/**
				 * Filter the seconds the audit may spend before stopping with a partial result.
				 *
				 * @param int $budget Seconds.
				 */
				$budget = (int) apply_filters( 'specflux_mac_pagespeed_time_budget', $budget );
				$start  = microtime( true );

				$rows    = array();
				$skipped = 0;
				foreach ( $pages['pages'] as $page ) {
					try {
						$cached = $client->is_cached( $page['path'], 'mobile' );
						if ( ! $cached && ( microtime( true ) - $start ) + self::RUN_HEADROOM > $budget ) {
							++$skipped;
							continue;
						}

						$summary = $client->get_summary( $page['path'], 'mobile' );
						$rows[]  = array(
							'url'        => $summary['url'],
							'views_28d'  => $page['views'],
							'score'      => $summary['score'],
							'lcp'        => $summary['lab']['lcp']['display_value'] ?? null,
							'cls'        => $summary['lab']['cls']['display_value'] ?? null,
							'tbt'        => $summary['lab']['tbt']['display_value'] ?? null,
							'cwv_status' => PageSpeed_Client::assess( $summary['field'] ),
							'top_issue'  => $summary['issues'][0]['title'] ?? null,
						);
					} catch ( \Exception $e ) {
						$rows[] = array(
							'url'   => $page['path'],
							'error' => $e->getMessage(),
						);
					}
				}

				usort(
					$rows,
					static function ( $a, $b ) {
						return ( $a['score'] ?? -1 ) <=> ( $b['score'] ?? -1 );
					}
				);
				// Errors (no score) sort first; move them to the end.
				usort(
					$rows,
					static function ( $a, $b ) {
						return isset( $a['error'] ) <=> isset( $b['error'] );
					}
				);

				$result = array(
					'source'   => $pages['source'],
					'strategy' => 'mobile',
					'rows'     => $rows,
					'partial'  => $skipped > 0,
				);
				if ( $skipped > 0 ) {
					$result['note'] = sprintf( 'Stopped early to respect the PHP time limit; %d page(s) not checked. Run again to continue (finished pages are cached).', $skipped );
				}

				return $result;
			}
		);
	}

	/**
	 * Pick pages to audit: top GA4 pages by views, else home + latest posts.
	 *
	 * @param int $limit Max pages.
	 * @return array{source:string,pages:array<int,array{path:string,views:int|null}>}
	 */
	protected function pick_pages( $limit ) {
		$pages = array();

		$ga4 = $this->get_ga4_views();
		if ( ! empty( $ga4 ) ) {
			arsort( $ga4 );
			foreach ( $ga4 as $path => $views ) {
				// Skip admin/login/feeds and asset-like paths.
				if ( preg_match( '#^/(wp-|feed)|\.[a-z0-9]{2,5}$#i', $path ) ) {
					continue;
				}
				$pages[] = array(
					'path'  => rawurldecode( $path ),
					'views' => (int) $views,
				);
				if ( count( $pages ) >= $limit ) {
					break;
				}
			}
			if ( ! empty( $pages ) ) {
				return array(
					'source' => 'ga4_top_pages_28d',
					'pages'  => $pages,
				);
			}
		}

		$pages[] = array(
			'path'  => '/',
			'views' => null,
		);
		if ( function_exists( 'get_posts' ) ) {
			$posts = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => max( 0, $limit - 1 ),
					'fields'         => 'ids',
				)
			);
			foreach ( (array) $posts as $post_id ) {
				$link = get_permalink( $post_id );
				if ( $link ) {
					$pages[] = array(
						'path'  => $link,
						'views' => null,
					);
				}
			}
		}

		return array(
			'source' => 'home_and_latest_posts',
			'pages'  => array_slice( $pages, 0, $limit ),
		);
	}

	/**
	 * Path => views from the cached 28-day GA4 stats (empty when GA4 is not connected).
	 *
	 * @return array<string,int>
	 */
	protected function get_ga4_views() {
		try {
			$stats = new Post_Stats();
			if ( ! in_array( 'ga4', $stats->get_connected_platforms(), true ) ) {
				return array();
			}
			$cache = $stats->get_cached();
			if ( null === $cache ) {
				$cache = $stats->build_cache();
			}

			return isset( $cache['ga4'] ) && is_array( $cache['ga4'] ) ? $cache['ga4'] : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	}
}
