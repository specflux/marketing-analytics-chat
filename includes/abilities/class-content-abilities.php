<?php
/**
 * WordPress Content & SEO Abilities
 *
 * Makes the chat aware of the site's own content and SEO-plugin data, and joins
 * it with GA4 and Google Search Console numbers.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Abilities;

use Specflux_Marketing_Analytics\Admin\Post_Stats;
use Specflux_Marketing_Analytics\API_Clients\GSC_Client;
use Specflux_Marketing_Analytics\Content\Content_Inventory;
use Specflux_Marketing_Analytics\Content\Seo_Issue_Detector;
use Specflux_Marketing_Analytics\Content\Seo_Plugin_Reader;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the content and SEO abilities.
 */
class Content_Abilities {

	/**
	 * Most recent items analysed when ranking by traffic.
	 */
	const POOL_SIZE = 500;

	/**
	 * Content inventory.
	 *
	 * @var Content_Inventory
	 */
	private $inventory;

	/**
	 * SEO plugin reader.
	 *
	 * @var Seo_Plugin_Reader
	 */
	private $reader;

	/**
	 * Post stats (GA4 + GSC 28-day per-path map).
	 *
	 * @var Post_Stats|null
	 */
	private $stats;

	/**
	 * Factory returning a GSC client (overridable in tests).
	 *
	 * @var callable|null
	 */
	private $gsc_factory;

	/**
	 * Constructor.
	 *
	 * @param Content_Inventory|null $inventory   Optional inventory.
	 * @param Seo_Plugin_Reader|null $reader      Optional reader.
	 * @param Post_Stats|null        $stats       Optional post stats.
	 * @param callable|null          $gsc_factory Optional factory for the GSC client.
	 */
	public function __construct( $inventory = null, $reader = null, $stats = null, $gsc_factory = null ) {
		$this->inventory   = $inventory ? $inventory : new Content_Inventory();
		$this->reader      = $reader ? $reader : new Seo_Plugin_Reader();
		$this->stats       = $stats;
		$this->gsc_factory = $gsc_factory;
	}

	/**
	 * Register the abilities.
	 */
	public function register() {
		$this->register_get_content_inventory();
		$this->register_get_content_performance();
		$this->register_get_seo_metadata();
		$this->register_find_seo_issues();
	}

	/**
	 * Shared registration arguments.
	 *
	 * @param string $label       Label.
	 * @param string $description Description.
	 * @param array  $properties  Input schema properties.
	 * @param string $callback    Execute method name.
	 * @return array
	 */
	private function definition( $label, $description, array $properties, $callback ) {
		return array(
			'label'               => $label,
			'description'         => $description,
			'category'            => 'marketing-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => $properties,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'period' => array( 'type' => 'string' ),
					'items'  => array( 'type' => 'array' ),
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
		);
	}

	/**
	 * Input properties shared by the content filters.
	 *
	 * @return array
	 */
	private function filter_properties() {
		return array(
			'post_type'  => array(
				'type'        => 'string',
				'description' => 'Post type slug, e.g. "post" or "page". Default: all public post types.',
			),
			'category'   => array(
				'type'        => 'string',
				'description' => 'Category slug to filter by (posts only).',
			),
			'start_date' => array(
				'type'        => 'string',
				'description' => 'Only content published on or after this date (Y-m-d).',
			),
			'end_date'   => array(
				'type'        => 'string',
				'description' => 'Only content published on or before this date (Y-m-d).',
			),
		);
	}

	/**
	 * Register get-content-inventory.
	 */
	private function register_get_content_inventory() {
		wp_register_ability(
			'marketing-analytics/get-content-inventory',
			$this->definition(
				__( 'Get Content Inventory', 'specflux-marketing-analytics-chat' ),
				__( 'List this site\'s published posts and pages: title, URL, path, type, publish and modified dates, author, categories, word count and comment count. Use it to answer "what content do I have", to find recent, old or most-discussed content, or before joining content with analytics. Only published, public content is returned.', 'specflux-marketing-analytics-chat' ),
				array_merge(
					$this->filter_properties(),
					array(
						'limit'   => array(
							'type'        => 'integer',
							'description' => 'Items to return (default 25, max 100).',
							'default'     => 25,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'orderby' => array(
							'type'        => 'string',
							'description' => 'Sort order, newest first.',
							'enum'        => array( 'date', 'modified', 'comments' ),
							'default'     => 'date',
						),
					)
				),
				'execute_get_content_inventory'
			)
		);
	}

	/**
	 * Register get-content-performance.
	 */
	private function register_get_content_performance() {
		wp_register_ability(
			'marketing-analytics/get-content-performance',
			$this->definition(
				__( 'Get Content Performance', 'specflux-marketing-analytics-chat' ),
				__( 'Join the site\'s published content with its last 28 days of Google Analytics 4 views and Google Search Console clicks, impressions, CTR and average position per page. Use it to find top or underperforming posts, and (with stale_days) content that is getting traffic but has not been updated and needs a refresh. Analyses the 500 most recently published matching items; a null metric means that platform is not connected or the page was outside its report.', 'specflux-marketing-analytics-chat' ),
				array_merge(
					$this->filter_properties(),
					array(
						'limit'      => array(
							'type'        => 'integer',
							'description' => 'Items to return (default 25, max 100).',
							'default'     => 25,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'sort_by'    => array(
							'type'        => 'string',
							'description' => 'Metric to rank by, highest first.',
							'enum'        => array( 'views', 'clicks', 'impressions' ),
							'default'     => 'views',
						),
						'stale_days' => array(
							'type'        => 'integer',
							'description' => 'Only content not modified in at least this many days (e.g. 365 for content to refresh).',
							'minimum'     => 1,
						),
					)
				),
				'execute_get_content_performance'
			)
		);
	}

	/**
	 * Register get-seo-metadata.
	 */
	private function register_get_seo_metadata() {
		wp_register_ability(
			'marketing-analytics/get-seo-metadata',
			$this->definition(
				__( 'Get SEO Metadata', 'specflux-marketing-analytics-chat' ),
				__( 'Report which SEO plugin the site runs (Yoast SEO, Rank Math, All in One SEO or SEOPress) and each page\'s SEO title, meta description, focus keyword(s), SEO score where the plugin stores one, noindex flag and canonical URL. Titles and descriptions are returned raw: uses_template flags template variables, and an empty value means the plugin falls back to its default template. Pass post_ids or urls for specific pages, otherwise the top pages by Search Console impressions are returned. Use it to review or suggest rewrites of titles and descriptions.', 'specflux-marketing-analytics-chat' ),
				array(
					'post_ids' => array(
						'type'        => 'array',
						'description' => 'Post IDs to read.',
						'items'       => array( 'type' => 'integer' ),
					),
					'urls'     => array(
						'type'        => 'array',
						'description' => 'Page URLs on this site to read.',
						'items'       => array( 'type' => 'string' ),
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => 'Top pages to return when no post_ids or urls are given (default 10, max 50).',
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 50,
					),
				),
				'execute_get_seo_metadata'
			)
		);
	}

	/**
	 * Register find-seo-issues.
	 */
	private function register_find_seo_issues() {
		wp_register_ability(
			'marketing-analytics/find-seo-issues',
			$this->definition(
				__( 'Find SEO Issues', 'specflux-marketing-analytics-chat' ),
				__( 'Scan published content for SEO problems by joining the SEO plugin\'s fields with Search Console and GA4: missing meta description, missing or duplicate SEO title, over-long titles (over 60 characters), over-long or too-short descriptions (over 160 or under 70), noindex on pages that get traffic, missing focus keyword, focus keyword absent from the page\'s top search queries, and high-impression pages with low CTR (with the current title and description so you can suggest rewrites). Returns a ranked list (severity, then impressions) with evidence, plus counts per issue type. Use it for "what should I fix first" SEO questions.', 'specflux-marketing-analytics-chat' ),
				array_merge(
					array(
						'post_type'  => $this->filter_properties()['post_type'],
						'start_date' => $this->filter_properties()['start_date'],
						'end_date'   => $this->filter_properties()['end_date'],
						'scan_limit' => array(
							'type'        => 'integer',
							'description' => 'How many pages to scan, highest Search Console impressions first (default 200, max 500).',
							'default'     => 200,
							'minimum'     => 1,
							'maximum'     => 500,
						),
						'max_issues' => array(
							'type'        => 'integer',
							'description' => 'Maximum issues to list (default 50, max 200). Counts always cover all issues found.',
							'default'     => 50,
							'minimum'     => 1,
							'maximum'     => 200,
						),
					)
				),
				'execute_find_seo_issues'
			)
		);
	}

	/**
	 * Execute get-content-inventory.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_content_inventory( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args = (array) $args;
				$this->assert_dates( $args );

				$items = $this->inventory->query( $args );

				return array(
					'period'  => $this->describe_publish_period( $args ),
					'count'   => count( $items ),
					'filters' => $this->describe_filters( $args ),
					'items'   => $items,
				);
			}
		);
	}

	/**
	 * Execute get-content-performance.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_content_performance( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args = (array) $args;
				$this->assert_dates( $args );

				$sort_by = isset( $args['sort_by'] ) && in_array( $args['sort_by'], array( 'views', 'clicks', 'impressions' ), true ) ? $args['sort_by'] : 'views';
				$limit   = Content_Inventory::clamp_limit( $args['limit'] ?? Content_Inventory::DEFAULT_LIMIT );

				$pool_args          = $args;
				$pool_args['limit'] = self::POOL_SIZE;
				$pool_args['hard_cap'] = self::POOL_SIZE;
				$pool               = $this->inventory->query( $pool_args );

				$analytics = $this->load_analytics();
				$rows      = $this->attach_metrics( $pool, $analytics );

				if ( ! empty( $args['stale_days'] ) ) {
					$cutoff = gmdate( 'Y-m-d', strtotime( '-' . (int) $args['stale_days'] . ' days' ) );
					$rows   = array_values(
						array_filter(
							$rows,
							static function ( $row ) use ( $cutoff ) {
								return $row['modified'] < $cutoff;
							}
						)
					);
				}

				$rows = $this->sort_rows( $rows, $sort_by );

				return array(
					'period'        => $this->describe_analytics_period() . ' Content filtered by ' . $this->describe_publish_period( $args ) . '.',
					'sorted_by'     => $sort_by,
					'stale_days'    => ! empty( $args['stale_days'] ) ? (int) $args['stale_days'] : null,
					'pool_size'     => count( $pool ),
					'pool_note'     => count( $pool ) >= self::POOL_SIZE ? 'Only the ' . self::POOL_SIZE . ' most recently published matching items were analysed.' : '',
					'data_status'   => $analytics['status'],
					'matching'      => count( $rows ),
					'items'         => array_slice( $rows, 0, $limit ),
				);
			}
		);
	}

	/**
	 * Execute get-seo-metadata.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_get_seo_metadata( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args   = (array) $args;
				$plugin = $this->reader->detect();
				$limit  = Content_Inventory::clamp_limit( $args['limit'] ?? 10, 50 );

				$ids = array();
				foreach ( (array) ( $args['post_ids'] ?? array() ) as $requested ) {
					$ids[] = (int) $requested;
				}
				foreach ( (array) ( $args['urls'] ?? array() ) as $url ) {
					$resolved = url_to_postid( (string) $url );
					if ( $resolved ) {
						$ids[] = (int) $resolved;
					}
				}
				$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, 50 );

				if ( $ids ) {
					$items = $this->inventory->query(
						array(
							'include'  => $ids,
							'limit'    => count( $ids ),
							'hard_cap' => 50,
						)
					);
				} else {
					$analytics = $this->load_analytics();
					$pool      = $this->inventory->query(
						array(
							'limit'    => self::POOL_SIZE,
							'hard_cap' => self::POOL_SIZE,
						)
					);
					$items     = array_slice( $this->sort_rows( $this->attach_metrics( $pool, $analytics ), 'impressions' ), 0, $limit );
				}

				$found   = array_column( $items, 'post_id' );
				$skipped = array_values( array_diff( $ids, $found ) );
				$seo     = $this->reader->read_many( $found, $plugin );

				$out = array();
				foreach ( $items as $item ) {
					$fields = $seo[ $item['post_id'] ];
					unset( $fields['post_id'], $fields['plugin'] );
					$out[] = array(
						'post_id' => $item['post_id'],
						'url'     => $item['url'],
						'type'    => $item['type'],
						'seo'     => $fields,
					);
				}

				$result = array(
					'period'     => 'Current stored values (no date range).',
					'seo_plugin' => '' === $plugin ? null : array(
						'slug'  => $plugin,
						'label' => $this->reader->get_label( $plugin ),
					),
					'note'       => '' === $plugin ? 'No supported SEO plugin (Yoast SEO, Rank Math, All in One SEO, SEOPress) is active, so no SEO fields are available.' : '',
					'items'      => $out,
				);

				if ( $skipped ) {
					$result['skipped_not_published_or_not_found'] = $skipped;
				}

				if ( Seo_Plugin_Reader::RANKMATH === $plugin ) {
					$logs = $this->reader->get_rank_math_404s( 10 );
					if ( null !== $logs ) {
						$result['rank_math_404_monitor'] = $logs;
					}
				}

				return $result;
			}
		);
	}

	/**
	 * Execute find-seo-issues.
	 *
	 * @param array $args Tool arguments.
	 * @return array Tool result.
	 */
	public function execute_find_seo_issues( $args = array() ) {
		return Ability_Response::tool(
			function () use ( $args ) {
				$args = (array) $args;
				$this->assert_dates( $args );

				$plugin     = $this->reader->detect();
				$scan_limit = Content_Inventory::clamp_limit( $args['scan_limit'] ?? 200, self::POOL_SIZE );
				$max_issues = Content_Inventory::clamp_limit( $args['max_issues'] ?? 50, 200 );

				$pool_args             = $args;
				$pool_args['limit']    = self::POOL_SIZE;
				$pool_args['hard_cap'] = self::POOL_SIZE;
				$analytics             = $this->load_analytics();
				$pool                  = $this->sort_rows( $this->attach_metrics( $this->inventory->query( $pool_args ), $analytics ), 'impressions' );
				$scanned               = array_slice( $pool, 0, $scan_limit );

				$seo = $this->reader->read_many( array_column( $scanned, 'post_id' ), $plugin );

				$queries_note = 'Focus keyword vs top search queries check was skipped: Search Console is not connected.';
				$queries_map  = null;
				if ( 'ok' === $analytics['status']['gsc'] || 'partial' === $analytics['status']['gsc'] ) {
					list( $queries_map, $queries_note ) = $this->load_page_queries();
				}

				$rows = array();
				foreach ( $scanned as $entry ) {
					$metrics = $entry['metrics'];
					unset( $entry['metrics'] );
					$rows[] = array(
						'item'    => $entry,
						'seo'     => $seo[ $entry['post_id'] ],
						'metrics' => $metrics,
						'queries' => ( null !== $queries_map && isset( $queries_map[ $entry['path'] ] ) ) ? $queries_map[ $entry['path'] ] : null,
					);
				}

				$result = ( new Seo_Issue_Detector() )->detect( $rows, $max_issues );

				return array(
					'period'            => $this->describe_analytics_period() . ' Content filtered by ' . $this->describe_publish_period( $args ) . '.',
					'seo_plugin'        => '' === $plugin ? null : $this->reader->get_label( $plugin ),
					'note'              => '' === $plugin ? 'No supported SEO plugin is active; only analytics-based checks (CTR) ran. SEO titles and descriptions were not checked; installing one of Yoast SEO, Rank Math, All in One SEO or SEOPress enables those checks.' : '',
					'pages_scanned'     => count( $scanned ),
					'pages_available'   => count( $pool ),
					'data_status'       => $analytics['status'],
					'queries_check'     => null === $queries_map ? $queries_note : 'ran',
					'site_average_ctr'  => $result['site_avg_ctr'],
					'total_issues'      => $result['total'],
					'listed_issues'     => count( $result['issues'] ),
					'truncated'         => $result['truncated'],
					'counts_by_type'    => $result['counts'],
					'template_note'     => 'Titles and descriptions containing template variables are not length-checked.',
					'issues'            => $result['issues'],
				);
			}
		);
	}

	/**
	 * Load the cached GA4 + GSC 28-day map through Post_Stats.
	 *
	 * @return array{cache:?array,status:array<string,string>}
	 */
	private function load_analytics() {
		$stats     = $this->get_stats();
		$platforms = $stats->get_connected_platforms();
		$status    = array(
			'ga4' => 'not_connected',
			'gsc' => 'not_connected',
		);

		if ( empty( $platforms ) ) {
			return array(
				'cache'  => null,
				'status' => $status,
			);
		}

		$cache = $stats->get_cached();
		if ( null === $cache ) {
			$cache = $stats->build_cache();
		}

		foreach ( array( 'ga4', 'gsc' ) as $platform ) {
			if ( ! in_array( $platform, $platforms, true ) ) {
				continue;
			}
			if ( ! empty( $cache['errors'][ $platform ] ) ) {
				$status[ $platform ] = 'error';
			} elseif ( ! empty( $cache['capped'][ $platform ] ) ) {
				$status[ $platform ] = 'partial';
			} else {
				$status[ $platform ] = 'ok';
			}
		}

		return array(
			'cache'  => $cache,
			'status' => $status,
		);
	}

	/**
	 * Add metrics to inventory items.
	 *
	 * @param array[] $items     Inventory items.
	 * @param array   $analytics Result of load_analytics().
	 * @return array[] Items with a `metrics` key.
	 */
	private function attach_metrics( array $items, array $analytics ) {
		$stats = $this->get_stats();

		foreach ( $items as &$entry ) {
			$metrics = null === $analytics['cache'] ? null : $stats->get_post_metrics( $entry['post_id'], $analytics['cache'] );
			if ( null === $metrics ) {
				$metrics = array(
					'views'       => null,
					'clicks'      => null,
					'impressions' => null,
					'position'    => null,
				);
			}

			$metrics['ctr']   = ( ! empty( $metrics['impressions'] ) ) ? round( $metrics['clicks'] / $metrics['impressions'], 4 ) : null;
			$entry['metrics'] = $metrics;
		}
		unset( $entry );

		return $items;
	}

	/**
	 * Sort rows by a metric, highest first; unknown values last.
	 *
	 * @param array[] $rows   Rows with metrics.
	 * @param string  $sort_by views|clicks|impressions.
	 * @return array[]
	 */
	private function sort_rows( array $rows, $sort_by ) {
		usort(
			$rows,
			static function ( $a, $b ) use ( $sort_by ) {
				$av = $a['metrics'][ $sort_by ];
				$bv = $b['metrics'][ $sort_by ];
				if ( null === $av && null === $bv ) {
					return strcmp( $b['published'], $a['published'] );
				}
				if ( null === $av ) {
					return 1;
				}
				if ( null === $bv ) {
					return -1;
				}

				return $bv <=> $av;
			}
		);

		return $rows;
	}

	/**
	 * Top GSC queries per page path (one cached page+query request).
	 *
	 * @return array{0:?array,1:string} Map of path => top queries (or null) and a note.
	 */
	private function load_page_queries() {
		try {
			$client = $this->gsc_factory ? call_user_func( $this->gsc_factory ) : new GSC_Client();
			$range  = gmdate( 'Y-m-d', strtotime( '-30 days' ) ) . ',' . gmdate( 'Y-m-d', strtotime( '-3 days' ) );
			$data   = $client->query_search_analytics( $range, array( 'page', 'query' ), array(), array( 'row_limit' => 5000 ) );
		} catch ( \Throwable $e ) {
			return array( null, 'Focus keyword vs top search queries check was skipped: Search Console query data could not be loaded.' );
		}

		$by_path = array();
		foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
			if ( ! isset( $row['keys'][0], $row['keys'][1] ) ) {
				continue;
			}
			$path = Post_Stats::normalize_path( $row['keys'][0] );
			if ( null === $path ) {
				continue;
			}
			$by_path[ $path ][ (string) $row['keys'][1] ] = ( $by_path[ $path ][ (string) $row['keys'][1] ] ?? 0 ) + (float) ( $row['impressions'] ?? 0 );
		}

		$map = array();
		foreach ( $by_path as $path => $queries ) {
			arsort( $queries );
			$map[ $path ] = array_slice( array_map( 'strval', array_keys( $queries ) ), 0, Seo_Issue_Detector::TOP_QUERIES_FOR_FOCUS );
		}

		return array( $map, '' );
	}

	/**
	 * Post stats instance.
	 *
	 * @return Post_Stats
	 */
	private function get_stats() {
		if ( null === $this->stats ) {
			$this->stats = new Post_Stats();
		}

		return $this->stats;
	}

	/**
	 * Reject malformed dates.
	 *
	 * @param array $args Tool arguments.
	 * @throws \Exception When a date is not Y-m-d.
	 */
	private function assert_dates( array $args ) {
		foreach ( array( 'start_date', 'end_date' ) as $key ) {
			if ( ! empty( $args[ $key ] ) && ! Content_Inventory::is_date( $args[ $key ] ) ) {
				throw new \Exception( esc_html( $key . ' must be a valid date in Y-m-d format.' ) );
			}
		}
	}

	/**
	 * Describe the publish-date filter.
	 *
	 * @param array $args Tool arguments.
	 * @return string
	 */
	private function describe_publish_period( array $args ) {
		$start = ! empty( $args['start_date'] ) ? $args['start_date'] : null;
		$end   = ! empty( $args['end_date'] ) ? $args['end_date'] : null;

		if ( $start && $end ) {
			return "published {$start} to {$end}";
		}
		if ( $start ) {
			return "published on or after {$start}";
		}
		if ( $end ) {
			return "published on or before {$end}";
		}

		return 'all publish dates';
	}

	/**
	 * Describe the fixed analytics window.
	 *
	 * @return string
	 */
	private function describe_analytics_period() {
		return 'Analytics: GA4 views for the last 28 days; Search Console clicks/impressions for ' . gmdate( 'Y-m-d', strtotime( '-30 days' ) ) . ' to ' . gmdate( 'Y-m-d', strtotime( '-3 days' ) ) . ' (28 days, ending 3 days ago).';
	}

	/**
	 * Echo back the filters applied.
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function describe_filters( array $args ) {
		return array_filter(
			array(
				'post_type' => $args['post_type'] ?? null,
				'category'  => $args['category'] ?? null,
				'orderby'   => $args['orderby'] ?? 'date',
			)
		);
	}
}
