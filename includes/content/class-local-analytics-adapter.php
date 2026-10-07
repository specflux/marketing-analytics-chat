<?php
/**
 * Local Analytics Adapter
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Table names are built only from $wpdb->prefix plus fixed strings; every value goes through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * Base adapter: one analytics plugin's storage.
 */
abstract class Local_Analytics_Adapter {

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	protected $db;

	/**
	 * Memoised table existence.
	 *
	 * @var array<string,bool>
	 */
	private $tables = array();

	/**
	 * Constructor.
	 *
	 * @param \wpdb|null $db Optional database handle.
	 */
	public function __construct( $db = null ) {
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->db = $db;
	}

	/**
	 * Plugin slug used in responses.
	 *
	 * @return string
	 */
	abstract public function slug();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Constant that proves the plugin is active.
	 *
	 * @return string
	 */
	abstract protected function marker_constant();

	/**
	 * Whether this adapter can read data.
	 *
	 * @return bool
	 */
	public function is_supported() {
		return true;
	}

	/**
	 * Why the plugin cannot be read, when unsupported.
	 *
	 * @return string
	 */
	public function unsupported_reason() {
		return '';
	}

	/**
	 * Whether the plugin is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->has_constant( $this->marker_constant() );
	}

	/**
	 * Whether the plugin's tables hold at least one recorded hit.
	 *
	 * @return bool
	 */
	abstract public function has_data();

	/**
	 * Totals for a period.
	 *
	 * @param array $range Range from Local_Analytics_Reader::build_range().
	 * @return array{visitors:int,pageviews:int,sessions:int|null,visitors_basis:string}
	 */
	abstract public function totals( array $range );

	/**
	 * Per-day rows (only days that have data).
	 *
	 * @param array $range Range.
	 * @return array<int,array{date:string,visitors:int,pageviews:int,sessions:int|null}>
	 */
	abstract public function daily( array $range );

	/**
	 * Top pages.
	 *
	 * @param array $range Range.
	 * @param int   $limit Row limit.
	 * @return array<int,array{path:string,pageviews:int,visitors:int}>
	 */
	abstract public function top_pages( array $range, $limit );

	/**
	 * Top referrers / sources.
	 *
	 * @param array $range Range.
	 * @param int   $limit Row limit.
	 * @return array<int,array{source:string,pageviews:int|null,visitors:int|null,sessions:int|null}>
	 */
	abstract public function referrers( array $range, $limit );

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
	 * Whether a table exists (names are given without the prefix).
	 *
	 * @param string $name Table name without prefix.
	 * @return bool
	 */
	protected function table_exists( $name ) {
		if ( ! isset( $this->tables[ $name ] ) ) {
			$table                  = $this->db->prefix . $name;
			$found                  = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) ) );
			$this->tables[ $name ] = ( $found === $table );
		}

		return $this->tables[ $name ];
	}

	/**
	 * Whether every named table exists.
	 *
	 * @param string[] $names Table names without prefix.
	 * @return bool
	 */
	protected function tables_exist( array $names ) {
		foreach ( $names as $name ) {
			if ( ! $this->table_exists( $name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Rows from a prepared query, or an empty array on error.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array
	 */
	protected function rows( $sql ) {
		$rows = $this->db->get_results( $sql );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Reduce a referrer URL or host to a bare host (no scheme, www or path).
	 *
	 * @param string $value Raw referrer.
	 * @return string
	 */
	protected function normalize_source( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$host = wp_parse_url( ( false === strpos( $value, '://' ) ? 'http://' : '' ) . $value, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return $value;
		}

		return (string) preg_replace( '/^www\./i', '', strtolower( $host ) );
	}

	/**
	 * Merge rows that share a normalised source, sort and cut.
	 *
	 * @param array $rows  Objects with source, pageviews, visitors, sessions.
	 * @param int   $limit Limit.
	 * @return array
	 */
	protected function merge_sources( array $rows, $limit ) {
		$merged = array();
		foreach ( $rows as $row ) {
			$source = $this->normalize_source( $row['source'] );
			if ( '' === $source ) {
				continue;
			}
			if ( ! isset( $merged[ $source ] ) ) {
				$merged[ $source ] = array(
					'source'    => $source,
					'pageviews' => null,
					'visitors'  => null,
					'sessions'  => null,
				);
			}
			foreach ( array( 'pageviews', 'visitors', 'sessions' ) as $key ) {
				if ( null !== $row[ $key ] ) {
					$merged[ $source ][ $key ] = (int) $merged[ $source ][ $key ] + (int) $row[ $key ];
				}
			}
		}
		$merged = array_values( $merged );
		usort(
			$merged,
			static function ( $a, $b ) {
				$left  = (int) ( null !== $a['pageviews'] ? $a['pageviews'] : $a['sessions'] );
				$right = (int) ( null !== $b['pageviews'] ? $b['pageviews'] : $b['sessions'] );
				return $right <=> $left;
			}
		);

		return array_slice( $merged, 0, max( 1, (int) $limit ) );
	}
}
