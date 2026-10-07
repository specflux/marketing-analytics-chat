<?php
/**
 * Content Inventory
 *
 * Lists the site's published, public content in a normalised shape. Never
 * returns drafts, private or password-protected posts, or author emails.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Content;

use Specflux_Marketing_Analytics\Admin\Post_Stats;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Queries published content.
 */
class Content_Inventory {

	const DEFAULT_LIMIT = 25;
	const MAX_LIMIT     = 100;

	/**
	 * Public post types that can be listed (attachments excluded).
	 *
	 * @return string[]
	 */
	public function get_post_types() {
		$types = array_values( array_diff( array_values( get_post_types( array( 'public' => true ) ) ), array( 'attachment' ) ) );

		return $types;
	}

	/**
	 * Normalise a limit argument.
	 *
	 * @param mixed $limit Requested limit.
	 * @param int   $cap   Upper bound.
	 * @return int
	 */
	public static function clamp_limit( $limit, $cap = self::MAX_LIMIT ) {
		$limit = (int) $limit;

		return $limit < 1 ? self::DEFAULT_LIMIT : min( $limit, $cap );
	}

	/**
	 * Query published posts.
	 *
	 * @param array $args {
	 *     Optional filters.
	 *
	 *     @type string $post_type  Post type slug.
	 *     @type string $category   Category slug or name.
	 *     @type string $start_date Publish date lower bound (Y-m-d).
	 *     @type string $end_date   Publish date upper bound (Y-m-d).
	 *     @type int    $limit      Items to return (default 25, max 100).
	 *     @type int    $hard_cap   Internal upper bound for $limit (used when building analysis pools).
	 *     @type string $orderby    date|modified|comments.
	 *     @type int[]  $include    Restrict to these post IDs.
	 * }
	 * @return array[] Normalised items.
	 */
	public function query( array $args = array() ) {
		$allowed_types = $this->get_post_types();
		$post_type     = isset( $args['post_type'] ) && in_array( $args['post_type'], $allowed_types, true ) ? $args['post_type'] : $allowed_types;
		$orderby_map   = array(
			'date'     => 'date',
			'modified' => 'modified',
			'comments' => 'comment_count',
		);
		$orderby       = isset( $args['orderby'], $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'date';

		$query = array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => self::clamp_limit( $args['limit'] ?? self::DEFAULT_LIMIT, (int) ( $args['hard_cap'] ?? self::MAX_LIMIT ) ),
			'orderby'                => $orderby,
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => false,
			'update_post_term_cache' => true,
		);

		if ( ! empty( $args['include'] ) ) {
			$query['post__in'] = array_map( 'intval', (array) $args['include'] );
			$query['orderby']  = 'post__in';
		}

		if ( ! empty( $args['category'] ) ) {
			$query['category_name'] = sanitize_title( (string) $args['category'] );
		}

		$date_query = array();
		if ( ! empty( $args['start_date'] ) && self::is_date( $args['start_date'] ) ) {
			$date_query['after'] = $args['start_date'] . ' 00:00:00';
		}
		if ( ! empty( $args['end_date'] ) && self::is_date( $args['end_date'] ) ) {
			$date_query['before'] = $args['end_date'] . ' 23:59:59';
		}
		if ( $date_query ) {
			$date_query['inclusive'] = true;
			$query['date_query']     = array( $date_query );
		}

		$items = array();
		foreach ( $this->run_query( $query ) as $found ) {
			$items[] = $this->to_item( $found );
		}

		return $items;
	}

	/**
	 * Run the WP_Query (overridable in tests).
	 *
	 * @param array $query WP_Query arguments.
	 * @return \WP_Post[]
	 */
	protected function run_query( array $query ) {
		$wp_query = new \WP_Query( $query );

		return $wp_query->posts;
	}

	/**
	 * Build the normalised item for a post object.
	 *
	 * @param \WP_Post $found Post object.
	 * @return array
	 */
	protected function to_item( $found ) {
		$post_id    = (int) $found->ID;
		$url        = (string) get_permalink( $post_id );
		$categories = array();
		$terms      = get_the_category( $post_id );
		foreach ( (array) $terms as $term ) {
			$categories[] = $term->name;
		}

		$author = get_the_author_meta( 'display_name', (int) $found->post_author );

		return array(
			'post_id'       => $post_id,
			'title'         => wp_strip_all_tags( get_the_title( $post_id ) ),
			'url'           => $url,
			'path'          => Post_Stats::normalize_path( wp_make_link_relative( $url ) ),
			'type'          => (string) $found->post_type,
			'published'     => gmdate( 'Y-m-d', strtotime( (string) $found->post_date_gmt ) ),
			'modified'      => gmdate( 'Y-m-d', strtotime( (string) $found->post_modified_gmt ) ),
			'author'        => (string) $author,
			'categories'    => $categories,
			'word_count'    => str_word_count( wp_strip_all_tags( strip_shortcodes( (string) $found->post_content ) ) ),
			'comment_count' => (int) $found->comment_count,
		);
	}

	/**
	 * Whether a string is a valid Y-m-d date.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_date( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		$parsed = \DateTime::createFromFormat( 'Y-m-d', $value );

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $value;
	}
}
