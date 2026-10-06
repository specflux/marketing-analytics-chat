<?php
/**
 * Chat Tool Policy
 *
 * Decides which WordPress abilities the in-admin chat may offer to the model.
 * An ability is a read tool only when it declares `readonly: true` in its
 * annotations; anything else (false, null or missing) is a write tool, so an
 * ability that says nothing is never treated as safe.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Chat;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Classifies, groups and selects abilities for the chat.
 */
class Tool_Policy {

	/**
	 * Provider slug of this plugin's own abilities.
	 */
	const OWN_PROVIDER = 'marketing-analytics';

	/**
	 * Provider slug of WordPress core abilities.
	 */
	const CORE_PROVIDER = 'core';

	/**
	 * Default cap on tools offered to the model.
	 */
	const DEFAULT_MAX_TOOLS = 64;

	/**
	 * Canonical slash form of an ability name.
	 *
	 * The AI Client sends names as "wpab__vendor__ability"; the registry uses "vendor/ability".
	 *
	 * @param string $name Tool or ability name.
	 * @return string Name such as "vendor/ability".
	 */
	public static function canonical_name( $name ) {
		$name = (string) $name;

		if ( 0 === strpos( $name, 'wpab__' ) ) {
			$name = substr( $name, 6 );
		}

		return str_replace( '__', '/', $name );
	}

	/**
	 * Classify an ability from its annotations.
	 *
	 * @param array|null $annotations Ability `meta.annotations`.
	 * @return string 'read' only when readonly is exactly true, otherwise 'write'.
	 */
	public static function classify( $annotations ) {
		if ( is_array( $annotations ) && isset( $annotations['readonly'] ) && true === $annotations['readonly'] ) {
			return 'read';
		}

		return 'write';
	}

	/**
	 * Classify a tool definition from MCP_Client::list_tools().
	 *
	 * @param array $tool Tool definition.
	 * @return string 'read' or 'write'.
	 */
	public static function classify_tool( array $tool ) {
		return self::classify( $tool['annotations'] ?? null );
	}

	/**
	 * Provider slug for an ability name: the namespace before the slash.
	 *
	 * @param string $name Tool or ability name.
	 * @return string Slug, or 'other' when the name has no namespace.
	 */
	public static function provider_slug( $name ) {
		$parts = explode( '/', self::canonical_name( $name ), 2 );

		return ( isset( $parts[1] ) && '' !== $parts[0] ) ? $parts[0] : 'other';
	}

	/**
	 * Human label for a provider.
	 *
	 * @param string $slug Provider slug.
	 * @return string Label.
	 */
	public static function provider_label( $slug ) {
		if ( self::OWN_PROVIDER === $slug ) {
			$label = __( 'Specflux Marketing Analytics Chat', 'specflux-marketing-analytics-chat' );
		} elseif ( self::CORE_PROVIDER === $slug ) {
			$label = __( 'WordPress core', 'specflux-marketing-analytics-chat' );
		} else {
			$label = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
		}

		/**
		 * Filters the label shown for an ability provider (namespace).
		 *
		 * @param string $label Label.
		 * @param string $slug  Provider slug.
		 */
		return (string) apply_filters( 'specflux_mac_chat_provider_label', $label, $slug );
	}

	/**
	 * Whether write abilities may be offered (a Pro add-on turns this on).
	 *
	 * @return bool
	 */
	public static function allow_write() {
		/**
		 * Filters whether the chat may offer write abilities. They never run
		 * directly: each call becomes a proposal the user must approve.
		 *
		 * @param bool $allow Default false.
		 */
		return true === (bool) apply_filters( 'specflux_mac_chat_allow_write_abilities', false );
	}

	/**
	 * Whether a provider is enabled in the chat.
	 *
	 * This plugin and core are always on; others default on unless switched off in settings.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	public static function provider_enabled( $slug ) {
		if ( self::OWN_PROVIDER === $slug || self::CORE_PROVIDER === $slug ) {
			return true;
		}

		$settings  = get_option( 'specflux_mac_settings', array() );
		$providers = is_array( $settings ) && isset( $settings['chat_providers'] ) && is_array( $settings['chat_providers'] ) ? $settings['chat_providers'] : array();

		return ! isset( $providers[ $slug ] ) || (bool) $providers[ $slug ];
	}

	/**
	 * Choose the tools to offer the model.
	 *
	 * Read tools from enabled providers; write tools only when allowed. Ordered
	 * own first, then core, then other providers alphabetically, and capped.
	 *
	 * @param array $tools Tool definitions from MCP_Client::list_tools().
	 * @return array Tools to offer.
	 */
	public static function select( array $tools ) {
		$allow_write = self::allow_write();
		$groups      = array();

		foreach ( $tools as $tool ) {
			if ( empty( $tool['name'] ) ) {
				continue;
			}

			if ( 'write' === self::classify_tool( $tool ) && ! $allow_write ) {
				continue;
			}

			$slug = self::provider_slug( $tool['name'] );
			if ( ! self::provider_enabled( $slug ) ) {
				continue;
			}

			$groups[ $slug ][] = $tool;
		}

		$slugs = array_keys( $groups );
		usort(
			$slugs,
			static function ( $a, $b ) {
				$rank = static function ( $slug ) {
					if ( self::OWN_PROVIDER === $slug ) {
						return 0;
					}
					return self::CORE_PROVIDER === $slug ? 1 : 2;
				};

				return array( $rank( $a ), $a ) <=> array( $rank( $b ), $b );
			}
		);

		$selected = array();
		foreach ( $slugs as $slug ) {
			$selected = array_merge( $selected, $groups[ $slug ] );
		}

		/**
		 * Filters the maximum number of tools offered to the chat model.
		 *
		 * @param int $max Default 64.
		 */
		$max = max( 1, (int) apply_filters( 'specflux_mac_chat_max_tools', self::DEFAULT_MAX_TOOLS ) );

		return array_slice( $selected, 0, $max );
	}

	/**
	 * Count read and write abilities per provider.
	 *
	 * @param array $tools Tool definitions from MCP_Client::list_tools().
	 * @return array Map of slug => array( label, read, write ), own first, core, then alphabetical.
	 */
	public static function providers( array $tools ) {
		$providers = array();

		foreach ( $tools as $tool ) {
			if ( empty( $tool['name'] ) ) {
				continue;
			}

			$slug = self::provider_slug( $tool['name'] );
			if ( ! isset( $providers[ $slug ] ) ) {
				$providers[ $slug ] = array(
					'label' => self::provider_label( $slug ),
					'read'  => 0,
					'write' => 0,
				);
			}

			++$providers[ $slug ][ self::classify_tool( $tool ) ];
		}

		uksort(
			$providers,
			static function ( $a, $b ) {
				$rank = static function ( $slug ) {
					if ( self::OWN_PROVIDER === $slug ) {
						return 0;
					}
					return self::CORE_PROVIDER === $slug ? 1 : 2;
				};

				return array( $rank( $a ), $a ) <=> array( $rank( $b ), $b );
			}
		);

		return $providers;
	}

	/**
	 * Short human summary of a proposed ability call, at most 140 characters.
	 *
	 * @param array $tool  Tool definition (name, label).
	 * @param array $input Ability input.
	 * @return string Summary.
	 */
	public static function summarize_call( array $tool, array $input ) {
		$label = ! empty( $tool['label'] ) ? (string) $tool['label'] : (string) $tool['name'];
		$parts = array();

		foreach ( $input as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$text = (string) ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : $value );
			} else {
				$text = wp_json_encode( $value );
			}
			$parts[] = $key . ': ' . $text;
		}

		$summary = $label . ( $parts ? ' (' . implode( ', ', $parts ) . ')' : '' );
		$summary = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $summary ) ) );

		return mb_strlen( $summary ) > 140 ? mb_substr( $summary, 0, 137 ) . '...' : $summary;
	}
}
