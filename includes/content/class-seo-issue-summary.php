<?php
/**
 * SEO issue summary
 *
 * A small cached digest of the find-seo-issues scan for the dashboard widget
 * and the weekly email. The scan itself may call Search Console, so it only
 * runs from refresh() (an AJAX request or the weekly job). Reading the cache
 * with cached() never does any work, which keeps normal admin page loads fast.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

use Specflux_Marketing_Analytics\Abilities\Content_Abilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Cached SEO issue digest.
 *
 * The summary looks like:
 *
 *     array(
 *         'total'         => int,
 *         'counts'        => array( 'missing_meta_description' => 3, ... ),
 *         'top'           => array( array( 'post_id', 'title', 'url', 'issue', 'label', 'severity' ), ... ), // up to 3.
 *         'seo_plugin'    => string|null,  // e.g. "Yoast SEO", null when none is active.
 *         'pages_scanned' => int,          // 0 hides the dashboard row.
 *         'generated_at'  => int,
 *     )
 */
class Seo_Issue_Summary {

	/**
	 * Transient holding the summary.
	 */
	const TRANSIENT = 'specflux_mac_seo_summary';

	/**
	 * Number of issues listed.
	 */
	const TOP_COUNT = 3;

	/**
	 * Optional scan callable returning the find-seo-issues result (tests pass doubles).
	 *
	 * @var callable|null
	 */
	private $scanner;

	/**
	 * Constructor.
	 *
	 * @param callable|null $scanner Returns the scan array; defaults to Content_Abilities::scan_seo_issues().
	 */
	public function __construct( $scanner = null ) {
		$this->scanner = $scanner;
	}

	/**
	 * Cache lifetime in seconds.
	 *
	 * @return int
	 */
	public static function ttl() {
		return 12 * HOUR_IN_SECONDS;
	}

	/**
	 * Cached summary, or null when cold. Never scans.
	 *
	 * @return array|null
	 */
	public static function cached() {
		$stored = get_transient( self::TRANSIENT );

		return ( is_array( $stored ) && isset( $stored['total'], $stored['generated_at'] ) ) ? $stored : null;
	}

	/**
	 * Cached summary when warm, otherwise scan now. For AJAX and the weekly job only.
	 *
	 * @return array Summary (pages_scanned is 0 when the scan failed).
	 */
	public function get_or_refresh() {
		$cached = self::cached();

		return null === $cached ? $this->refresh() : $cached;
	}

	/**
	 * Run the scan and store the digest.
	 *
	 * A failed scan stores an empty summary for one hour so a broken site is
	 * not rescanned on every dashboard visit.
	 *
	 * @return array Summary.
	 */
	public function refresh() {
		try {
			$scan    = $this->scanner ? call_user_func( $this->scanner ) : ( new Content_Abilities() )->scan_seo_issues();
			$summary = self::build( (array) $scan );
			$ttl     = self::ttl();
		} catch ( \Throwable $e ) {
			$summary = self::build( array() );
			$ttl     = HOUR_IN_SECONDS;
		}

		set_transient( self::TRANSIENT, $summary, $ttl );

		return $summary;
	}

	/**
	 * Digest a scan result.
	 *
	 * @param array $scan Result of Content_Abilities::scan_seo_issues().
	 * @return array Summary.
	 */
	public static function build( array $scan ) {
		$top = array();

		foreach ( array_slice( (array) ( $scan['issues'] ?? array() ), 0, self::TOP_COUNT ) as $issue ) {
			if ( ! is_array( $issue ) || ! isset( $issue['issue'] ) ) {
				continue;
			}
			$url   = (string) ( $issue['url'] ?? '' );
			$title = (string) get_the_title( (int) ( $issue['post_id'] ?? 0 ) );
			$top[] = array(
				'post_id'  => (int) ( $issue['post_id'] ?? 0 ),
				'title'    => '' === $title ? $url : $title,
				'url'      => $url,
				'issue'    => (string) $issue['issue'],
				'label'    => self::label( (string) $issue['issue'] ),
				'severity' => (string) ( $issue['severity'] ?? 'low' ),
			);
		}

		return array(
			'total'         => (int) ( $scan['total_issues'] ?? 0 ),
			'counts'        => array_map( 'intval', (array) ( $scan['counts_by_type'] ?? array() ) ),
			'top'           => $top,
			'seo_plugin'    => isset( $scan['seo_plugin'] ) && is_string( $scan['seo_plugin'] ) ? $scan['seo_plugin'] : null,
			'pages_scanned' => (int) ( $scan['pages_scanned'] ?? 0 ),
			'generated_at'  => time(),
		);
	}

	/**
	 * Human label for an issue type.
	 *
	 * @param string $type Issue type from Seo_Issue_Detector.
	 * @return string
	 */
	public static function label( $type ) {
		$labels = array(
			'missing_meta_description'         => __( 'Missing meta description', 'specflux-marketing-analytics-chat' ),
			'description_too_long'             => __( 'Meta description too long', 'specflux-marketing-analytics-chat' ),
			'description_too_short'            => __( 'Meta description too short', 'specflux-marketing-analytics-chat' ),
			'missing_seo_title'                => __( 'Missing SEO title', 'specflux-marketing-analytics-chat' ),
			'title_too_long'                   => __( 'SEO title too long', 'specflux-marketing-analytics-chat' ),
			'duplicate_seo_title'              => __( 'Duplicate SEO title', 'specflux-marketing-analytics-chat' ),
			'noindex_with_traffic'             => __( 'Set to noindex but gets traffic', 'specflux-marketing-analytics-chat' ),
			'missing_focus_keyword'            => __( 'No focus keyword', 'specflux-marketing-analytics-chat' ),
			'focus_keyword_not_in_top_queries' => __( 'Focus keyword not in top search queries', 'specflux-marketing-analytics-chat' ),
			'high_impressions_low_ctr'         => __( 'Many impressions, few clicks', 'specflux-marketing-analytics-chat' ),
		);

		return $labels[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) );
	}
}
