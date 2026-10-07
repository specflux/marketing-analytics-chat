<?php
/**
 * Jetpack Stats Adapter
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Table names are built only from $wpdb->prefix plus fixed strings; every value goes through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * Jetpack Stats adapter: detection only. Stats live on WordPress.com.
 */
class Jetpack_Stats_Adapter extends Local_Analytics_Adapter {

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function slug() {
		return 'jetpack-stats';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Jetpack Stats';
	}

	/**
	 * Marker constant (jetpack.php).
	 *
	 * @return string
	 */
	protected function marker_constant() {
		return 'JETPACK__VERSION';
	}

	/**
	 * Not readable locally.
	 *
	 * @return bool
	 */
	public function is_supported() {
		return false;
	}

	/**
	 * Explanation.
	 *
	 * @return string
	 */
	public function unsupported_reason() {
		return 'Jetpack Stats keeps its numbers on WordPress.com and is not supported yet.';
	}

	/**
	 * No local data.
	 *
	 * @return bool
	 */
	public function has_data() {
		return false;
	}

	/**
	 * Unsupported.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public function totals( array $range ) {
		unset( $range );
		return array(
			'visitors'       => 0,
			'pageviews'      => 0,
			'sessions'       => null,
			'visitors_basis' => 'unsupported',
		);
	}

	/**
	 * Unsupported.
	 *
	 * @param array $range Range.
	 * @return array
	 */
	public function daily( array $range ) {
		unset( $range );
		return array();
	}

	/**
	 * Unsupported.
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function top_pages( array $range, $limit ) {
		unset( $range, $limit );
		return array();
	}

	/**
	 * Unsupported.
	 *
	 * @param array $range Range.
	 * @param int   $limit Limit.
	 * @return array
	 */
	public function referrers( array $range, $limit ) {
		unset( $range, $limit );
		return array();
	}
}
