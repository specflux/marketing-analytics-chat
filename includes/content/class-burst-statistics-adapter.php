<?php
/**
 * Burst Statistics Adapter
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Table names are built only from $wpdb->prefix plus fixed strings; every value goes through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * Burst Statistics adapter.
 */
class Burst_Statistics_Adapter extends Local_Analytics_Adapter {

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'burst-statistics';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Burst Statistics';
	}

	/**
	 * Marker constant (includes/class-burst.php).
	 *
	 * @return string
	 */
	protected function marker_constant() {
		return 'BURST_VERSION';
	}

	/**
	 * Whether any hit has been recorded.
	 *
	 * @return bool
	 */
	public function has_data() {
		if ( ! $this->table_exists( 'burst_statistics' ) ) {
			return false;
		}

		return null !== $this->db->get_var( "SELECT ID FROM {$this->db->prefix}burst_statistics LIMIT 1" );
	}

	/**
	 * Totals. 404 hits are excluded like Burst does; visitors are distinct uid_id.
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
		if ( ! $this->table_exists( 'burst_statistics' ) ) {
			return $zero;
		}
		$sql = $this->db->prepare(
			"SELECT COUNT(s.ID) AS pageviews, COUNT(DISTINCT NULLIF(s.uid_id, 0)) AS visitors, COUNT(DISTINCT s.session_id) AS sessions
			FROM {$this->db->prefix}burst_statistics s
			WHERE s.time >= %d AND s.time < %d AND s.page_type <> %s",
			$range['start_ts'],
			$range['end_ts'],
			'404'
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
	 * Per-day rows. Days are bucketed with integer math on the unix time (no FROM_UNIXTIME, so the
	 * MySQL session time zone does not matter), using the site's UTC offset at the start of the range.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public function daily( array $range ) {
		if ( ! $this->table_exists( 'burst_statistics' ) ) {
			return array();
		}
		$sql = $this->db->prepare(
			"SELECT FLOOR((s.time + %d) / 86400) AS day_index, COUNT(s.ID) AS pageviews, COUNT(DISTINCT NULLIF(s.uid_id, 0)) AS visitors, COUNT(DISTINCT s.session_id) AS sessions
			FROM {$this->db->prefix}burst_statistics s
			WHERE s.time >= %d AND s.time < %d AND s.page_type <> %s
			GROUP BY day_index
			ORDER BY day_index ASC",
			$range['offset'],
			$range['start_ts'],
			$range['end_ts'],
			'404'
		);

		$out = array();
		foreach ( $this->rows( $sql ) as $row ) {
			$out[] = array(
				'date'      => gmdate( 'Y-m-d', (int) $row->day_index * 86400 ),
				'visitors'  => (int) $row->visitors,
				'pageviews' => (int) $row->pageviews,
				'sessions'  => (int) $row->sessions,
			);
		}

		return $out;
	}

	/**
	 * Top pages (page_url holds the path).
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function top_pages( array $range, $limit ) {
		if ( ! $this->table_exists( 'burst_statistics' ) ) {
			return array();
		}
		$sql = $this->db->prepare(
			"SELECT s.page_url AS path, COUNT(s.ID) AS pageviews, COUNT(DISTINCT NULLIF(s.uid_id, 0)) AS visitors
			FROM {$this->db->prefix}burst_statistics s
			WHERE s.time >= %d AND s.time < %d AND s.page_type <> %s
			GROUP BY s.page_url
			ORDER BY pageviews DESC, visitors DESC
			LIMIT %d",
			$range['start_ts'],
			$range['end_ts'],
			'404',
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
	 * Referrers, one count per session (burst_sessions.referrer).
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function referrers( array $range, $limit ) {
		if ( ! $this->table_exists( 'burst_sessions' ) ) {
			return array();
		}
		$sql = $this->db->prepare(
			"SELECT se.referrer AS source, COUNT(se.ID) AS sessions, COUNT(DISTINCT NULLIF(se.uid_id, 0)) AS visitors
			FROM {$this->db->prefix}burst_sessions se
			WHERE se.start_time >= %d AND se.start_time < %d AND se.referrer IS NOT NULL AND se.referrer <> %s
			GROUP BY se.referrer
			ORDER BY sessions DESC
			LIMIT %d",
			$range['start_ts'],
			$range['end_ts'],
			'',
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
