<?php
/**
 * Koko Analytics Adapter
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Table names are built only from $wpdb->prefix plus fixed strings; every value goes through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * Koko Analytics adapter.
 */
class Koko_Analytics_Adapter extends Local_Analytics_Adapter {

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'koko-analytics';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Koko Analytics';
	}

	/**
	 * Marker constant (koko-analytics.php).
	 *
	 * @return string
	 */
	protected function marker_constant() {
		return 'KOKO_ANALYTICS_VERSION';
	}

	/**
	 * Whether any day has been recorded.
	 *
	 * @return bool
	 */
	public function has_data() {
		if ( ! $this->table_exists( 'koko_analytics_site_stats' ) ) {
			return false;
		}

		return null !== $this->db->get_var( "SELECT date FROM {$this->db->prefix}koko_analytics_site_stats LIMIT 1" );
	}

	/**
	 * Totals. Koko only stores per-day visitors, so visitors is the sum of daily visitors.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public function totals( array $range ) {
		$visitors  = 0;
		$pageviews = 0;
		foreach ( $this->daily( $range ) as $day ) {
			$visitors  += $day['visitors'];
			$pageviews += $day['pageviews'];
		}

		return array(
			'visitors'       => $visitors,
			'pageviews'      => $pageviews,
			'sessions'       => null,
			'visitors_basis' => 'sum_of_daily_visitors',
		);
	}

	/**
	 * Per-day rows.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public function daily( array $range ) {
		if ( ! $this->table_exists( 'koko_analytics_site_stats' ) ) {
			return array();
		}
		$sql = $this->db->prepare(
			"SELECT date, visitors, pageviews FROM {$this->db->prefix}koko_analytics_site_stats WHERE date BETWEEN %s AND %s ORDER BY date ASC",
			$range['start'],
			$range['end']
		);

		$out = array();
		foreach ( $this->rows( $sql ) as $row ) {
			$out[] = array(
				'date'      => (string) $row->date,
				'visitors'  => (int) $row->visitors,
				'pageviews' => (int) $row->pageviews,
				'sessions'  => null,
			);
		}

		return $out;
	}

	/**
	 * Top pages (post_stats joined to paths, see Stats::get_posts()).
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function top_pages( array $range, $limit ) {
		if ( ! $this->tables_exist( array( 'koko_analytics_post_stats', 'koko_analytics_paths' ) ) ) {
			return array();
		}
		$sql = $this->db->prepare(
			"SELECT p.path AS path, SUM(s.pageviews) AS pageviews, SUM(s.visitors) AS visitors
			FROM {$this->db->prefix}koko_analytics_post_stats s
			JOIN {$this->db->prefix}koko_analytics_paths p ON p.id = s.path_id
			WHERE s.date BETWEEN %s AND %s
			GROUP BY p.id, p.path
			ORDER BY pageviews DESC, visitors DESC
			LIMIT %d",
			$range['start'],
			$range['end'],
			max( 1, (int) $limit )
		);

		$out = array();
		foreach ( $this->rows( $sql ) as $row ) {
			$out[] = array(
				'path'      => (string) $row->path,
				'pageviews' => (int) $row->pageviews,
				'visitors'  => (int) $row->visitors,
			);
		}

		return $out;
	}

	/**
	 * Referrers (referrer_stats joined to referrer_labels, see Table::get()).
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function referrers( array $range, $limit ) {
		if ( ! $this->tables_exist( array( 'koko_analytics_referrer_stats', 'koko_analytics_referrer_labels' ) ) ) {
			return array();
		}
		$sql = $this->db->prepare(
			"SELECT l.value AS source, SUM(s.hits) AS pageviews, SUM(s.unique_hits) AS visitors
			FROM {$this->db->prefix}koko_analytics_referrer_stats s
			JOIN {$this->db->prefix}koko_analytics_referrer_labels l ON l.id = s.id
			WHERE s.date BETWEEN %s AND %s
			GROUP BY s.id, l.value
			ORDER BY pageviews DESC
			LIMIT %d",
			$range['start'],
			$range['end'],
			max( 1, (int) $limit ) * 3
		);

		$rows = array();
		foreach ( $this->rows( $sql ) as $row ) {
			$rows[] = array(
				'source'    => (string) $row->source,
				'pageviews' => (int) $row->pageviews,
				'visitors'  => (int) $row->visitors,
				'sessions'  => null,
			);
		}

		return $this->merge_sources( $rows, $limit );
	}
}
