<?php
/**
 * Dashboard data
 *
 * Small, side-effect-free helpers that shape what the Dashboard screen shows:
 * setup progress, the platform list, the headline stat cards and the recent
 * questions. Everything here reads state that already exists (credentials,
 * cached headline numbers, stored conversations). Nothing calls a remote API.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Admin;

use Specflux_Marketing_Analytics\Credentials\Credential_Manager;
use Specflux_Marketing_Analytics\Credentials\Platform_Registry;
use Specflux_Marketing_Analytics\Reports\Weekly_Summary;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Dashboard view-model helpers.
 */
class Dashboard_Data {

	/**
	 * Connections screen URL, optionally on one platform tab.
	 *
	 * @param string $tab Tab slug, or an empty string for the overview.
	 * @return string
	 */
	public static function connections_url( $tab = '' ) {
		$url = admin_url( 'admin.php?page=specflux-mac-connections' );

		return '' === $tab ? $url : $url . '&tab=' . rawurlencode( $tab );
	}

	/**
	 * Setup steps and the primary next action.
	 *
	 * Pure: the caller passes what is true, this decides what is done, what is
	 * current and what comes next. The first step that is not done is current.
	 *
	 * @param array $state Flags `ga4` and `gsc` (connected), `asked` (a question was asked) and `prompt` (a prompt was saved), each a bool.
	 * @return array {
	 *     @type array[] $steps    Each: key, label, desc, status (done|current|todo), optional.
	 *     @type int     $current  1-based number of the current step, 0 when every step is done.
	 *     @type bool    $complete Whether every step is done.
	 *     @type array   $action   label and url of the primary next action (empty when complete).
	 * }
	 */
	public static function setup_steps( array $state ) {
		$done = array(
			'ga4'    => ! empty( $state['ga4'] ),
			'gsc'    => ! empty( $state['gsc'] ),
			'asked'  => ! empty( $state['asked'] ),
			'prompt' => ! empty( $state['prompt'] ),
		);

		$definitions = array(
			'ga4'    => array(
				'label'    => __( 'Google Analytics', 'specflux-marketing-analytics-chat' ),
				'done'     => __( 'Connected', 'specflux-marketing-analytics-chat' ),
				'todo'     => __( 'Not connected yet', 'specflux-marketing-analytics-chat' ),
				'action'   => __( 'Connect Google Analytics', 'specflux-marketing-analytics-chat' ),
				'url'      => self::connections_url( 'ga4' ),
				'optional' => false,
			),
			'gsc'    => array(
				'label'    => __( 'Search Console', 'specflux-marketing-analytics-chat' ),
				'done'     => __( 'Connected', 'specflux-marketing-analytics-chat' ),
				'todo'     => __( 'Not connected yet', 'specflux-marketing-analytics-chat' ),
				'action'   => __( 'Connect Search Console', 'specflux-marketing-analytics-chat' ),
				'url'      => self::connections_url( 'gsc' ),
				'optional' => false,
			),
			'asked'  => array(
				'label'    => __( 'First question', 'specflux-marketing-analytics-chat' ),
				'done'     => __( 'Asked', 'specflux-marketing-analytics-chat' ),
				'todo'     => __( 'Takes about a minute', 'specflux-marketing-analytics-chat' ),
				'action'   => __( 'Ask your first question', 'specflux-marketing-analytics-chat' ),
				'url'      => admin_url( 'admin.php?page=specflux-mac-ai-assistant' ),
				'optional' => false,
			),
			'prompt' => array(
				'label'    => __( 'Favorite prompt', 'specflux-marketing-analytics-chat' ),
				'done'     => __( 'Saved', 'specflux-marketing-analytics-chat' ),
				'todo'     => __( 'Optional', 'specflux-marketing-analytics-chat' ),
				'action'   => __( 'Save a favorite prompt', 'specflux-marketing-analytics-chat' ),
				'url'      => admin_url( 'admin.php?page=specflux-mac-prompts' ),
				'optional' => true,
			),
		);

		$steps   = array();
		$current = 0;
		$action  = array();
		$number  = 0;

		foreach ( $definitions as $key => $definition ) {
			++$number;

			if ( $done[ $key ] ) {
				$status = 'done';
			} elseif ( 0 === $current ) {
				$status  = 'current';
				$current = $number;
				$action  = array(
					'label' => $definition['action'],
					'url'   => $definition['url'],
				);
			} else {
				$status = 'todo';
			}

			$steps[] = array(
				'key'      => $key,
				'label'    => $definition['label'],
				'desc'     => 'done' === $status ? $definition['done'] : $definition['todo'],
				'status'   => $status,
				'optional' => $definition['optional'],
			);
		}

		return array(
			'steps'    => $steps,
			'current'  => $current,
			'complete' => 0 === $current,
			'action'   => $action,
		);
	}

	/**
	 * Read the real setup state for the current user.
	 *
	 * @param Credential_Manager|null $credentials Credential manager (defaults to the real one).
	 * @param object|null             $chat        Object with get_conversations( $user_id, $limit ).
	 * @param object|null             $prompts     Object with get_all_prompts().
	 * @param int|null                $user_id     User ID (defaults to the current user).
	 * @return array State for setup_steps().
	 */
	public static function setup_state( $credentials = null, $chat = null, $prompts = null, $user_id = null ) {
		$credentials = $credentials ?? new Credential_Manager();
		$user_id     = null === $user_id ? get_current_user_id() : (int) $user_id;
		$asked       = false;
		$prompt      = false;

		try {
			$asked = null !== $chat && count( (array) $chat->get_conversations( $user_id, 1 ) ) > 0;
		} catch ( \Throwable $e ) {
			$asked = false;
		}

		try {
			$prompt = null !== $prompts && count( (array) $prompts->get_all_prompts() ) > 0;
		} catch ( \Throwable $e ) {
			$prompt = false;
		}

		return array(
			'ga4'    => $credentials->has_credentials( 'ga4' ),
			'gsc'    => $credentials->has_credentials( 'gsc' ),
			'asked'  => $asked,
			'prompt' => $prompt,
		);
	}

	/**
	 * Every registered platform with its connection state.
	 *
	 * Reads the platform registry (so add-on platforms appear too), never a
	 * hard-coded list.
	 *
	 * @param Credential_Manager|null $credentials Credential manager (defaults to the real one).
	 * @return array[] Each: slug, label, description, connected, icon, url.
	 */
	public static function platform_rows( $credentials = null ) {
		$credentials = $credentials ?? new Credential_Manager();
		$icons       = array(
			'ga4'     => 'chart',
			'gsc'     => 'magnifying-glass',
			'clarity' => 'user',
		);
		$rows        = array();

		foreach ( Platform_Registry::all() as $slug => $definition ) {
			$rows[] = array(
				'slug'        => $slug,
				'label'       => (string) $definition['label'],
				'description' => self::short_description( (string) $definition['description'] ),
				'connected'   => $credentials->has_credentials( $slug ),
				'icon'        => $icons[ $slug ] ?? 'plug',
				'url'         => self::connections_url( ! empty( $definition['tab'] ) ? $slug : '' ),
			);
		}

		return $rows;
	}

	/**
	 * First sentence of a platform description, capped at 14 words, so list rows stay one or two lines.
	 *
	 * @param string $description Full description from the registry.
	 * @return string
	 */
	public static function short_description( $description ) {
		$parts = preg_split( '/(?<=[.!?])\s+/', trim( $description ), 2 );
		$first = is_array( $parts ) ? (string) $parts[0] : '';

		return wp_trim_words( $first, 14, '…' );
	}

	/**
	 * Headline stat cards from cached numbers.
	 *
	 * The numbers come from the dashboard widget's cached headline (the weekly
	 * summary collector), so this never reads a remote API. Windows are what that
	 * collector uses: 7 days for Analytics and Search Console, 3 days for Clarity.
	 * There is no cached daily series, so the cards show no sparkline.
	 *
	 * @param array[]|null $headline  Cached headline items (Widget_Headline::cached()), or null when nothing is cached.
	 * @param string[]     $connected Slugs of connected platforms.
	 * @return array[] Each: key, label, state (data|empty|disconnected), value, change, has_change, url, platform.
	 */
	public static function stat_cards( $headline, array $connected ) {
		$by_platform = array();

		foreach ( (array) $headline as $item ) {
			if ( is_array( $item ) && isset( $item['platform'] ) ) {
				$by_platform[ (string) $item['platform'] ] = $item;
			}
		}

		$specs = array(
			array(
				'key'       => 'sessions',
				'label'     => __( 'Sessions, 7 days', 'specflux-marketing-analytics-chat' ),
				'platform'  => 'ga4',
				'fallback'  => Weekly_Summary::LOCAL_PLATFORM,
				'fallback_label' => __( 'Visitors, 7 days', 'specflux-marketing-analytics-chat' ),
				'tab'       => 'ga4',
			),
			array(
				'key'      => 'clicks',
				'label'    => __( 'Search clicks, 7 days', 'specflux-marketing-analytics-chat' ),
				'platform' => 'gsc',
				'tab'      => 'gsc',
			),
			array(
				'key'      => 'clarity',
				'label'    => __( 'Clarity sessions, 3 days', 'specflux-marketing-analytics-chat' ),
				'platform' => 'clarity',
				'tab'      => 'clarity',
			),
		);

		$cards = array();

		foreach ( $specs as $spec ) {
			$platform = $spec['platform'];
			$label    = $spec['label'];

			// A site with no Analytics connection but an on-site stats plugin reports visitors instead.
			if ( ! empty( $spec['fallback'] ) && ! in_array( $platform, $connected, true ) && isset( $by_platform[ $spec['fallback'] ] ) ) {
				$platform = $spec['fallback'];
				$label    = $spec['fallback_label'];
			}

			$card = array(
				'key'        => $spec['key'],
				'label'      => $label,
				'platform'   => $platform,
				'state'      => 'disconnected',
				'value'      => null,
				'change'     => null,
				'has_change' => false,
				'url'        => self::connections_url( $spec['tab'] ),
			);

			if ( in_array( $platform, $connected, true ) || isset( $by_platform[ $platform ] ) ) {
				$item = $by_platform[ $platform ] ?? array();

				if ( isset( $item['value'] ) && is_numeric( $item['value'] ) ) {
					$card['state']      = 'data';
					$card['value']      = (float) $item['value'];
					$card['has_change'] = ! empty( $item['has_change'] ) && isset( $item['change'] ) && is_numeric( $item['change'] );
					$card['change']     = $card['has_change'] ? (float) $item['change'] : null;
				} else {
					$card['state'] = 'empty';
				}
			}

			$cards[] = $card;
		}

		return $cards;
	}

	/**
	 * Delta text for a stat card, such as "+8.2%".
	 *
	 * @param float $change Percent change.
	 * @return string
	 */
	public static function format_delta( $change ) {
		$sign = $change > 0 ? '+' : ( $change < 0 ? '-' : '' );

		return $sign . number_format_i18n( abs( $change ), 1 ) . '%';
	}

	/**
	 * The user's most recent conversations, as links into the chat.
	 *
	 * @param object $chat    Object with get_conversations( $user_id, $limit ).
	 * @param int    $user_id User ID.
	 * @param string $nonce   Nonce for the `specflux_mac_conversation` action.
	 * @param int    $limit   How many to return.
	 * @return array[] Each: id, title, url.
	 */
	public static function recent_questions( $chat, $user_id, $nonce, $limit = 5 ) {
		try {
			$conversations = (array) $chat->get_conversations( (int) $user_id, (int) $limit );
		} catch ( \Throwable $e ) {
			return array();
		}

		$items = array();

		foreach ( array_slice( $conversations, 0, (int) $limit ) as $conversation ) {
			$id = isset( $conversation->id ) ? (int) $conversation->id : 0;

			if ( $id < 1 ) {
				continue;
			}

			$title = isset( $conversation->title ) ? trim( wp_strip_all_tags( (string) $conversation->title ) ) : '';

			$items[] = array(
				'id'    => $id,
				'title' => '' === $title ? __( 'Untitled conversation', 'specflux-marketing-analytics-chat' ) : $title,
				'url'   => admin_url( 'admin.php?page=specflux-mac-ai-assistant&conversation_id=' . $id . '&_wpnonce=' . rawurlencode( (string) $nonce ) ),
			);
		}

		return $items;
	}
}
