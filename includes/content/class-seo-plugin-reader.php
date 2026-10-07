<?php
/**
 * SEO Plugin Reader
 *
 * Detects the active SEO plugin and reads its per-post fields (title, meta
 * description, focus keyword, score, noindex, canonical).
 *
 * Storage locations were checked against the plugin sources on wordpress.org:
 * - Yoast SEO:      post meta `_yoast_wpseo_*` (inc/class-wpseo-meta.php).
 * - Rank Math:      post meta `rank_math_*` (includes/admin/class-post-columns.php, importers).
 * - All in One SEO: table `{prefix}aioseo_posts` (app/Common/Models/Post.php).
 * - SEOPress:       post meta `_seopress_*` (seopress-functions.php, migrators).
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reads SEO plugin data for posts.
 */
class Seo_Plugin_Reader {

	const YOAST    = 'yoast';
	const RANKMATH = 'rankmath';
	const AIOSEO   = 'aioseo';
	const SEOPRESS = 'seopress';

	/**
	 * Memoised result of table_exists() per table name.
	 *
	 * @var array<string,bool>
	 */
	private $tables = array();

	/**
	 * Storage map for every supported plugin.
	 *
	 * Each field is array( 'type' => 'meta', 'key' => ... ) for post meta or
	 * array( 'type' => 'table', 'table' => ..., 'column' => ..., 'id_column' => ... )
	 * for a custom table (table is without the $wpdb->prefix). A `null` field means
	 * the plugin does not store it. This is the contract a write-back add-on reuses.
	 *
	 * @return array<string,array<string,mixed>> Map of plugin slug => definition.
	 */
	public function get_storage_map() {
		$table = static function ( $column ) {
			return array(
				'type'      => 'table',
				'table'     => 'aioseo_posts',
				'column'    => $column,
				'id_column' => 'post_id',
			);
		};
		$meta  = static function ( $key ) {
			return array(
				'type' => 'meta',
				'key'  => $key,
			);
		};

		return array(
			self::YOAST    => array(
				'label'         => 'Yoast SEO',
				'title'         => $meta( '_yoast_wpseo_title' ),
				'description'   => $meta( '_yoast_wpseo_metadesc' ),
				'focus_keyword' => $meta( '_yoast_wpseo_focuskw' ),
				'seo_score'     => $meta( '_yoast_wpseo_linkdex' ),
				'noindex'       => $meta( '_yoast_wpseo_meta-robots-noindex' ),
				'canonical'     => $meta( '_yoast_wpseo_canonical' ),
			),
			self::RANKMATH => array(
				'label'         => 'Rank Math SEO',
				'title'         => $meta( 'rank_math_title' ),
				'description'   => $meta( 'rank_math_description' ),
				'focus_keyword' => $meta( 'rank_math_focus_keyword' ),
				'seo_score'     => $meta( 'rank_math_seo_score' ),
				'noindex'       => $meta( 'rank_math_robots' ),
				'canonical'     => $meta( 'rank_math_canonical_url' ),
			),
			self::AIOSEO   => array(
				'label'         => 'All in One SEO',
				'title'         => $table( 'title' ),
				'description'   => $table( 'description' ),
				'focus_keyword' => $table( 'focus_keyword' ),
				'seo_score'     => $table( 'seo_score' ),
				'noindex'       => $table( 'robots_noindex' ),
				'canonical'     => $table( 'canonical_url' ),
			),
			self::SEOPRESS => array(
				'label'         => 'SEOPress',
				'title'         => $meta( '_seopress_titles_title' ),
				'description'   => $meta( '_seopress_titles_desc' ),
				'focus_keyword' => $meta( '_seopress_analysis_target_kw' ),
				'seo_score'     => null, // Not stored as a post field.
				'noindex'       => $meta( '_seopress_robots_index' ),
				'canonical'     => $meta( '_seopress_robots_canonical' ),
			),
		);
	}

	/**
	 * Detect the active SEO plugin.
	 *
	 * @return string Plugin slug, or '' when none of the supported plugins is active.
	 */
	public function detect() {
		if ( $this->has_constant( 'WPSEO_VERSION' ) ) {
			return self::YOAST;
		}
		if ( $this->has_constant( 'RANK_MATH_VERSION' ) ) {
			return self::RANKMATH;
		}
		if ( $this->has_constant( 'AIOSEO_VERSION' ) || $this->has_function( 'aioseo' ) ) {
			return self::AIOSEO;
		}
		if ( $this->has_constant( 'SEOPRESS_VERSION' ) ) {
			return self::SEOPRESS;
		}

		return '';
	}

	/**
	 * Whether a constant is defined (overridable in tests, since constants cannot be undefined).
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	protected function has_constant( $name ) {
		return defined( $name );
	}

	/**
	 * Whether a function exists (overridable in tests).
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	protected function has_function( $name ) {
		return function_exists( $name );
	}

	/**
	 * Human-readable name of the detected plugin.
	 *
	 * @param string|null $plugin Plugin slug; detected when null.
	 * @return string Name, or '' when no plugin is active.
	 */
	public function get_label( $plugin = null ) {
		$plugin = null === $plugin ? $this->detect() : $plugin;
		$map    = $this->get_storage_map();

		return isset( $map[ $plugin ] ) ? $map[ $plugin ]['label'] : '';
	}

	/**
	 * Whether a SEO title or description contains plugin template variables.
	 *
	 * Covers %%title%% (Yoast, SEOPress), %title% (Rank Math) and #post_title (All in One SEO).
	 *
	 * @param string $value Raw title or description.
	 * @return bool
	 */
	public static function has_template( $value ) {
		return 1 === preg_match( '/%%[^%\s]+%%|%[a-z][a-z0-9_]*%|#[a-z]+(?:_[a-z]+)+|#(?:title|tagline|excerpt|sitename|sep)\b/i', (string) $value );
	}

	/**
	 * Read the SEO fields for one post.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $plugin  Plugin slug; detected when null.
	 * @return array Normalised fields; `plugin` is '' when no SEO plugin is active.
	 */
	public function read( $post_id, $plugin = null ) {
		$result = $this->read_many( array( $post_id ), $plugin );

		return $result[ (int) $post_id ];
	}

	/**
	 * Read the SEO fields for several posts (one query for table-based plugins).
	 *
	 * @param int[]       $post_ids Post IDs.
	 * @param string|null $plugin   Plugin slug; detected when null.
	 * @return array<int,array> Map of post ID => normalised fields.
	 */
	public function read_many( array $post_ids, $plugin = null ) {
		$plugin   = null === $plugin ? $this->detect() : $plugin;
		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		$out      = array();

		if ( '' === $plugin || ! isset( $this->get_storage_map()[ $plugin ] ) ) {
			foreach ( $post_ids as $post_id ) {
				$out[ $post_id ] = $this->normalise( '', array(), $post_id );
			}
			return $out;
		}

		$rows = array();
		if ( self::AIOSEO === $plugin ) {
			$rows = $this->fetch_aioseo_rows( $post_ids );
		} elseif ( function_exists( 'update_meta_cache' ) && $post_ids ) {
			update_meta_cache( 'post', $post_ids );
		}

		$map = $this->get_storage_map()[ $plugin ];
		foreach ( $post_ids as $post_id ) {
			$raw = array();
			foreach ( array( 'title', 'description', 'focus_keyword', 'seo_score', 'noindex', 'canonical' ) as $field ) {
				$location = $map[ $field ];
				if ( null === $location ) {
					$raw[ $field ] = null;
				} elseif ( 'meta' === $location['type'] ) {
					$raw[ $field ] = get_post_meta( $post_id, $location['key'], true );
				} else {
					$raw[ $field ] = isset( $rows[ $post_id ] ) && isset( $rows[ $post_id ]->{$location['column']} ) ? $rows[ $post_id ]->{$location['column']} : null;
				}
			}

			if ( self::AIOSEO === $plugin && isset( $rows[ $post_id ] ) ) {
				$raw['aioseo_row'] = $rows[ $post_id ];
			}

			$out[ $post_id ] = $this->normalise( $plugin, $raw, $post_id );
		}

		return $out;
	}

	/**
	 * Turn raw stored values into the normalised shape.
	 *
	 * @param string $plugin  Plugin slug or ''.
	 * @param array  $raw     Raw values keyed by field.
	 * @param int    $post_id Post ID.
	 * @return array
	 */
	private function normalise( $plugin, array $raw, $post_id ) {
		$title       = isset( $raw['title'] ) ? trim( (string) $raw['title'] ) : '';
		$description = isset( $raw['description'] ) ? trim( (string) $raw['description'] ) : '';
		$keywords    = $this->parse_keywords( $plugin, $raw );
		$score       = isset( $raw['seo_score'] ) && is_numeric( $raw['seo_score'] ) && (int) $raw['seo_score'] > 0 ? (int) $raw['seo_score'] : null;
		$canonical   = isset( $raw['canonical'] ) && is_string( $raw['canonical'] ) ? trim( $raw['canonical'] ) : '';

		return array(
			'post_id'                => (int) $post_id,
			'plugin'                 => $plugin,
			'title'                  => $title,
			'title_source'           => '' === $title ? 'default_template' : 'custom',
			'description'            => $description,
			'description_source'     => '' === $description ? 'default_template' : 'custom',
			'uses_template'          => self::has_template( $title ) || self::has_template( $description ),
			'focus_keywords'         => $keywords,
			'focus_keyword'          => $keywords ? $keywords[0] : '',
			'seo_score'              => $score,
			'noindex'                => $this->parse_noindex( $plugin, $raw, $post_id ),
			'canonical'              => $canonical,
			'empty_fallback_message' => '' === $title || '' === $description ? 'An empty title or description means the SEO plugin falls back to its default template for this post.' : '',
		);
	}

	/**
	 * Parse the focus keyword(s).
	 *
	 * @param string $plugin Plugin slug.
	 * @param array  $raw    Raw values.
	 * @return string[] Primary keyword first.
	 */
	private function parse_keywords( $plugin, array $raw ) {
		$value = isset( $raw['focus_keyword'] ) && is_scalar( $raw['focus_keyword'] ) ? trim( (string) $raw['focus_keyword'] ) : '';

		if ( self::AIOSEO === $plugin && '' === $value && isset( $raw['aioseo_row']->keyphrases ) ) {
			// Older rows only hold the keyword in the `keyphrases` JSON (focus.keyphrase).
			$json = json_decode( (string) $raw['aioseo_row']->keyphrases, true );
			if ( is_array( $json ) && isset( $json['focus']['keyphrase'] ) && is_scalar( $json['focus']['keyphrase'] ) ) {
				$value = trim( (string) $json['focus']['keyphrase'] );
			}
		}

		if ( '' === $value ) {
			return array();
		}

		// Rank Math and SEOPress store a comma-separated list; Yoast and AIOSEO a single phrase.
		if ( in_array( $plugin, array( self::RANKMATH, self::SEOPRESS ), true ) ) {
			$parts = array_map( 'trim', explode( ',', $value ) );
		} else {
			$parts = array( $value );
		}

		return array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Parse the noindex flag.
	 *
	 * @param string $plugin  Plugin slug.
	 * @param array  $raw     Raw values.
	 * @param int    $post_id Post ID.
	 * @return bool|null True/false, or null when the post follows the plugin's site-wide default.
	 */
	private function parse_noindex( $plugin, array $raw, $post_id ) {
		$value = $raw['noindex'] ?? null;

		switch ( $plugin ) {
			case self::YOAST:
				// '1' = noindex, '2' = index, '0'/'' = post type default.
				if ( '1' === (string) $value ) {
					return true;
				}
				if ( '2' === (string) $value ) {
					return false;
				}
				$titles    = get_option( 'wpseo_titles', array() );
				$post_type = function_exists( 'get_post_type' ) ? get_post_type( $post_id ) : false;
				if ( is_array( $titles ) && $post_type && isset( $titles[ 'noindex-' . $post_type ] ) ) {
					return (bool) $titles[ 'noindex-' . $post_type ];
				}
				return null;

			case self::RANKMATH:
				// Serialized array of directives, e.g. array( 'index', 'noindex' ).
				if ( is_array( $value ) ) {
					return in_array( 'noindex', $value, true );
				}
				return null;

			case self::AIOSEO:
				if ( ! isset( $raw['aioseo_row'] ) ) {
					return null;
				}
				// robots_default = follow the global default; the per-post flag is ignored.
				if ( ! empty( $raw['aioseo_row']->robots_default ) ) {
					return null;
				}
				return ! empty( $raw['aioseo_row']->robots_noindex );

			case self::SEOPRESS:
				return 'yes' === $value;
		}

		return null;
	}

	/**
	 * Fetch aioseo_posts rows keyed by post ID.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int,object>
	 */
	private function fetch_aioseo_rows( array $post_ids ) {
		global $wpdb;

		if ( empty( $post_ids ) || ! $this->table_exists( $wpdb->prefix . 'aioseo_posts' ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
		$table        = $wpdb->prefix . 'aioseo_posts';
		// SELECT * because older versions lack the focus_keyword column.
		$sql  = $wpdb->prepare( "SELECT * FROM `{$table}` WHERE post_id IN ({$placeholders})", $post_ids ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$by_id = array();
		foreach ( (array) $rows as $row ) {
			$by_id[ (int) $row->post_id ] = $row;
		}

		return $by_id;
	}

	/**
	 * Whether a database table exists.
	 *
	 * @param string $table Full table name including prefix.
	 * @return bool
	 */
	public function table_exists( $table ) {
		global $wpdb;

		if ( ! isset( $this->tables[ $table ] ) ) {
			$found                  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->tables[ $table ] = ( $found === $table );
		}

		return $this->tables[ $table ];
	}

	/**
	 * Most-hit URLs from the Rank Math 404 monitor.
	 *
	 * @param int $limit Maximum rows.
	 * @return array|null Rows of uri/hits/last_accessed, or null when Rank Math's table does not exist.
	 */
	public function get_rank_math_404s( $limit = 10 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_404_logs';
		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT uri, times_accessed, accessed FROM `{$table}` ORDER BY times_accessed DESC LIMIT %d", max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'uri'           => (string) $row->uri,
				'hits'          => (int) $row->times_accessed,
				'last_accessed' => (string) $row->accessed,
			);
		}

		return $out;
	}
}
