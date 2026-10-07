<?php
/**
 * Form entries reader
 *
 * Counts form submissions from the supported form plugins' own database
 * storage. Only counts, form titles and page URLs are ever read out: names,
 * emails, phone numbers, message bodies and IP addresses are never returned.
 *
 * Storage per plugin (verified against the plugin source):
 * - Fluent Forms: {prefix}fluentform_submissions (form_id, created_at, status, source_url).
 * - Contact Form 7 + Flamingo: Flamingo `flamingo_inbound` posts; the `_meta`
 *   post meta holds the submission page `url`; the channel term maps to the form.
 * - Formidable: {prefix}frm_items (form_id, created_at, is_draft, description JSON
 *   with `referrer`).
 * - WPForms Lite: no entries table; only a rolling 30-day site-wide daily count
 *   (option `wpforms_entries_count_daily`) and a lifetime count per form.
 *   WPForms Pro keeps {prefix}wpforms_entries (form_id, date); not verified here.
 * - Gravity Forms: {prefix}gf_entry / gf_form (documented schema; not verified here).
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

use Specflux_Marketing_Analytics\Admin\Post_Stats;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reads form entry counts from supported form plugins.
 */
class Form_Entries_Reader {

	const WPFORMS       = 'wpforms';
	const GRAVITYFORMS  = 'gravityforms';
	const FLUENTFORM    = 'fluentform';
	const CF7           = 'cf7';
	const FORMIDABLE    = 'formidable';

	/**
	 * Most grouped rows or entries read per plugin in one call.
	 */
	const ROW_CAP = 20000;

	/**
	 * Human-readable plugin names.
	 *
	 * @var array<string,string>
	 */
	const LABELS = array(
		self::WPFORMS      => 'WPForms',
		self::GRAVITYFORMS => 'Gravity Forms',
		self::FLUENTFORM   => 'Fluent Forms',
		self::CF7          => 'Contact Form 7 (with Flamingo)',
		self::FORMIDABLE   => 'Formidable Forms',
	);

	/**
	 * Notes gathered while reading (limits the caller should relay).
	 *
	 * @var string[]
	 */
	private $notes = array();

	/**
	 * Whether a constant is defined (overridable in tests).
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	protected function has_constant( $name ) {
		return defined( $name );
	}

	/**
	 * Whether a class exists (overridable in tests).
	 *
	 * @param string $name Class name.
	 * @return bool
	 */
	protected function has_class( $name ) {
		return class_exists( $name );
	}

	/**
	 * Whether a database table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	protected function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table;
	}

	/**
	 * Slugs of the supported form plugins that can supply entry counts.
	 *
	 * Contact Form 7 only counts when Flamingo (which stores its messages) is active too.
	 *
	 * @return string[]
	 */
	public function detect() {
		$active = array();

		if ( $this->has_constant( 'WPFORMS_VERSION' ) ) {
			$active[] = self::WPFORMS;
		}
		if ( $this->has_class( 'GFForms' ) || $this->has_class( 'GFAPI' ) ) {
			$active[] = self::GRAVITYFORMS;
		}
		if ( $this->has_constant( 'FLUENTFORM' ) ) {
			$active[] = self::FLUENTFORM;
		}
		if ( $this->has_constant( 'WPCF7_VERSION' ) && $this->has_constant( 'FLAMINGO_VERSION' ) ) {
			$active[] = self::CF7;
		}
		if ( $this->has_class( 'FrmAppHelper' ) ) {
			$active[] = self::FORMIDABLE;
		}

		return $active;
	}

	/**
	 * Display name for a plugin slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	public function get_label( $slug ) {
		return self::LABELS[ $slug ] ?? '';
	}

	/**
	 * Comma-separated names of the detected plugins.
	 *
	 * @return string
	 */
	public function get_labels() {
		return implode( ', ', array_map( array( $this, 'get_label' ), $this->detect() ) );
	}

	/**
	 * Limits noticed during the last read.
	 *
	 * @return string[]
	 */
	public function get_notes() {
		return array_values( array_unique( $this->notes ) );
	}

	/**
	 * Whether any detected plugin records the page a form was submitted from.
	 *
	 * @return bool
	 */
	public function stores_page_urls() {
		return (bool) array_diff( $this->detect(), array( self::WPFORMS ) );
	}

	/**
	 * List forms across the detected plugins.
	 *
	 * @return array[] Each: plugin, form_id (string), title, lifetime_entries (int|null).
	 */
	public function list_forms() {
		$forms = array();

		foreach ( $this->detect() as $plugin ) {
			foreach ( $this->forms_for( $plugin ) as $form ) {
				$forms[] = array(
					'plugin'           => $plugin,
					'form_id'          => (string) $form['id'],
					'title'            => (string) $form['title'],
					'lifetime_entries' => $form['lifetime'] ?? null,
				);
			}
		}

		return $forms;
	}

	/**
	 * Grouped entry counts for a date range across the detected plugins.
	 *
	 * @param string $start Start date (Y-m-d, inclusive).
	 * @param string $end   End date (Y-m-d, inclusive).
	 * @return array[] Each: plugin, form_id, title, day (Y-m-d), url ('' when not stored), count.
	 */
	public function get_rows( $start, $end ) {
		$this->notes = array();
		$rows        = array();

		foreach ( $this->detect() as $plugin ) {
			$titles = array();
			foreach ( $this->forms_for( $plugin ) as $form ) {
				$titles[ (string) $form['id'] ] = (string) $form['title'];
			}

			foreach ( $this->rows_for( $plugin, $start, $end ) as $row ) {
				$form_id = (string) $row['form_id'];
				$rows[]  = array(
					'plugin'  => $plugin,
					'form_id' => $form_id,
					'title'   => $row['title'] ?? ( $titles[ $form_id ] ?? ( 'Form ' . $form_id ) ),
					'day'     => (string) $row['day'],
					'url'     => (string) $row['url'],
					'count'   => (int) $row['count'],
				);
			}
		}

		return $rows;
	}

	/**
	 * Entries per page path (query string and trailing slash removed).
	 *
	 * @param array[] $rows Result of get_rows().
	 * @return array{pages:array<string,array{entries:int,forms:array<string,int>}>,unattributed:int}
	 */
	public static function group_by_page( array $rows ) {
		$pages        = array();
		$unattributed = 0;

		foreach ( $rows as $row ) {
			$path = '' === $row['url'] ? null : Post_Stats::normalize_path( $row['url'] );
			if ( null === $path ) {
				$unattributed += $row['count'];
				continue;
			}
			if ( ! isset( $pages[ $path ] ) ) {
				$pages[ $path ] = array(
					'entries' => 0,
					'forms'   => array(),
				);
			}
			$pages[ $path ]['entries']                  += $row['count'];
			$pages[ $path ]['forms'][ $row['title'] ] = ( $pages[ $path ]['forms'][ $row['title'] ] ?? 0 ) + $row['count'];
		}

		return array(
			'pages'        => $pages,
			'unattributed' => $unattributed,
		);
	}

	/**
	 * Entries per UTM source / medium / campaign found on the submission page URL.
	 *
	 * @param array[] $rows Result of get_rows().
	 * @return array{sources:array<string,array{source:string,medium:string,campaign:string,entries:int}>,with_utm:int,without_utm:int}
	 */
	public static function group_by_source( array $rows ) {
		$sources = array();
		$with    = 0;
		$without = 0;

		foreach ( $rows as $row ) {
			$utm = self::utm_from_url( $row['url'] );
			if ( null === $utm ) {
				$without += $row['count'];
				continue;
			}
			$with += $row['count'];
			$key   = $utm['source'] . '|' . $utm['medium'] . '|' . $utm['campaign'];
			if ( ! isset( $sources[ $key ] ) ) {
				$sources[ $key ] = $utm + array( 'entries' => 0 );
			}
			$sources[ $key ]['entries'] += $row['count'];
		}

		return array(
			'sources'     => $sources,
			'with_utm'    => $with,
			'without_utm' => $without,
		);
	}

	/**
	 * UTM parameters from a URL's query string.
	 *
	 * @param string $url Submission page URL.
	 * @return array{source:string,medium:string,campaign:string}|null Null when no UTM source is present.
	 */
	public static function utm_from_url( $url ) {
		$query = '' === $url ? null : wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return null;
		}

		$params = array();
		parse_str( $query, $params );

		$source = isset( $params['utm_source'] ) && is_string( $params['utm_source'] ) ? strtolower( trim( $params['utm_source'] ) ) : '';
		if ( '' === $source ) {
			return null;
		}

		return array(
			'source'   => substr( $source, 0, 100 ),
			'medium'   => isset( $params['utm_medium'] ) && is_string( $params['utm_medium'] ) ? substr( strtolower( trim( $params['utm_medium'] ) ), 0, 100 ) : '',
			'campaign' => isset( $params['utm_campaign'] ) && is_string( $params['utm_campaign'] ) ? substr( strtolower( trim( $params['utm_campaign'] ) ), 0, 100 ) : '',
		);
	}

	/**
	 * Forms for one plugin.
	 *
	 * @param string $plugin Plugin slug.
	 * @return array[] Each: id, title, lifetime (optional).
	 */
	private function forms_for( $plugin ) {
		switch ( $plugin ) {
			case self::FLUENTFORM:
				return $this->query_forms( 'fluentform_forms', 'id', 'title', '' );
			case self::GRAVITYFORMS:
				return $this->query_forms( 'gf_form', 'id', 'title', 'is_trash = 0' );
			case self::FORMIDABLE:
				return $this->query_forms( 'frm_forms', 'id', 'name', "is_template = 0 AND parent_form_id = 0 AND status <> 'trash'" );
			case self::CF7:
				return $this->cf7_forms();
			case self::WPFORMS:
				return $this->wpforms_forms();
		}

		return array();
	}

	/**
	 * Read id/title pairs from a plugin table, if it exists.
	 *
	 * @param string $table       Table name without prefix.
	 * @param string $id_col      ID column.
	 * @param string $title_col   Title column.
	 * @param string $where       Static WHERE clause (no user input), or ''.
	 * @return array[]
	 */
	private function query_forms( $table, $id_col, $title_col, $where ) {
		global $wpdb;

		$name = $wpdb->prefix . $table;
		if ( ! $this->table_exists( $name ) ) {
			return array();
		}

		$sql = "SELECT {$id_col} AS id, {$title_col} AS title FROM {$name}" . ( '' !== $where ? " WHERE {$where}" : '' ) . ' LIMIT %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_results( $wpdb->prepare( $sql, 1000 ), ARRAY_A );

		return is_array( $found ) ? $found : array();
	}

	/**
	 * Contact Form 7 forms (post type wpcf7_contact_form).
	 *
	 * @return array[]
	 */
	private function cf7_forms() {
		global $wpdb;

		$posts = $wpdb->posts;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID AS id, post_title AS title, post_name AS slug FROM {$posts} WHERE post_type = %s AND post_status = %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'wpcf7_contact_form',
				'publish',
				1000
			),
			ARRAY_A
		);

		return is_array( $found ) ? $found : array();
	}

	/**
	 * WPForms forms (post type wpforms) with the lifetime entry count Lite keeps.
	 *
	 * @return array[]
	 */
	private function wpforms_forms() {
		global $wpdb;

		$posts = $wpdb->posts;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID AS id, post_title AS title FROM {$posts} WHERE post_type = %s AND post_status = %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'wpforms',
				'publish',
				1000
			),
			ARRAY_A
		);

		$forms = array();
		foreach ( is_array( $found ) ? $found : array() as $form ) {
			$count           = get_post_meta( (int) $form['id'], 'wpforms_entries_count', true );
			$form['lifetime'] = '' === $count || false === $count ? null : (int) $count;
			$forms[]         = $form;
		}

		return $forms;
	}

	/**
	 * Grouped rows for one plugin and range.
	 *
	 * @param string $plugin Plugin slug.
	 * @param string $start  Start date.
	 * @param string $end    End date.
	 * @return array[] Each: form_id, day, url, count (and optionally title).
	 */
	private function rows_for( $plugin, $start, $end ) {
		$from = $start . ' 00:00:00';
		$to   = gmdate( 'Y-m-d', strtotime( $end . ' +1 day' ) ) . ' 00:00:00';

		switch ( $plugin ) {
			case self::FLUENTFORM:
				return $this->table_group_rows( 'fluentform_submissions', 'form_id', 'created_at', 'source_url', "status NOT IN ('trashed','spam')", $from, $to );
			case self::GRAVITYFORMS:
				return $this->table_group_rows( 'gf_entry', 'form_id', 'date_created', 'source_url', "status = 'active'", $from, $to );
			case self::FORMIDABLE:
				return $this->formidable_rows( $from, $to );
			case self::CF7:
				return $this->flamingo_rows( $from, $to );
			case self::WPFORMS:
				return $this->wpforms_rows( $start, $end );
		}

		return array();
	}

	/**
	 * GROUP BY form, day and URL on a plugin entries table.
	 *
	 * @param string $table    Table name without prefix.
	 * @param string $form_col Form ID column.
	 * @param string $date_col Date column.
	 * @param string $url_col  Page URL column.
	 * @param string $where    Static extra WHERE clause (no user input).
	 * @param string $from     Range start (inclusive datetime).
	 * @param string $to       Range end (exclusive datetime).
	 * @return array[]
	 */
	private function table_group_rows( $table, $form_col, $date_col, $url_col, $where, $from, $to ) {
		global $wpdb;

		$name = $wpdb->prefix . $table;
		if ( ! $this->table_exists( $name ) ) {
			return array();
		}

		$sql = "SELECT {$form_col} AS form_id, DATE({$date_col}) AS day, {$url_col} AS url, COUNT(*) AS count FROM {$name} WHERE {$date_col} >= %s AND {$date_col} < %s AND {$where} GROUP BY {$form_col}, DATE({$date_col}), {$url_col} LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_results( $wpdb->prepare( $sql, $from, $to, self::ROW_CAP ), ARRAY_A );

		return array_map(
			static function ( $row ) {
				$row['url'] = (string) ( $row['url'] ?? '' );
				return $row;
			},
			is_array( $found ) ? $found : array()
		);
	}

	/**
	 * Formidable entries: the page is the `referrer` inside the entry description JSON.
	 *
	 * Only the referrer key is kept; the rest of the description (browser) is dropped.
	 *
	 * @param string $from Range start.
	 * @param string $to   Range end.
	 * @return array[]
	 */
	private function formidable_rows( $from, $to ) {
		global $wpdb;

		$name = $wpdb->prefix . 'frm_items';
		if ( ! $this->table_exists( $name ) ) {
			return array();
		}

		$sql = "SELECT form_id, DATE(created_at) AS day, description FROM {$name} WHERE created_at >= %s AND created_at < %s AND is_draft = 0 AND parent_item_id = 0 LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_results( $wpdb->prepare( $sql, $from, $to, self::ROW_CAP ), ARRAY_A );
		$found = is_array( $found ) ? $found : array();

		if ( count( $found ) >= self::ROW_CAP ) {
			$this->notes[] = 'Formidable Forms entries were capped at ' . self::ROW_CAP . ' for this period, so counts may be low.';
		}

		$grouped = array();
		foreach ( $found as $row ) {
			$decoded = json_decode( (string) ( $row['description'] ?? '' ), true );
			$url     = is_array( $decoded ) && isset( $decoded['referrer'] ) && is_string( $decoded['referrer'] ) ? $decoded['referrer'] : '';
			$key     = $row['form_id'] . '|' . $row['day'] . '|' . $url;
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'form_id' => $row['form_id'],
					'day'     => $row['day'],
					'url'     => $url,
					'count'   => 0,
				);
			}
			++$grouped[ $key ]['count'];
		}

		return array_values( $grouped );
	}

	/**
	 * Flamingo inbound messages (Contact Form 7), non-spam only.
	 *
	 * The `_meta` post meta also holds the visitor's IP, email and similar
	 * values; only its `url` / `post_id` entries are read.
	 *
	 * @param string $from Range start.
	 * @param string $to   Range end.
	 * @return array[]
	 */
	private function flamingo_rows( $from, $to ) {
		global $wpdb;

		$posts    = $wpdb->posts;
		$postmeta = $wpdb->postmeta;
		$rel      = $wpdb->term_relationships;
		$tax      = $wpdb->term_taxonomy;
		$terms    = $wpdb->terms;

		$sql = "SELECT DATE(p.post_date) AS day, pm.meta_value AS meta, ch.slug AS channel_slug, ch.name AS channel_name
			FROM {$posts} p
			LEFT JOIN {$postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_meta'
			LEFT JOIN (
				SELECT tr.object_id, t.slug, t.name
				FROM {$rel} tr
				INNER JOIN {$tax} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				INNER JOIN {$terms} t ON t.term_id = tt.term_id
			) ch ON ch.object_id = p.ID
			WHERE p.post_type = %s AND p.post_status = %s AND p.post_date >= %s AND p.post_date < %s
			LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_results( $wpdb->prepare( $sql, 'flamingo_inbound_channel', 'flamingo_inbound', 'publish', $from, $to, self::ROW_CAP ), ARRAY_A );
		$found = is_array( $found ) ? $found : array();

		if ( count( $found ) >= self::ROW_CAP ) {
			$this->notes[] = 'Flamingo messages were capped at ' . self::ROW_CAP . ' for this period, so counts may be low.';
		}

		$by_slug = array();
		foreach ( $this->cf7_forms() as $form ) {
			$by_slug[ (string) $form['slug'] ] = $form;
		}

		$grouped = array();
		foreach ( $found as $row ) {
			$slug = (string) ( $row['channel_slug'] ?? '' );
			if ( isset( $by_slug[ $slug ] ) ) {
				$form_id = (string) $by_slug[ $slug ]['id'];
				$title   = (string) $by_slug[ $slug ]['title'];
			} else {
				$form_id = '' === $slug ? 'unknown' : 'channel:' . $slug;
				$title   = '' === $slug ? 'Contact Form 7 (unassigned)' : (string) ( $row['channel_name'] ?? $slug );
			}

			$url = $this->flamingo_url( (string) ( $row['meta'] ?? '' ) );
			$key = $form_id . '|' . $row['day'] . '|' . $url;
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'form_id' => $form_id,
					'title'   => $title,
					'day'     => $row['day'],
					'url'     => $url,
					'count'   => 0,
				);
			}
			++$grouped[ $key ]['count'];
		}

		return array_values( $grouped );
	}

	/**
	 * Page URL from a Flamingo `_meta` value; nothing else is kept.
	 *
	 * @param string $raw Raw serialized `_meta` meta value.
	 * @return string URL or ''.
	 */
	private function flamingo_url( $raw ) {
		if ( '' === $raw || ! is_serialized( $raw ) ) {
			return '';
		}

		// No objects: the value is only ever a plain array of strings.
		$meta = unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( ! is_array( $meta ) ) {
			return '';
		}

		if ( ! empty( $meta['url'] ) && is_string( $meta['url'] ) ) {
			return $meta['url'];
		}

		if ( ! empty( $meta['post_id'] ) && is_numeric( $meta['post_id'] ) ) {
			$link = get_permalink( (int) $meta['post_id'] );
			return is_string( $link ) ? $link : '';
		}

		return '';
	}

	/**
	 * WPForms: the Pro entries table when present, otherwise Lite's rolling site-wide window.
	 *
	 * @param string $start Start date.
	 * @param string $end   End date.
	 * @return array[]
	 */
	private function wpforms_rows( $start, $end ) {
		global $wpdb;

		$name = $wpdb->prefix . 'wpforms_entries';
		if ( $this->table_exists( $name ) ) {
			$from = $start . ' 00:00:00';
			$to   = gmdate( 'Y-m-d', strtotime( $end . ' +1 day' ) ) . ' 00:00:00';
			$sql  = "SELECT form_id, DATE(date) AS day, COUNT(*) AS count FROM {$name} WHERE date >= %s AND date < %s GROUP BY form_id, DATE(date) LIMIT %d";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$found = $wpdb->get_results( $wpdb->prepare( $sql, $from, $to, self::ROW_CAP ), ARRAY_A );

			$this->notes[] = 'WPForms entries are counted per form and day only; WPForms does not store the submission page.';

			return array_map(
				static function ( $row ) {
					$row['url'] = '';
					return $row;
				},
				is_array( $found ) ? $found : array()
			);
		}

		$window = get_option( 'wpforms_entries_count_daily' );
		$days   = is_array( $window ) && isset( $window['days'] ) && is_array( $window['days'] ) ? $window['days'] : array();
		$rows   = array();

		foreach ( $days as $day => $count ) {
			if ( $day >= $start && $day <= $end && (int) $count > 0 ) {
				$rows[] = array(
					'form_id' => '0',
					'title'   => 'All WPForms forms (WPForms Lite, site-wide)',
					'day'     => $day,
					'url'     => '',
					'count'   => (int) $count,
				);
			}
		}

		$this->notes[] = 'WPForms Lite does not store entries. Only a site-wide daily total for the last 30 days and a lifetime count per form are available, with no per-form days or submission pages.';

		return $rows;
	}
}
