<?php
/**
 * Form markup helpers for the Connections and Settings screens
 *
 * Small, escaped builders for the admin UI kit patterns those two screens share:
 * the sub-page navigation (a link-based segmented control that also hosts the
 * Pro add-on tab links), status badges, notices and checkbox switches.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Kit markup builders for form screens.
 */
class Form_Ui {

	/**
	 * Print the sub-page navigation.
	 *
	 * Core tabs are rendered as kit segmented-control links. The extension action
	 * then fires with the active tab, exactly as before, and whatever markup it
	 * prints (Pro still echoes `<a class="nav-tab">` links) is captured, filtered
	 * to safe HTML and placed inside the same control. A link carrying
	 * `nav-tab-active` is marked `aria-current="page"` so the kit styles it.
	 *
	 * Each item: `slug`, `label`, `url`, optional `badge` (a pre-escaped HTML snippet).
	 *
	 * @param array<int,array<string,string>> $items      Core tabs.
	 * @param string                          $active_tab Active tab slug.
	 * @param string                          $hook       Extension action name.
	 * @param string                          $label      Accessible name of the navigation.
	 */
	public static function sub_nav( array $items, $active_tab, $hook, $label ) {
		echo '<nav class="aui-seg smac-subnav" aria-label="' . esc_attr( $label ) . '">';

		foreach ( $items as $item ) {
			$is_active = $item['slug'] === $active_tab;
			$badge     = isset( $item['badge'] ) ? $item['badge'] : '';

			echo '<a href="' . esc_attr( $item['url'] ) . '"' . ( $is_active ? ' aria-current="page"' : '' ) . '>';
			echo esc_html( $item['label'] );
			echo $badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by the caller (an icon or count).
			echo '</a>';
		}

		ob_start();
		/**
		 * Fires so an add-on can print extra tab links.
		 *
		 * @param string $active_tab The currently active tab.
		 */
		do_action( $hook, $active_tab ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Callers pass the plugin's own specflux_mac_*_tabs hook names.
		$extra = (string) ob_get_clean();

		echo self::mark_active_links( wp_kses_post( $extra ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Passed through wp_kses_post() above; only an aria-current attribute is added.

		echo '</nav>';
	}

	/**
	 * Platform slug => group key for the Connections list. Unlisted slugs go under "More".
	 */
	const PLATFORM_GROUPS = array(
		'ga4'            => 'analytics',
		'matomo'         => 'analytics',
		'clarity'        => 'analytics',
		'pagespeed'      => 'analytics',
		'wpcontent'      => 'analytics',
		'localstats'     => 'analytics',
		'forms'          => 'analytics',
		'gsc'            => 'search',
		'bing'           => 'search',
		'dataforseo'     => 'search',
		'klaviyo'        => 'email',
		'mailchimp'      => 'email',
		'brevo'          => 'email',
		'mailerlite'     => 'email',
		'activecampaign' => 'email',
		'ads'            => 'ads',
		'msads'          => 'ads',
		'meta'           => 'ads',
		'callrail'       => 'sales',
		'whatconverts'   => 'sales',
		'stripe'         => 'sales',
		'woocommerce'    => 'sales',
		'easy-digital-downloads' => 'sales',
		'givewp'         => 'sales',
	);

	/**
	 * Print the Connections platform list.
	 *
	 * Same contract as sub_nav(): core items first, then the extension action
	 * fires once with the active tab. Links the add-on prints are read back
	 * (slug from their `tab` query arg, text as the label) so they join their
	 * group instead of trailing the list; anything that is not such a link is
	 * kept, sanitised, at the end.
	 *
	 * @param array<int,array<string,string>> $items      Core tabs (`slug`, `label`, `url`).
	 * @param string                          $active_tab Active tab slug.
	 * @param string                          $hook       Extension action name.
	 * @param string                          $label      Accessible name of the navigation.
	 * @param array<string,bool>              $status     Slug => connected, for platforms whose state is known.
	 */
	public static function platform_nav( array $items, $active_tab, $hook, $label, array $status = array() ) {
		ob_start();
		/** This action is documented in includes/admin/class-form-ui.php */
		do_action( $hook, $active_tab ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Callers pass the plugin's own specflux_mac_*_tabs hook names.
		$extra = (string) ob_get_clean();

		echo self::platform_nav_markup( $items, $extra, $active_tab, $label, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts; add-on leftovers pass through wp_kses_post().
	}

	/**
	 * Build the grouped platform list.
	 *
	 * @param array<int,array<string,string>> $items      Core tabs.
	 * @param string                          $extra      Markup the extension action printed.
	 * @param string                          $active_tab Active tab slug.
	 * @param string                          $label      Accessible name of the navigation.
	 * @param array<string,bool>              $status     Slug => connected.
	 * @return string
	 */
	public static function platform_nav_markup( array $items, $extra, $active_tab, $label, array $status = array() ) {
		$seen = array();
		foreach ( $items as $item ) {
			$seen[ $item['slug'] ] = true;
		}

		$leftover = (string) preg_replace_callback(
			'/<a\b[^>]*\bhref=(["\'])([^"\']*)\1[^>]*>(.*?)<\/a>/is',
			static function ( $m ) use ( &$items, &$seen ) {
				$url  = html_entity_decode( $m[2], ENT_QUOTES );
				$args = array();
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
				$slug = isset( $args['tab'] ) && is_string( $args['tab'] ) ? sanitize_key( $args['tab'] ) : '';
				$text = trim( wp_strip_all_tags( $m[3] ) );
				if ( '' === $slug || '' === $text ) {
					return $m[0];
				}
				if ( empty( $seen[ $slug ] ) ) {
					$seen[ $slug ] = true;
					$items[]       = array(
						'slug'  => $slug,
						'label' => $text,
						'url'   => $url,
					);
				}
				return '';
			},
			$extra
		);

		$names  = array(
			'analytics' => __( 'Analytics', 'specflux-marketing-analytics-chat' ),
			'search'    => __( 'Search & SEO', 'specflux-marketing-analytics-chat' ),
			'email'     => __( 'Email', 'specflux-marketing-analytics-chat' ),
			'ads'       => __( 'Advertising', 'specflux-marketing-analytics-chat' ),
			'sales'     => __( 'Sales & calls', 'specflux-marketing-analytics-chat' ),
			'more'      => __( 'More', 'specflux-marketing-analytics-chat' ),
		);
		$groups = array_fill_keys( array_keys( $names ), array() );
		foreach ( $items as $item ) {
			$key              = isset( self::PLATFORM_GROUPS[ $item['slug'] ] ) ? self::PLATFORM_GROUPS[ $item['slug'] ] : 'more';
			$groups[ $key ][] = $item;
		}

		$html = '<nav class="smac-pnav" aria-label="' . esc_attr( $label ) . '">';
		foreach ( array_filter( $groups ) as $key => $group ) {
			$heading_id = 'smac-pnav-' . $key;
			$html      .= '<div class="smac-pnav-group"><p class="smac-pnav-h" id="' . esc_attr( $heading_id ) . '">' . esc_html( $names[ $key ] ) . '</p>';
			$html      .= '<ul aria-labelledby="' . esc_attr( $heading_id ) . '">';
			foreach ( $group as $item ) {
				$dot   = 'smac-pnav-dot is-unknown';
				$state = '';
				if ( isset( $status[ $item['slug'] ] ) ) {
					$dot   = $status[ $item['slug'] ] ? 'smac-pnav-dot is-on' : 'smac-pnav-dot';
					$state = $status[ $item['slug'] ] ? __( '(connected)', 'specflux-marketing-analytics-chat' ) : __( '(not connected)', 'specflux-marketing-analytics-chat' );
				}
				$html .= '<li><a href="' . esc_attr( $item['url'] ) . '"' . ( $item['slug'] === $active_tab ? ' aria-current="page"' : '' ) . '>';
				$html .= '<span class="' . esc_attr( $dot ) . '" aria-hidden="true"></span>';
				$html .= '<span class="smac-pnav-label">' . esc_html( $item['label'] ) . '</span>';
				$html .= '' !== $state ? '<span class="aui-sr"> ' . esc_html( $state ) . '</span>' : '';
				$html .= '</a></li>';
			}
			$html .= '</ul></div>';
		}
		if ( '' !== trim( wp_strip_all_tags( $leftover ) ) ) {
			$html .= '<div class="smac-pnav-extra">' . self::mark_active_links( wp_kses_post( $leftover ) ) . '</div>';
		}
		$html .= '</nav>';

		return $html;
	}

	/**
	 * Add `aria-current="page"` to anchors that WordPress marks as the active tab.
	 *
	 * @param string $html Sanitised markup containing `<a class="nav-tab ...">` links.
	 * @return string
	 */
	public static function mark_active_links( $html ) {
		return (string) preg_replace_callback(
			'/<a\b([^>]*)>/i',
			static function ( $m ) {
				if ( false === strpos( $m[1], 'nav-tab-active' ) || false !== strpos( $m[1], 'aria-current' ) ) {
					return $m[0];
				}

				return '<a' . $m[1] . ' aria-current="page">';
			},
			$html
		);
	}

	/**
	 * Resolve the connection state for a badge.
	 *
	 * A saved credential whose last connection test failed shows as an error.
	 *
	 * @param bool       $has_credentials Whether credentials are saved.
	 * @param array|null $last_test       Last stored test result (`success`, `message`) or null.
	 * @return array{state:string,label:string,message:string}
	 */
	public static function connection_state( $has_credentials, $last_test = null ) {
		if ( $has_credentials && is_array( $last_test ) && empty( $last_test['success'] ) ) {
			return array(
				'state'   => 'error',
				'label'   => __( 'Error', 'specflux-marketing-analytics-chat' ),
				'message' => isset( $last_test['message'] ) ? (string) $last_test['message'] : '',
			);
		}

		if ( $has_credentials ) {
			return array(
				'state'   => 'connected',
				'label'   => __( 'Connected', 'specflux-marketing-analytics-chat' ),
				'message' => '',
			);
		}

		return array(
			'state'   => 'disconnected',
			'label'   => __( 'Not connected', 'specflux-marketing-analytics-chat' ),
			'message' => '',
		);
	}

	/**
	 * Status badge markup for a connection state.
	 *
	 * @param string $state Connected, disconnected or error.
	 * @param string $label Visible, translated label.
	 * @return string
	 */
	public static function status_badge( $state, $label ) {
		$map = array(
			'connected'    => array( 'aui-badge--ok', 'check' ),
			'error'        => array( 'aui-badge--bad', 'warning' ),
			'disconnected' => array( '', 'plug' ),
		);
		$state = isset( $map[ $state ] ) ? $state : 'disconnected';
		$def   = $map[ $state ];

		return '<span class="' . esc_attr( trim( 'aui-badge ' . $def[0] ) ) . ' aui-end" data-state="' . esc_attr( $state ) . '">'
			. Ui_Kit::icon( $def[1] )
			. esc_html( $label )
			. '</span>';
	}

	/**
	 * Kit notice markup.
	 *
	 * @param string $type    Success, error, warning or info.
	 * @param string $message Plain-text message (escaped here).
	 * @return string
	 */
	public static function notice( $type, $message ) {
		$icons = array(
			'success' => 'check',
			'error'   => 'warning',
			'warning' => 'warning',
			'info'    => 'info',
		);
		$type  = isset( $icons[ $type ] ) ? $type : 'info';
		$role  = 'error' === $type ? 'alert' : 'status';

		return '<div class="aui-notice aui-notice--' . esc_attr( $type ) . '" role="' . esc_attr( $role ) . '">'
			. Ui_Kit::icon( $icons[ $type ] )
			. '<div>' . esc_html( $message ) . '</div></div>';
	}

	/**
	 * A real checkbox styled as a kit switch.
	 *
	 * The input keeps its `name`, `value` and `id`, so it posts exactly as a plain
	 * checkbox does, with or without JavaScript. Options: `id`, `name`, `value`,
	 * `checked` (bool), `disabled` (bool), `label` (plain text), `description`
	 * (plain text, optional).
	 *
	 * @param array<string,mixed> $args Options.
	 * @return string
	 */
	public static function toggle( array $args ) {
		$args = array_merge(
			array(
				'id'          => '',
				'name'        => '',
				'value'       => '1',
				'checked'     => false,
				'disabled'    => false,
				'label'       => '',
				'description' => '',
			),
			$args
		);

		$desc_id = '' !== $args['description'] && '' !== $args['id'] ? $args['id'] . '-desc' : '';

		$html  = '<label class="smac-toggle">';
		$html .= '<input type="checkbox" class="smac-toggle-input"';
		$html .= '' !== $args['id'] ? ' id="' . esc_attr( $args['id'] ) . '"' : '';
		$html .= ' name="' . esc_attr( $args['name'] ) . '" value="' . esc_attr( $args['value'] ) . '"';
		$html .= '' !== $desc_id ? ' aria-describedby="' . esc_attr( $desc_id ) . '"' : '';
		$html .= $args['checked'] ? ' checked="checked"' : '';
		$html .= $args['disabled'] ? ' disabled="disabled"' : '';
		$html .= ' />';
		$html .= '<span class="aui-switch-track" aria-hidden="true"></span>';
		$html .= '<span class="smac-toggle-text"><span class="smac-toggle-label">' . esc_html( $args['label'] ) . '</span>';
		if ( '' !== $args['description'] ) {
			$html .= '<span class="aui-help" id="' . esc_attr( $desc_id ) . '">' . esc_html( $args['description'] ) . '</span>';
		}
		$html .= '</span>';
		$html .= '<span class="aui-switch-state" aria-hidden="true" data-on="' . esc_attr__( 'On', 'specflux-marketing-analytics-chat' ) . '" data-off="' . esc_attr__( 'Off', 'specflux-marketing-analytics-chat' ) . '"></span>';
		$html .= '</label>';

		return $html;
	}

	/**
	 * Print a checkbox switch (see toggle()).
	 *
	 * @param array<string,mixed> $args Options, as for toggle().
	 */
	public static function render_toggle( array $args ) {
		echo self::toggle( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped inside toggle().
	}

	/**
	 * Print the sticky save bar that closes a `[data-aui-form]` form.
	 *
	 * The save button is a real submit button, so the form posts without
	 * JavaScript; the kit only decides when the bar is visible.
	 *
	 * @param string $name  Name of the submit button (the handler tests `isset( $_POST[ $name ] )`).
	 * @param string $label Visible, translated button label.
	 */
	public static function savebar( $name, $label ) {
		?>
		<div class="aui-savebar">
			<span class="aui-note"><?php esc_html_e( 'You have unsaved changes.', 'specflux-marketing-analytics-chat' ); ?></span>
			<button type="button" class="aui-btn aui-btn--secondary" data-aui-discard><?php esc_html_e( 'Discard', 'specflux-marketing-analytics-chat' ); ?></button>
			<button type="submit" name="<?php echo esc_attr( $name ); ?>" value="1" class="aui-btn aui-btn--primary" data-aui-save><?php echo esc_html( $label ); ?></button>
		</div>
		<?php
	}

	/**
	 * Print an icon-only copy button for the kit's copy behaviour.
	 *
	 * @param string $target_id Id of the element whose text is copied.
	 */
	public static function copy_button( $target_id ) {
		?>
		<button type="button" class="aui-btn aui-btn--ghost aui-btn--icon aui-btn--sm" data-aui-copy data-aui-target="#<?php echo esc_attr( $target_id ); ?>" data-copied-label="<?php esc_attr_e( 'Copied', 'specflux-marketing-analytics-chat' ); ?>" aria-label="<?php esc_attr_e( 'Copy to clipboard', 'specflux-marketing-analytics-chat' ); ?>">
			<?php echo Ui_Kit::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside Ui_Kit::icon(). ?>
		</button>
		<?php
	}
}
