<?php
/**
 * Independent Analytics Adapter
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Table names are built only from $wpdb->prefix plus fixed strings; every value goes through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * Independent Analytics adapter.
 */
class Independent_Analytics_Adapter extends Local_Analytics_Adapter {

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'independent-analytics';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Independent Analytics';
	}

	/**
	 * Marker constant (iawp-bootstrap.php).
	 *
	 * @return string
	 */
	protected function marker_constant() {
		return 'IAWP_VERSION';
	}

	/**
	 * Table name without prefix.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	private function t( $name ) {
		return 'independent_analytics_' . $name;
	}

	/**
	 * Whether any view has been recorded.
	 *
	 * @return bool
	 */
	public function has_data() {
		if ( ! $this->table_exists( $this->t( 'views' ) ) ) {
			return false;
		}

		return null !== $this->db->get_var( "SELECT id FROM {$this->db->prefix}independent_analytics_views LIMIT 1" );
	}

	/**
	 * Totals, using the same distinct counts as IAWP\Public_API\Analytics.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public function totals( array $range ) {
		$zero = array(
			'visitors'       => 0,
			'pageviews'      => 0,
			'sessions'       => 0,
			'visitors_basis' => 'unique_in_period',
		);
		if ( ! $this->tables_exist( array( $this->t( 'views' ), $this->t( 'sessions' ) ) ) ) {
			return $zero;
		}
		$sql  = $this->db->prepare(
			"SELECT COUNT(DISTINCT v.id) AS pageviews, COUNT(DISTINCT se.visitor_id) AS visitors, COUNT(DISTINCT se.session_id) AS sessions
			FROM {$this->db->prefix}independent_analytics_views v
			JOIN {$this->db->prefix}independent_analytics_sessions se ON se.session_id = v.session_id
			WHERE v.viewed_at >= %s AND v.viewed_at < %s",
			$range['utc_start'],
			$range['utc_end']
		);
		$rows = $this->rows( $sql );
		if ( empty( $rows ) ) {
			return $zero;
		}

		return array(
			'visitors'       => (int) $rows[0]->visitors,
			'pageviews'      => (int) $rows[0]->pageviews,
			'sessions'       => (int) $rows[0]->sessions,
			'visitors_basis' => 'unique_in_period',
		);
	}

	/**
	 * Per-day rows (viewed_at is UTC; shifted by the site offset).
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public function daily( array $range ) {
		if ( ! $this->tables_exist( array( $this->t( 'views' ), $this->t( 'sessions' ) ) ) ) {
			return array();
		}
		$sql = $this->db->prepare(
			"SELECT DATE_FORMAT(DATE_ADD(v.viewed_at, INTERVAL %d SECOND), %s) AS day, COUNT(DISTINCT v.id) AS pageviews, COUNT(DISTINCT se.visitor_id) AS visitors, COUNT(DISTINCT se.session_id) AS sessions
			FROM {$this->db->prefix}independent_analytics_views v
			JOIN {$this->db->prefix}independent_analytics_sessions se ON se.session_id = v.session_id
			WHERE v.viewed_at >= %s AND v.viewed_at < %s
			GROUP BY day
			ORDER BY day ASC",
			$range['offset'],
			'%Y-%m-%d',
			$range['utc_start'],
			$range['utc_end']
		);

		$out = array();
		foreach ( $this->rows( $sql ) as $row ) {
			$out[] = array(
				'date'      => (string) $row->day,
				'visitors'  => (int) $row->visitors,
				'pageviews' => (int) $row->pageviews,
				'sessions'  => (int) $row->sessions,
			);
		}

		return $out;
	}

	/**
	 * Top pages (resources hold the cached URL and title).
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function top_pages( array $range, $limit ) {
		if ( ! $this->tables_exist( array( $this->t( 'views' ), $this->t( 'sessions' ), $this->t( 'resources' ) ) ) ) {
			return array();
		}
		$sql = $this->db->prepare(
			"SELECT r.id AS resource_id, r.cached_url AS url, r.cached_title AS title, COUNT(DISTINCT v.id) AS pageviews, COUNT(DISTINCT se.visitor_id) AS visitors
			FROM {$this->db->prefix}independent_analytics_views v
			JOIN {$this->db->prefix}independent_analytics_resources r ON r.id = v.resource_id
			JOIN {$this->db->prefix}independent_analytics_sessions se ON se.session_id = v.session_id
			WHERE v.viewed_at >= %s AND v.viewed_at < %s
			GROUP BY r.id, r.cached_url, r.cached_title
			ORDER BY pageviews DESC, visitors DESC
			LIMIT %d",
			$range['utc_start'],
			$range['utc_end'],
			max( 1, (int) $limit )
		);

		$out = array();
		foreach ( $this->rows( $sql ) as $row ) {
			$path = '';
			if ( ! empty( $row->url ) ) {
				$parsed = wp_parse_url( (string) $row->url );
				$path   = is_array( $parsed ) && isset( $parsed['path'] ) ? $parsed['path'] : '/';
				if ( is_array( $parsed ) && ! empty( $parsed['query'] ) ) {
					$path .= '?' . $parsed['query'];
				}
			}
			if ( '' === $path ) {
				$path = (string) $row->title;
			}
			$out[] = array(
				'path'      => $path,
				'pageviews' => (int) $row->pageviews,
				'visitors'  => (int) $row->visitors,
			);
		}

		return $out;
	}

	/**
	 * Referrers per session; the "Direct" referrer type is left out.
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function referrers( array $range, $limit ) {
		if ( ! $this->tables_exist( array( $this->t( 'sessions' ), $this->t( 'referrers' ), $this->t( 'referrer_types' ) ) ) ) {
			return array();
		}
		$sql = $this->db->prepare(
			"SELECT rf.domain AS source, COUNT(DISTINCT se.session_id) AS sessions, COUNT(DISTINCT se.visitor_id) AS visitors
			FROM {$this->db->prefix}independent_analytics_sessions se
			JOIN {$this->db->prefix}independent_analytics_referrers rf ON rf.id = se.referrer_id
			JOIN {$this->db->prefix}independent_analytics_referrer_types rt ON rt.id = rf.referrer_type_id
			WHERE se.created_at >= %s AND se.created_at < %s AND rt.referrer_type <> %s
			GROUP BY rf.id, rf.domain
			ORDER BY sessions DESC
			LIMIT %d",
			$range['utc_start'],
			$range['utc_end'],
			'Direct',
			max( 1, (int) $limit ) * 3
		);

		$rows = array();
		foreach ( $this->rows( $sql ) as $row ) {
			$rows[] = array(
				'source'    => (string) $row->source,
				'pageviews' => null,
				'visitors'  => (int) $row->visitors,
				'sessions'  => (int) $row->sessions,
			);
		}

		return $this->merge_sources( $rows, $limit );
	}
}
