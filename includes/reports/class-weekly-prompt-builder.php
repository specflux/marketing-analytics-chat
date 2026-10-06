<?php
/**
 * Weekly prompt builder
 *
 * Builds the "ask the AI about this week" question and the chat suggestion
 * chips from numbers that were already collected (the weekly email context or
 * the cached dashboard headline). It never calls an API.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Reports;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Turns collected weekly numbers into a prefill question.
 *
 * Facts are normalised to: array( 'platform' => 'ga4|gsc|clarity', 'value' => float, 'change' => float|null ).
 */
class Weekly_Prompt_Builder {

	/**
	 * Longest prompt the AI Assistant page will prefill.
	 */
	const MAX_LENGTH = 500;

	/**
	 * Slug of the AI Assistant admin page.
	 */
	const PAGE_SLUG = 'specflux-mac-ai-assistant';

	/**
	 * Build a prompt from the weekly email context (Weekly_Summary::collect()).
	 *
	 * @param array $context Weekly_Summary context.
	 * @return string Plain-text prompt, at most MAX_LENGTH characters.
	 */
	public static function from_context( array $context ) {
		$facts = array();

		$keys = array(
			'ga4' => 'sessions',
			'gsc' => 'clicks',
		);

		foreach ( $keys as $platform => $key ) {
			$entry = $context['platforms'][ $platform ] ?? array();
			if ( ! is_array( $entry ) || 'ok' !== ( $entry['status'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $entry['data']['metrics'] ?? array() ) as $metric ) {
				if ( is_array( $metric ) && ( $metric['key'] ?? '' ) === $key && isset( $metric['current'] ) && is_numeric( $metric['current'] ) ) {
					$facts[] = array(
						'platform' => $platform,
						'value'    => (float) $metric['current'],
						'change'   => isset( $metric['change'] ) && is_numeric( $metric['change'] ) ? (float) $metric['change'] : null,
					);
					break;
				}
			}
		}

		return self::build( $facts );
	}

	/**
	 * Build a prompt from cached widget headline items (Widget_Headline::cached()).
	 *
	 * @param array[]|null $items Headline items, or null when nothing is cached.
	 * @return string Plain-text prompt, at most MAX_LENGTH characters.
	 */
	public static function from_headline( ?array $items ) {
		return self::build( self::facts_from_headline( $items ) );
	}

	/**
	 * Normalise headline items into facts.
	 *
	 * @param array[]|null $items Headline items.
	 * @return array[]
	 */
	private static function facts_from_headline( ?array $items ) {
		$facts = array();

		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['platform'], $item['value'] ) || ! is_numeric( $item['value'] ) ) {
				continue;
			}
			$facts[] = array(
				'platform' => (string) $item['platform'],
				'value'    => (float) $item['value'],
				'change'   => ! empty( $item['has_change'] ) && isset( $item['change'] ) && is_numeric( $item['change'] ) ? (float) $item['change'] : null,
			);
		}

		return $facts;
	}

	/**
	 * Build the AI Assistant URL with a prefilled prompt.
	 *
	 * @param string $prompt Plain-text prompt.
	 * @return string URL (not HTML-escaped; wrap in esc_url() on output).
	 */
	public static function assistant_url( $prompt ) {
		// add_query_arg() does not encode values; an unencoded '?' or '%' would truncate or corrupt the prompt.
		return add_query_arg( 'prompt', rawurlencode( self::cap( $prompt ) ), admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
	}

	/**
	 * Suggestion chips for the empty chat state.
	 *
	 * @param array[]|null $items Cached headline items, or null when none.
	 * @return string[] Three plain-text suggestions.
	 */
	public static function suggestions( ?array $items ) {
		$chips = array();
		$names = array(
			'gsc' => __( 'Search Console clicks', 'specflux-marketing-analytics-chat' ),
			'ga4' => __( 'GA4 sessions', 'specflux-marketing-analytics-chat' ),
		);

		foreach ( self::facts_from_headline( $items ) as $fact ) {
			if ( ! isset( $names[ $fact['platform'] ] ) || null === $fact['change'] || abs( $fact['change'] ) < 0.05 ) {
				continue;
			}
			$percent = number_format_i18n( abs( $fact['change'] ), 1 );
			$chips[] = $fact['change'] > 0
				? sprintf(
					/* translators: 1: metric name such as "GA4 sessions", 2: percentage such as "12.1" */
					__( 'Why are %1$s up %2$s%% this week?', 'specflux-marketing-analytics-chat' ),
					$names[ $fact['platform'] ],
					$percent
				)
				: sprintf(
					/* translators: 1: metric name such as "GA4 sessions", 2: percentage such as "12.1" */
					__( 'Why are %1$s down %2$s%% this week?', 'specflux-marketing-analytics-chat' ),
					$names[ $fact['platform'] ],
					$percent
				);
		}

		$chips[] = __( 'Which pages brought the most visitors this week?', 'specflux-marketing-analytics-chat' );
		$chips[] = __( 'What should I fix first on my site?', 'specflux-marketing-analytics-chat' );
		$chips[] = __( 'Compare this week vs last week', 'specflux-marketing-analytics-chat' );

		return array_slice( $chips, 0, 3 );
	}

	/**
	 * Compose the question from facts.
	 *
	 * @param array[] $facts Normalised facts.
	 * @return string
	 */
	private static function build( array $facts ) {
		$parts      = array();
		$has_change = false;

		foreach ( $facts as $fact ) {
			$part = self::describe( $fact );
			if ( '' === $part ) {
				continue;
			}
			$parts[]    = $part;
			$has_change = $has_change || null !== $fact['change'];
		}

		if ( empty( $parts ) ) {
			return self::cap( __( "Summarize last week's analytics and tell me what to fix first.", 'specflux-marketing-analytics-chat' ) );
		}

		$ending = $has_change
			? __( 'What drove the change, and what should I do first this week?', 'specflux-marketing-analytics-chat' )
			: __( 'What stands out, and what should I do first this week?', 'specflux-marketing-analytics-chat' );

		$prompt = sprintf(
			/* translators: 1: list of metrics such as "GA4 sessions were 12,480 (up 8.4%) and Search Console clicks were 2,181", 2: follow-up question */
			__( 'Last week %1$s. %2$s', 'specflux-marketing-analytics-chat' ),
			self::join( $parts ),
			$ending
		);

		return self::cap( $prompt );
	}

	/**
	 * Join phrases with a plain "and" (no Oxford comma; at most three phrases exist).
	 *
	 * @param string[] $parts Phrases.
	 * @return string
	 */
	private static function join( array $parts ) {
		if ( count( $parts ) < 2 ) {
			return (string) reset( $parts );
		}

		$last = array_pop( $parts );

		return implode( ', ', $parts ) . ' ' . __( 'and', 'specflux-marketing-analytics-chat' ) . ' ' . $last;
	}

	/**
	 * One metric phrase, e.g. "GA4 sessions were 12,480 (up 8.4%)".
	 *
	 * @param array $fact Normalised fact.
	 * @return string Empty for an unknown platform.
	 */
	private static function describe( array $fact ) {
		$value = number_format_i18n( $fact['value'] );

		if ( 'clarity' === $fact['platform'] ) {
			return sprintf(
				/* translators: %s: number of sessions */
				__( 'Clarity recorded %s sessions over the last 3 days', 'specflux-marketing-analytics-chat' ),
				$value
			);
		}

		$labels = array(
			'ga4' => __( 'GA4 sessions', 'specflux-marketing-analytics-chat' ),
			'gsc' => __( 'Search Console clicks', 'specflux-marketing-analytics-chat' ),
		);

		if ( ! isset( $labels[ $fact['platform'] ] ) ) {
			return '';
		}

		$text = sprintf(
			/* translators: 1: metric name such as "GA4 sessions", 2: formatted number */
			__( '%1$s were %2$s', 'specflux-marketing-analytics-chat' ),
			$labels[ $fact['platform'] ],
			$value
		);

		if ( null === $fact['change'] ) {
			return $text;
		}

		if ( abs( $fact['change'] ) < 0.05 ) {
			return $text . ' ' . __( '(flat)', 'specflux-marketing-analytics-chat' );
		}

		$percent = number_format_i18n( abs( $fact['change'] ), 1 );

		return $text . ' ' . (
			$fact['change'] > 0
			/* translators: %s: percentage such as "8.4" */
			? sprintf( __( '(up %s%%)', 'specflux-marketing-analytics-chat' ), $percent )
			/* translators: %s: percentage such as "8.4" */
			: sprintf( __( '(down %s%%)', 'specflux-marketing-analytics-chat' ), $percent )
		);
	}

	/**
	 * Strip tags, collapse whitespace and cap the length.
	 *
	 * @param string $prompt Prompt.
	 * @return string
	 */
	private static function cap( $prompt ) {
		$prompt = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $prompt ) ) );

		return mb_substr( $prompt, 0, self::MAX_LENGTH );
	}
}
