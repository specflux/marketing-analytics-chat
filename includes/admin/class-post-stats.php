<?php
/**
 * Per-post analytics column for the Posts and Pages list screens
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Admin;

use Specflux_Marketing_Analytics\API_Clients\GA4_Client;
use Specflux_Marketing_Analytics\API_Clients\GSC_Client;
use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Utils\Logger;
use Specflux_Marketing_Analytics\Utils\Permission_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adds an "Analytics (28d)" column showing GA4 views and Search Console clicks.
 *
 * Google is queried at most once per platform to build a path-keyed map that
 * is cached in a transient. Rendering a row never touches an API: when the
 * cache is cold the cells show a placeholder and a small script asks
 * wp-admin/admin-ajax.php to build the cache once, then fills the cells in.
 */
class Post_Stats {

	/**
	 * Transient holding the combined GA4 + GSC maps.
	 */
	const TRANSIENT = 'specflux_mac_post_stats';

	/**
	 * Nonce action for the cache-building AJAX request.
	 */
	const NONCE_ACTION = 'specflux_mac_post_stats';

	/**
	 * AJAX action name.
	 */
	const AJAX_ACTION = 'specflux_mac_post_stats_build';

	/**
	 * Column key.
	 */
	const COLUMN = 'specflux_mac_analytics';

	/**
	 * Cache lifetime for a successful build.
	 */
	const TTL_OK = 12 * HOUR_IN_SECONDS;

	/**
	 * Cache lifetime when an API failed.
	 */
	const TTL_ERROR = HOUR_IN_SECONDS;

	/**
	 * Maximum number of post IDs served per AJAX request.
	 */
	const MAX_IDS = 500;

	/**
	 * Credential manager.
	 *
	 * @var Credential_Manager
	 */
	private $credentials;

	/**
	 * Constructor.
	 *
	 * @param Credential_Manager|null $credentials Optional credential manager.
	 */
	public function __construct( $credentials = null ) {
		$this->credentials = $credentials ? $credentials : new Credential_Manager();
	}

	/**
	 * Hook the column into every eligible post type's list table.
	 *
	 * @return void
	 */
	public function register_columns() {
		foreach ( $this->get_post_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * Post types that get the column.
	 *
	 * @return string[] Post type slugs.
	 */
	public function get_post_types() {
		$types = array_values(
			get_post_types(
				array(
					'public'  => true,
					'show_ui' => true,
				)
			)
		);

		// Attachments are public with a UI but have no analytics worth showing.
		$types = array_values( array_diff( $types, array( 'attachment' ) ) );

		/**
		 * Filters the post types that show the analytics column.
		 *
		 * @param string[] $types Post type slugs.
		 */
		$types = apply_filters( 'specflux_mac_post_stats_post_types', $types );

		return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ) ) ) : array();
	}

	/**
	 * Platforms that are connected and relevant here.
	 *
	 * @return string[] Subset of array( 'ga4', 'gsc' ).
	 */
	public function get_connected_platforms() {
		$connected = array();
		foreach ( array( 'ga4', 'gsc' ) as $platform ) {
			if ( $this->credentials->has_credentials( $platform ) ) {
				$connected[] = $platform;
			}
		}
		return $connected;
	}

	/**
	 * Whether the current user should see the column at all.
	 *
	 * @return bool
	 */
	public function is_available() {
		return Permission_Manager::can_access_plugin() && ! empty( $this->get_connected_platforms() );
	}

	/**
	 * Filter callback: add the column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		if ( ! $this->is_available() ) {
			return $columns;
		}

		$label = __( 'Analytics (28d)', 'specflux-marketing-analytics-chat' );
		$new   = array();
		$added = false;
		foreach ( $columns as $key => $value ) {
			if ( 'date' === $key ) {
				$new[ self::COLUMN ] = $label;
				$added               = true;
			}
			$new[ $key ] = $value;
		}
		if ( ! $added ) {
			$new[ self::COLUMN ] = $label;
		}
		return $new;
	}

	/**
	 * Action callback: render a cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column || ! $this->is_available() ) {
			return;
		}

		echo $this->get_cell_html( (int) $post_id, $this->get_cached() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_cell_html().
	}

	/**
	 * Enqueue the stylesheet (and the loader script when the cache is cold).
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'edit.php' !== $hook || ! $this->is_available() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->post_type ) || ! in_array( $screen->post_type, $this->get_post_types(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'specflux-mac-post-stats',
			SPECFLUX_MAC_URL . 'admin/css/post-stats.css',
			array(),
			SPECFLUX_MAC_VERSION
		);

		if ( null !== $this->get_cached() ) {
			return;
		}

		wp_enqueue_script(
			'specflux-mac-post-stats',
			SPECFLUX_MAC_URL . 'admin/js/post-stats.js',
			array( 'jquery' ),
			SPECFLUX_MAC_VERSION,
			true
		);

		wp_localize_script(
			'specflux-mac-post-stats',
			'specfluxMacPostStats',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => self::AJAX_ACTION,
				'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
				'errorTitle' => $this->get_error_title(),
			)
		);
	}

	/**
	 * AJAX: build the cache (if needed) and return cell HTML keyed by post ID.
	 *
	 * @return void
	 */
	public function handle_ajax() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed. Please refresh the page and try again.' ) );
			return;
		}

		if ( ! Permission_Manager::can_access_plugin() ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to perform this action.' ) );
			return;
		}

		if ( empty( $this->get_connected_platforms() ) ) {
			wp_send_json_error( array( 'message' => 'No analytics platform is connected.' ) );
			return;
		}

		$ids = array();
		if ( isset( $_POST['post_ids'] ) ) {
			$raw = is_array( $_POST['post_ids'] ) ? wp_unslash( $_POST['post_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast with absint() below.
			$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) ), 0, self::MAX_IDS );
		}

		$cache = $this->get_cached();
		if ( null === $cache ) {
			$cache = $this->build_cache();
		}

		$cells = array();
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$cells[ $id ] = $this->get_cell_html( $id, $cache );
		}

		wp_send_json_success( array( 'cells' => $cells ) );
	}

	/**
	 * Read the cache, treating it as missing if the connected platforms changed.
	 *
	 * @return array|null Cache payload or null when cold.
	 */
	public function get_cached() {
		$cache = get_transient( self::TRANSIENT );
		if ( ! is_array( $cache ) || ! isset( $cache['platforms'], $cache['ga4'], $cache['gsc'] ) ) {
			return null;
		}
		if ( $cache['platforms'] !== $this->get_connected_platforms() ) {
			return null;
		}
		return $cache;
	}

	/**
	 * Query Google (one call per connected platform) and cache the result.
	 *
	 * Never throws: failures are recorded per platform and cached briefly.
	 *
	 * @return array Cache payload.
	 */
	public function build_cache() {
		$platforms = $this->get_connected_platforms();
		$cache     = array(
			'platforms' => $platforms,
			'ga4'       => array(),
			'gsc'       => array(),
			'errors'    => array(),
		);

		if ( in_array( 'ga4', $platforms, true ) ) {
			try {
				$report       = $this->make_ga4_client()->run_report(
					array( 'screenPageViews' ),
					array( 'pagePath' ),
					'28daysAgo',
					array(
						'limit'           => 2000,
						'order_by_metric' => 'screenPageViews',
					)
				);
				$cache['ga4'] = self::build_ga4_map( $report );
			} catch ( \Throwable $e ) {
				$cache['errors']['ga4'] = true;
				Logger::error( 'Post stats GA4 fetch failed: ' . $e->getMessage() );
			}
		}

		if ( in_array( 'gsc', $platforms, true ) ) {
			try {
				// 28 days ending 3 days ago to allow for Search Console's reporting lag.
				$range        = gmdate( 'Y-m-d', strtotime( '-30 days' ) ) . ',' . gmdate( 'Y-m-d', strtotime( '-3 days' ) );
				$data         = $this->make_gsc_client()->query_search_analytics(
					$range,
					array( 'page' ),
					array(),
					array( 'row_limit' => 5000 )
				);
				$cache['gsc'] = self::build_gsc_map( $data );
			} catch ( \Throwable $e ) {
				$cache['errors']['gsc'] = true;
				Logger::error( 'Post stats GSC fetch failed: ' . $e->getMessage() );
			}
		}

		set_transient( self::TRANSIENT, $cache, empty( $cache['errors'] ) ? self::TTL_OK : self::TTL_ERROR );

		return $cache;
	}

	/**
	 * Create the GA4 client (overridable in tests).
	 *
	 * @return GA4_Client
	 */
	protected function make_ga4_client() {
		return new GA4_Client();
	}

	/**
	 * Create the GSC client (overridable in tests).
	 *
	 * @return GSC_Client
	 */
	protected function make_gsc_client() {
		return new GSC_Client();
	}

	/**
	 * Normalise a URL or path to a lowercase, query-free path with no trailing slash.
	 *
	 * @param string $url Full URL or path.
	 * @return string|null Normalised path ("/" for the home page) or null if unusable.
	 */
	public static function normalize_path( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return null;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( null === $path || false === $path ) {
			// A bare host such as https://example.com has no path component.
			$path = preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ? '/' : '';
		}

		$path = rawurldecode( (string) $path );
		if ( '' === $path || '/' !== $path[0] ) {
			return null;
		}

		$path = strtolower( $path );
		$path = rtrim( $path, '/' );

		return '' === $path ? '/' : $path;
	}

	/**
	 * Build a path => views map from a GA4 run_report() result.
	 *
	 * @param array|null $report Result of GA4_Client::run_report().
	 * @return array<string,int>
	 */
	public static function build_ga4_map( $report ) {
		$map = array();
		if ( ! is_array( $report ) || empty( $report['rows'] ) ) {
			return $map;
		}

		foreach ( $report['rows'] as $row ) {
			if ( ! isset( $row['pagePath'] ) ) {
				continue;
			}
			$path = self::normalize_path( $row['pagePath'] );
			if ( null === $path ) {
				continue;
			}
			$map[ $path ] = ( $map[ $path ] ?? 0 ) + (int) ( $row['screenPageViews'] ?? 0 );
		}

		return $map;
	}

	/**
	 * Build a path => [clicks, impressions, position] map from a GSC result.
	 *
	 * Rows that normalise to the same path (http/https, www, trailing slash
	 * variants) are merged; position is the impression-weighted average.
	 *
	 * @param array|null $data Result of GSC_Client::query_search_analytics().
	 * @return array<string,array{0:int,1:int,2:float}>
	 */
	public static function build_gsc_map( $data ) {
		$acc = array();
		if ( ! is_array( $data ) || empty( $data['rows'] ) ) {
			return array();
		}

		foreach ( $data['rows'] as $row ) {
			$key = $row['key'] ?? ( $row['keys'][0] ?? null );
			if ( null === $key ) {
				continue;
			}
			$path = self::normalize_path( $key );
			if ( null === $path ) {
				continue;
			}
			$clicks      = (float) ( $row['clicks'] ?? 0 );
			$impressions = (float) ( $row['impressions'] ?? 0 );
			$position    = (float) ( $row['position'] ?? 0 );

			if ( ! isset( $acc[ $path ] ) ) {
				$acc[ $path ] = array( 0.0, 0.0, 0.0 );
			}
			$acc[ $path ][0] += $clicks;
			$acc[ $path ][1] += $impressions;
			$acc[ $path ][2] += $position * $impressions;
		}

		$map = array();
		foreach ( $acc as $path => $sums ) {
			$map[ $path ] = array(
				(int) round( $sums[0] ),
				(int) round( $sums[1] ),
				$sums[1] > 0 ? round( $sums[2] / $sums[1], 1 ) : 0.0,
			);
		}

		return $map;
	}

	/**
	 * Normalised path for a post, or null when it has no stable public URL.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	public function get_post_path( $post_id ) {
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return null;
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return null;
		}

		// Plain permalinks (?p=123) differ only by query string, which is stripped.
		if ( false !== strpos( $permalink, '?' ) ) {
			return null;
		}

		return self::normalize_path( wp_make_link_relative( $permalink ) );
	}

	/**
	 * Title attribute used when data could not be loaded.
	 *
	 * @return string
	 */
	private function get_error_title() {
		return __( 'Analytics could not be loaded. Check your connections; this will retry within the hour.', 'specflux-marketing-analytics-chat' );
	}

	/**
	 * Build the escaped cell markup for one post.
	 *
	 * @param int        $post_id Post ID.
	 * @param array|null $cache   Cache payload, or null when cold.
	 * @return string Escaped HTML.
	 */
	public function get_cell_html( $post_id, $cache ) {
		if ( null === $cache ) {
			return '<span class="smac-post-stats smac-post-stats--loading" data-post-id="' . esc_attr( (string) $post_id ) . '">'
				. '<span aria-hidden="true">&mdash;</span> '
				. '<span class="smac-post-stats__loading">' . esc_html__( 'Loading…', 'specflux-marketing-analytics-chat' ) . '</span>'
				. '</span>';
		}

		$path = $this->get_post_path( $post_id );
		if ( null === $path ) {
			return '<span class="smac-post-stats" data-post-id="' . esc_attr( (string) $post_id ) . '"><span aria-hidden="true">&mdash;</span></span>';
		}

		$errors    = isset( $cache['errors'] ) ? (array) $cache['errors'] : array();
		$platforms = isset( $cache['platforms'] ) ? (array) $cache['platforms'] : array();
		$parts     = array();
		$position  = null;
		$failed    = 0;

		if ( in_array( 'ga4', $platforms, true ) ) {
			if ( ! empty( $errors['ga4'] ) ) {
				++$failed;
			} else {
				$views = (int) ( $cache['ga4'][ $path ] ?? 0 );
				/* translators: %s: formatted number of page views. */
				$parts[] = sprintf( _n( '%s view', '%s views', $views, 'specflux-marketing-analytics-chat' ), number_format_i18n( $views ) );
			}
		}

		if ( in_array( 'gsc', $platforms, true ) ) {
			if ( ! empty( $errors['gsc'] ) ) {
				++$failed;
			} else {
				$row    = $cache['gsc'][ $path ] ?? array( 0, 0, 0.0 );
				$clicks = (int) $row[0];
				/* translators: %s: formatted number of search clicks. */
				$parts[] = sprintf( _n( '%s click', '%s clicks', $clicks, 'specflux-marketing-analytics-chat' ), number_format_i18n( $clicks ) );
				if ( (float) $row[2] > 0 ) {
					$position = (float) $row[2];
				}
			}
		}

		if ( empty( $parts ) ) {
			return '<span class="smac-post-stats smac-post-stats--error" data-post-id="' . esc_attr( (string) $post_id ) . '" title="' . esc_attr( $this->get_error_title() ) . '"><span aria-hidden="true">&mdash;</span></span>';
		}

		$html = '<span class="smac-post-stats__line">' . esc_html( implode( ' · ', $parts ) ) . '</span>';
		if ( null !== $position ) {
			/* translators: %s: average Google search position, e.g. 12.3. */
			$html .= '<span class="smac-post-stats__line smac-post-stats__pos">' . esc_html( sprintf( __( 'avg pos %s', 'specflux-marketing-analytics-chat' ), number_format_i18n( $position, 1 ) ) ) . '</span>';
		}

		$title_attr = $failed > 0 ? ' title="' . esc_attr( $this->get_error_title() ) . '"' : '';

		return '<a class="smac-post-stats" data-post-id="' . esc_attr( (string) $post_id ) . '" href="' . esc_url( $this->get_chat_url( $post_id ) ) . '"' . $title_attr . '>' . $html . '</a>';
	}

	/**
	 * AI Assistant URL with a prefilled question about the post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_chat_url( $post_id ) {
		/* translators: %s: post title. */
		$prompt = sprintf( __( 'How has "%s" performed over the last 28 days, and what should I improve?', 'specflux-marketing-analytics-chat' ), wp_strip_all_tags( get_the_title( $post_id ) ) );

		return admin_url( 'admin.php?page=specflux-mac-ai-assistant&prompt=' . rawurlencode( $prompt ) );
	}
}
