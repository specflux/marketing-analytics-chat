<?php
/**
 * SEO Issue Detector
 *
 * Pure rules engine: turns content rows (inventory item + SEO fields + analytics
 * metrics) into a ranked list of SEO issues.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Detects SEO issues.
 */
class Seo_Issue_Detector {

	const TITLE_MAX            = 60;
	const DESCRIPTION_MAX      = 160;
	const DESCRIPTION_MIN      = 70;
	const LOW_CTR_IMPRESSIONS  = 100;
	const HIGH_IMPRESSIONS     = 1000;
	const TOP_QUERIES_FOR_FOCUS = 10;

	/**
	 * Severity weights for ranking.
	 *
	 * @var array<string,int>
	 */
	private static $weights = array(
		'high'   => 3,
		'medium' => 2,
		'low'    => 1,
	);

	/**
	 * Detect issues.
	 *
	 * @param array[] $rows    Rows with keys: item (inventory item), seo (reader output),
	 *                         metrics (views/clicks/impressions/position or null), queries
	 *                         (string[] top GSC queries for the page, or null when unavailable).
	 * @param int     $max     Maximum issues to return.
	 * @return array{issues:array[],counts:array<string,int>,total:int,truncated:bool,site_avg_ctr:?float}
	 */
	public function detect( array $rows, $max = 50 ) {
		$issues = array();

		$site_avg_ctr = $this->site_average_ctr( $rows );
		$title_groups = $this->group_titles( $rows );

		foreach ( $rows as $row ) {
			$item        = $row['item'];
			$seo         = $row['seo'];
			$metrics     = $row['metrics'] ?? null;
			$queries     = $row['queries'] ?? null;
			$impressions = (int) ( $metrics['impressions'] ?? 0 );
			$views       = (int) ( $metrics['views'] ?? 0 );
			$has_traffic = $impressions > 0 || $views > 0;
			$popular     = $impressions >= self::LOW_CTR_IMPRESSIONS;

			$add = function ( $type, $severity, array $evidence ) use ( &$issues, $item, $impressions ) {
				$issues[] = array(
					'post_id'     => $item['post_id'],
					'url'         => $item['url'],
					'issue'       => $type,
					'severity'    => $severity,
					'evidence'    => $evidence,
					'impressions' => $impressions,
				);
			};

			// Description.
			if ( '' === $seo['description'] ) {
				$add( 'missing_meta_description', $popular ? 'high' : 'medium', array( 'description_source' => 'default_template (plugin falls back to its default)' ) );
			} elseif ( ! Seo_Plugin_Reader::has_template( $seo['description'] ) ) {
				// Template variables make the final length unknowable, so only literal text is measured.
				$length = self::length( $seo['description'] );
				if ( $length > self::DESCRIPTION_MAX ) {
					$add(
						'description_too_long',
						'low',
						array(
							'length'      => $length,
							'max'         => self::DESCRIPTION_MAX,
							'description' => $seo['description'],
						)
					);
				} elseif ( $length < self::DESCRIPTION_MIN ) {
					$add(
						'description_too_short',
						'low',
						array(
							'length'      => $length,
							'min'         => self::DESCRIPTION_MIN,
							'description' => $seo['description'],
						)
					);
				}
			}

			// Title.
			if ( '' === $seo['title'] ) {
				$add( 'missing_seo_title', 'low', array( 'title_source' => 'default_template (plugin falls back to its default)' ) );
			} else {
				if ( ! Seo_Plugin_Reader::has_template( $seo['title'] ) ) {
					$length = self::length( $seo['title'] );
					if ( $length > self::TITLE_MAX ) {
						$add(
							'title_too_long',
							$popular ? 'medium' : 'low',
							array(
								'length' => $length,
								'max'    => self::TITLE_MAX,
								'title'  => $seo['title'],
							)
						);
					}
				}

				$key = self::title_key( $seo['title'] );
				if ( isset( $title_groups[ $key ] ) && count( $title_groups[ $key ] ) > 1 ) {
					$others = array_values( array_diff( $title_groups[ $key ], array( $item['post_id'] ) ) );
					$add(
						'duplicate_seo_title',
						'medium',
						array(
							'title'          => $seo['title'],
							'also_used_by'   => array_slice( $others, 0, 5 ),
							'duplicate_count' => count( $title_groups[ $key ] ),
						)
					);
				}
			}

			// Indexing.
			if ( true === $seo['noindex'] && $has_traffic ) {
				$add(
					'noindex_with_traffic',
					'high',
					array(
						'gsc_impressions' => $metrics['impressions'] ?? null,
						'ga4_views'       => $metrics['views'] ?? null,
					)
				);
			}

			// Focus keyword.
			if ( '' !== $seo['plugin'] ) {
				if ( '' === $seo['focus_keyword'] ) {
					$add( 'missing_focus_keyword', 'low', array( 'plugin' => $seo['plugin'] ) );
				} elseif ( is_array( $queries ) && ! empty( $queries ) && ! self::keyword_in_queries( $seo['focus_keyword'], $queries ) ) {
					$add(
						'focus_keyword_not_in_top_queries',
						'medium',
						array(
							'focus_keyword' => $seo['focus_keyword'],
							'top_queries'   => $queries,
						)
					);
				}
			}

			// CTR.
			if ( $popular && null !== $site_avg_ctr ) {
				$ctr = $metrics['clicks'] / $impressions;
				if ( $ctr < $site_avg_ctr / 2 ) {
					$add(
						'high_impressions_low_ctr',
						$impressions >= self::HIGH_IMPRESSIONS ? 'high' : 'medium',
						array(
							'impressions'         => $impressions,
							'clicks'              => (int) $metrics['clicks'],
							'ctr'                 => round( $ctr, 4 ),
							'site_average_ctr'    => round( $site_avg_ctr, 4 ),
							'average_position'    => $metrics['position'] ?? null,
							'current_seo_title'   => $seo['title'],
							'current_description' => $seo['description'],
							'page_title'          => $item['title'],
							'uses_template'       => $seo['uses_template'],
						)
					);
				}
			}
		}

		usort(
			$issues,
			static function ( $a, $b ) {
				$by_severity = self::$weights[ $b['severity'] ] <=> self::$weights[ $a['severity'] ];

				return 0 !== $by_severity ? $by_severity : $b['impressions'] <=> $a['impressions'];
			}
		);

		$counts = array();
		foreach ( $issues as $issue ) {
			$counts[ $issue['issue'] ] = ( $counts[ $issue['issue'] ] ?? 0 ) + 1;
		}
		arsort( $counts );

		$total  = count( $issues );
		$issues = array_slice( $issues, 0, max( 1, (int) $max ) );
		foreach ( $issues as &$issue ) {
			unset( $issue['impressions'] );
		}
		unset( $issue );

		return array(
			'issues'       => $issues,
			'counts'       => $counts,
			'total'        => $total,
			'truncated'    => $total > count( $issues ),
			'site_avg_ctr' => null === $site_avg_ctr ? null : round( $site_avg_ctr, 4 ),
		);
	}

	/**
	 * Site-wide CTR over the rows that have known GSC numbers.
	 *
	 * @param array[] $rows Rows.
	 * @return float|null Null when no impressions are known.
	 */
	private function site_average_ctr( array $rows ) {
		$clicks      = 0;
		$impressions = 0;
		foreach ( $rows as $row ) {
			if ( isset( $row['metrics']['impressions'] ) && null !== $row['metrics']['impressions'] ) {
				$clicks      += (int) $row['metrics']['clicks'];
				$impressions += (int) $row['metrics']['impressions'];
			}
		}

		return $impressions > 0 ? $clicks / $impressions : null;
	}

	/**
	 * Group post IDs by normalised SEO title.
	 *
	 * @param array[] $rows Rows.
	 * @return array<string,int[]>
	 */
	private function group_titles( array $rows ) {
		$groups = array();
		foreach ( $rows as $row ) {
			if ( '' !== $row['seo']['title'] ) {
				$groups[ self::title_key( $row['seo']['title'] ) ][] = $row['item']['post_id'];
			}
		}

		return $groups;
	}

	/**
	 * Case-insensitive grouping key for a title.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	private static function title_key( $title ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title );
	}

	/**
	 * Character length.
	 *
	 * @param string $value Text.
	 * @return int
	 */
	private static function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	/**
	 * Whether the focus keyword matches any of the page's top queries.
	 *
	 * @param string   $keyword Focus keyword.
	 * @param string[] $queries Top queries.
	 * @return bool
	 */
	private static function keyword_in_queries( $keyword, array $queries ) {
		$keyword = self::title_key( $keyword );
		foreach ( $queries as $query ) {
			$query = self::title_key( (string) $query );
			if ( false !== strpos( $query, $keyword ) || false !== strpos( $keyword, $query ) ) {
				return true;
			}
		}

		return false;
	}
}
