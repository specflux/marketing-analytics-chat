<?php
/**
 * Admin Interface Handler
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Admin;

use Specflux_Marketing_Analytics\Utils\Permission_Manager;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Handles admin menu and pages
 */
class Admin {

	/**
	 * Add admin menu pages
	 */
	public function add_admin_menu() {
		// Main menu page - Dashboard.
		add_menu_page(
			__( 'Marketing Analytics', 'specflux-marketing-analytics-chat' ),
			__( 'Marketing Analytics', 'specflux-marketing-analytics-chat' ),
			'access_specflux_mac', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'specflux-mac',
			array( $this, 'render_dashboard_page' ),
			'dashicons-chart-line',
			30
		);

		// Dashboard submenu (same as main).
		add_submenu_page(
			'specflux-mac',
			__( 'Dashboard', 'specflux-marketing-analytics-chat' ),
			__( 'Dashboard', 'specflux-marketing-analytics-chat' ),
			'access_specflux_mac', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'specflux-mac',
			array( $this, 'render_dashboard_page' )
		);

		// Connections page.
		add_submenu_page(
			'specflux-mac',
			__( 'Connections', 'specflux-marketing-analytics-chat' ),
			__( 'Connections', 'specflux-marketing-analytics-chat' ),
			'access_specflux_mac', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'specflux-mac-connections',
			array( $this, 'render_connections_page' )
		);

		// Custom Prompts page.
		add_submenu_page(
			'specflux-mac',
			__( 'Custom Prompts', 'specflux-marketing-analytics-chat' ),
			__( 'Custom Prompts', 'specflux-marketing-analytics-chat' ),
			'access_specflux_mac', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'specflux-mac-prompts',
			array( $this, 'render_prompts_page' )
		);

		// Abilities Catalog page.
		add_submenu_page(
			'specflux-mac',
			__( 'Abilities Catalog', 'specflux-marketing-analytics-chat' ),
			__( 'Abilities', 'specflux-marketing-analytics-chat' ),
			'access_specflux_mac', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'specflux-mac-abilities',
			array( $this, 'render_abilities_page' )
		);

		// Settings page. Requires full admin capability: it holds sensitive
		// controls (role grants, Google OAuth client credentials, AI provider
		// keys, debug mode), so it must not be reachable via the plugin access
		// capability alone.
		add_submenu_page(
			'specflux-mac',
			__( 'Settings', 'specflux-marketing-analytics-chat' ),
			__( 'Settings', 'specflux-marketing-analytics-chat' ),
			'manage_options',
			'specflux-mac-settings',
			array( $this, 'render_settings_page' )
		);

		// AI Chat page.
		add_submenu_page(
			'specflux-mac',
			__( 'AI Assistant', 'specflux-marketing-analytics-chat' ),
			__( 'AI Assistant', 'specflux-marketing-analytics-chat' ),
			'access_specflux_mac', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'specflux-mac-ai-assistant',
			array( $this, 'render_chat_page' )
		);

		/**
		 * Allow pro add-on to register additional submenu pages.
		 */
		do_action( 'specflux_mac_admin_menu' );
	}

	/**
	 * Enqueue admin styles
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_styles( $hook ) {
		// Only load on our admin pages.
		if ( strpos( $hook, 'specflux-mac' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'specflux-mac-admin',
			SPECFLUX_MAC_URL . 'admin/css/admin-styles.css',
			array(),
			SPECFLUX_MAC_VERSION
		);

		// Enqueue chat styles on AI Assistant page.
		if ( strpos( $hook, 'specflux-mac-ai-assistant' ) !== false ) {
			wp_enqueue_style(
				'specflux-mac-interface',
				SPECFLUX_MAC_URL . 'admin/css/chat-interface.css',
				array(),
				SPECFLUX_MAC_VERSION
			);
			wp_enqueue_style(
				'senroflux-card',
				SPECFLUX_MAC_URL . 'admin/css/senroflux-card.css',
				array(),
				SPECFLUX_MAC_VERSION
			);
		}

		// Enqueue wizard styles on settings page.
		if ( strpos( $hook, 'specflux-mac-settings' ) !== false ) {
			wp_enqueue_style(
				'specflux-mac-wizard',
				SPECFLUX_MAC_URL . 'admin/css/wizard.css',
				array(),
				SPECFLUX_MAC_VERSION
			);
		}
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		// Enqueue dashboard widget script on the WP Dashboard.
		if ( 'index.php' === $hook && Permission_Manager::can_access_plugin() ) {
			wp_enqueue_script(
				'specflux-mac-dashboard-widget',
				SPECFLUX_MAC_URL . 'admin/js/dashboard-widget.js',
				array( 'jquery' ),
				SPECFLUX_MAC_VERSION,
				true
			);

			wp_localize_script(
				'specflux-mac-dashboard-widget',
				'specfluxMacDashboardWidget',
				array(
					'nonce' => wp_create_nonce( 'specflux_mac_admin' ),
				)
			);
		}

		// Command Palette commands. Core loads the palette on every admin screen
		// (since WP 6.9), so these must register outside the plugin-page gate
		// below — otherwise the commands only exist on pages the user has
		// already navigated to.
		if ( Permission_Manager::can_access_plugin() ) {
			$this->enqueue_command_palette_script();
		}

		// Only load remaining scripts on our admin pages.
		if ( strpos( $hook, 'specflux-mac' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'specflux-mac-admin',
			SPECFLUX_MAC_URL . 'admin/js/admin-scripts.js',
			array( 'jquery' ),
			SPECFLUX_MAC_VERSION,
			true
		);

		// Localize script with data.
		wp_localize_script(
			'specflux-mac-admin',
			'specfluxMacAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'specflux_mac_admin' ),
				'strings' => array(
					'testing'   => __( 'Testing connection...', 'specflux-marketing-analytics-chat' ),
					'success'   => __( 'Connection successful!', 'specflux-marketing-analytics-chat' ),
					'error'     => __( 'Connection failed', 'specflux-marketing-analytics-chat' ),
					'saveError' => __( 'Error saving settings', 'specflux-marketing-analytics-chat' ),
					'redirecting' => __( 'Redirecting to Google…', 'specflux-marketing-analytics-chat' ),
				),
			)
		);

		// Enqueue wizard script on settings page.
		if ( strpos( $hook, 'specflux-mac-settings' ) !== false ) {
			wp_enqueue_script(
				'specflux-mac-wizard',
				SPECFLUX_MAC_URL . 'admin/js/wizard.js',
				array( 'jquery' ),
				SPECFLUX_MAC_VERSION,
				true
			);

			wp_enqueue_script(
				'specflux-mac-settings-tools',
				SPECFLUX_MAC_URL . 'admin/js/settings-tools.js',
				array( 'jquery' ),
				SPECFLUX_MAC_VERSION,
				true
			);
		}

		// Enqueue sparklines on the dashboard page.
		if ( 'toplevel_page_specflux-mac' === $hook ) {
			wp_enqueue_script(
				'specflux-mac-sparklines',
				SPECFLUX_MAC_URL . 'admin/js/sparklines.js',
				array( 'jquery' ),
				SPECFLUX_MAC_VERSION,
				true
			);

			wp_localize_script(
				'specflux-mac-sparklines',
				'specfluxMacDashboardInsights',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'specflux_mac_dashboard_insights' ),
				)
			);
		}

		// Enqueue onboarding wizard on dashboard page (toplevel only).
		if ( 'toplevel_page_specflux-mac' === $hook && ! get_option( 'specflux_mac_onboarding_complete' ) ) {
			wp_enqueue_script(
				'specflux-mac-onboarding-wizard',
				SPECFLUX_MAC_URL . 'admin/js/onboarding-wizard.js',
				array( 'jquery' ),
				SPECFLUX_MAC_VERSION,
				true
			);

			wp_localize_script(
				'specflux-mac-onboarding-wizard',
				'specfluxMacOnboardingWizard',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'specflux_mac_dismiss_wizard' ),
				)
			);
		}

		// Enqueue chat interface script on AI Assistant page.
		if ( strpos( $hook, 'specflux-mac-ai-assistant' ) !== false ) {
			wp_enqueue_script(
				'specflux-mac-interface',
				SPECFLUX_MAC_URL . 'admin/js/chat-interface.js',
				array( 'jquery' ),
				SPECFLUX_MAC_VERSION,
				true
			);

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading conversation_id for localization only.
			$active_conversation_id = isset( $_GET['conversation_id'] ) ? absint( $_GET['conversation_id'] ) : 0;

			wp_localize_script(
				'specflux-mac-interface',
				'specfluxMacChat',
				array(
					'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
					'nonce'             => wp_create_nonce( 'specflux_mac_admin' ),
					'conversationId'    => $active_conversation_id ? $active_conversation_id : null,
					'userId'            => get_current_user_id(),
					'chatPageUrl'       => admin_url( 'admin.php?page=specflux-mac-ai-assistant' ),
					'conversationNonce' => wp_create_nonce( 'specflux_mac_conversation' ),
					'i18n'              => array(
						'needsApproval'        => __( 'Needs your approval:', 'specflux-marketing-analytics-chat' ),
						'approve'              => __( 'Approve', 'specflux-marketing-analytics-chat' ),
						'reject'               => __( 'Reject', 'specflux-marketing-analytics-chat' ),
						'reviewInAgentSafety'  => __( 'Review in Agent Safety', 'specflux-marketing-analytics-chat' ),
					),
				)
			);
		}

		// Enqueue abilities catalog filter script.
		if ( strpos( $hook, 'specflux-mac-abilities' ) !== false ) {
			wp_enqueue_script(
				'specflux-mac-abilities-catalog',
				SPECFLUX_MAC_URL . 'admin/js/abilities-catalog.js',
				array(),
				SPECFLUX_MAC_VERSION,
				true
			);
		}

		// Enqueue prompts page script.
		if ( strpos( $hook, 'specflux-mac-prompts' ) !== false ) {
			wp_enqueue_script(
				'specflux-mac-prompts',
				SPECFLUX_MAC_URL . 'admin/js/prompts.js',
				array( 'jquery' ),
				SPECFLUX_MAC_VERSION,
				true
			);

			$custom_prompts = ( new \Specflux_Marketing_Analytics\Prompts\Prompt_Manager() )->get_all_prompts();

			wp_localize_script(
				'specflux-mac-prompts',
				'specfluxMacPrompts',
				array(
					'customPrompts' => $custom_prompts,
					'strings'       => array(
						'invalidJson' => __( 'Invalid JSON in Arguments field. Please check your syntax.', 'specflux-marketing-analytics-chat' ),
					),
				)
			);
		}
	}

	/**
	 * Enqueue the Command Palette integration script.
	 *
	 * Registers plugin commands with core's admin-wide Command Palette. The
	 * 'wp-commands' handle is only registered from WP 6.9 onward, so guard on
	 * it rather than on a version number.
	 */
	private function enqueue_command_palette_script() {
		if ( ! wp_script_is( 'wp-commands', 'registered' ) ) {
			return;
		}

		wp_enqueue_script(
			'specflux-mac-command-palette',
			SPECFLUX_MAC_URL . 'admin/js/command-palette.js',
			array( 'wp-commands', 'wp-data', 'wp-dom-ready', 'wp-element', 'wp-primitives', 'wp-a11y' ),
			SPECFLUX_MAC_VERSION,
			true
		);

		wp_localize_script(
			'specflux-mac-command-palette',
			'specfluxMacCommands',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'specflux_mac_admin' ),
				'dashboardUrl' => admin_url( 'admin.php?page=specflux-mac' ),
				'chatUrl'      => admin_url( 'admin.php?page=specflux-mac-ai-assistant' ),
				'strings'      => array(
					// Core already derives "Go to: Marketing Analytics > …" commands
					// from the admin menu. These labels are deliberately
					// intent-led rather than page-named so they read as
					// shortcuts, not duplicates of those.
					//
					// The *Keywords entries are extra match terms, not replacements:
					// core matches on `searchLabel ?? label`, so setting searchLabel
					// makes the visible label itself unsearchable. They are passed as
					// the palette's `keywords`, which adds to the label instead.
					// Spell the platforms out in full — people type "google analytics",
					// not "GA4".
					'openDashboard'         => __( 'Marketing Analytics: View my metrics', 'specflux-marketing-analytics-chat' ),
					'openDashboardKeywords' => __( 'dashboard metrics traffic GA4 Google Analytics Clarity Microsoft Clarity GSC Google Search Console', 'specflux-marketing-analytics-chat' ),
					'askAi'                 => __( 'Marketing Analytics: Ask AI about my data', 'specflux-marketing-analytics-chat' ),
					'askAiKeywords'         => __( 'ask AI assistant chat question GA4 Google Analytics Clarity Microsoft Clarity GSC Google Search Console', 'specflux-marketing-analytics-chat' ),
					'refreshData'           => __( 'Marketing Analytics: Refresh data', 'specflux-marketing-analytics-chat' ),
					'refreshDataKeywords'   => __( 'refresh reload cache GA4 Google Analytics Clarity Microsoft Clarity GSC Google Search Console', 'specflux-marketing-analytics-chat' ),
					'refreshing'            => __( 'Refreshing marketing analytics data…', 'specflux-marketing-analytics-chat' ),
					'refreshed'             => __( 'Marketing analytics data refreshed.', 'specflux-marketing-analytics-chat' ),
					'refreshFailed'         => __( 'Could not refresh marketing analytics data.', 'specflux-marketing-analytics-chat' ),
				),
			)
		);
	}

	/**
	 * Render dashboard page
	 */
	public function render_dashboard_page() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once SPECFLUX_MAC_PATH . 'admin/views/dashboard.php';
	}

	/**
	 * Render connections page
	 */
	public function render_connections_page() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once SPECFLUX_MAC_PATH . 'admin/views/connections.php';
	}

	/**
	 * Render prompts page
	 */
	public function render_prompts_page() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once SPECFLUX_MAC_PATH . 'admin/views/prompts.php';
	}

	/**
	 * Render abilities catalog page
	 */
	public function render_abilities_page() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once SPECFLUX_MAC_PATH . 'admin/views/abilities-catalog.php';
	}

	/**
	 * Render AI chat page
	 */
	public function render_chat_page() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once SPECFLUX_MAC_PATH . 'admin/views/chat.php';
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		// Sensitive page (role grants, OAuth client credentials, AI keys, debug):
		// require full admin capability, not merely plugin access. This also
		// guards the save handlers, which run inside the included template.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'specflux-marketing-analytics-chat' ) );
		}

		require_once SPECFLUX_MAC_PATH . 'admin/views/settings.php';
	}

	/**
	 * Register the WordPress dashboard widget
	 */
	public function register_dashboard_widget() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		wp_add_dashboard_widget(
			'specflux_mac_dashboard_widget',
			__( 'Marketing Analytics', 'specflux-marketing-analytics-chat' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the WordPress dashboard widget
	 */
	public function render_dashboard_widget() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once SPECFLUX_MAC_PATH . 'admin/views/widgets/dashboard-widget.php';
	}

	/**
	 * Add analytics pulse item to the WordPress admin bar
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 */
	public function add_admin_bar_item( $wp_admin_bar ) {
		if ( ! is_admin() || ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		$anomalies    = get_option( 'specflux_mac_recent_anomalies', array() );
		$anomalies    = is_array( $anomalies ) ? $anomalies : array();
		$health_class = $this->get_health_class( $anomalies );
		$badge_count  = count( $anomalies );

		// Build title with health dot and optional badge.
		$title  = '<span class="smac-pulse-dot ' . esc_attr( $health_class ) . '"></span> ';
		$title .= esc_html__( 'Analytics', 'specflux-marketing-analytics-chat' );

		if ( $badge_count > 0 ) {
			$title .= ' <span class="smac-anomaly-badge">' . esc_html( $badge_count ) . '</span>';
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'specflux-mac-pulse',
				'title' => $title,
				'href'  => esc_url( admin_url( 'admin.php?page=specflux-mac' ) ),
				'meta'  => array(
					'class' => 'specflux-mac-pulse-node',
				),
			)
		);

		// Add up to 3 most recent anomaly child nodes.
		$recent = array_slice( $anomalies, 0, 3 );
		foreach ( $recent as $index => $anomaly ) {
			$anomaly_title = isset( $anomaly['title'] ) ? $anomaly['title'] : '';
			$severity      = isset( $anomaly['severity'] ) ? $anomaly['severity'] : 'low';

			$wp_admin_bar->add_node(
				array(
					'id'     => 'specflux-mac-anomaly-' . $index,
					'parent' => 'specflux-mac-pulse',
					'title'  => '<span class="smac-bar-severity smac-bar-severity-' . esc_attr( $severity ) . '">' . esc_html( ucfirst( $severity ) ) . '</span> ' . esc_html( $anomaly_title ),
					'href'   => esc_url( admin_url( 'admin.php?page=specflux-mac' ) ),
				)
			);
		}

		// "View Dashboard" link as final child node.
		$wp_admin_bar->add_node(
			array(
				'id'     => 'specflux-mac-view-dashboard',
				'parent' => 'specflux-mac-pulse',
				'title'  => esc_html__( 'View Dashboard', 'specflux-marketing-analytics-chat' ),
				'href'   => esc_url( admin_url( 'admin.php?page=specflux-mac' ) ),
			)
		);

		/**
		 * Fires after admin bar items are added, allowing premium to extend.
		 *
		 * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
		 */
		do_action( 'specflux_mac_admin_bar_items', $wp_admin_bar );
	}

	/**
	 * Enqueue inline CSS for admin bar analytics pulse
	 */
	public function enqueue_admin_bar_styles() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		$css = '
			#wp-admin-bar-specflux-mac-pulse .smac-pulse-dot {
				display: inline-block;
				width: 10px;
				height: 10px;
				border-radius: 50%;
				margin-right: 4px;
				vertical-align: middle;
			}
			#wp-admin-bar-specflux-mac-pulse .smac-pulse-dot.healthy {
				background-color: #00a32a;
			}
			#wp-admin-bar-specflux-mac-pulse .smac-pulse-dot.warning {
				background-color: #dba617;
			}
			#wp-admin-bar-specflux-mac-pulse .smac-pulse-dot.critical {
				background-color: #d63638;
			}
			#wp-admin-bar-specflux-mac-pulse .smac-anomaly-badge {
				display: inline-block;
				background: #d63638;
				color: #fff;
				font-size: 9px;
				font-weight: 600;
				line-height: 16px;
				min-width: 16px;
				height: 16px;
				text-align: center;
				border-radius: 8px;
				padding: 0 4px;
				margin-left: 4px;
				vertical-align: middle;
			}
			#wp-admin-bar-specflux-mac-pulse .smac-bar-severity {
				display: inline-block;
				padding: 1px 6px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 600;
				margin-right: 4px;
			}
			#wp-admin-bar-specflux-mac-pulse .smac-bar-severity-critical {
				background: #d63638;
				color: #fff;
			}
			#wp-admin-bar-specflux-mac-pulse .smac-bar-severity-high {
				background: #e65100;
				color: #fff;
			}
			#wp-admin-bar-specflux-mac-pulse .smac-bar-severity-medium {
				background: #dba617;
				color: #1d2327;
			}
			#wp-admin-bar-specflux-mac-pulse .smac-bar-severity-low {
				background: #00a32a;
				color: #fff;
			}
			#wp-admin-bar-specflux-mac-pulse .ab-submenu .ab-item {
				height: auto !important;
				line-height: 1.4 !important;
				padding: 6px 10px !important;
			}
		';

		wp_register_style( 'specflux-mac-admin-bar', false, array(), SPECFLUX_MAC_VERSION );
		wp_enqueue_style( 'specflux-mac-admin-bar' );
		wp_add_inline_style( 'specflux-mac-admin-bar', $css );
	}

	/**
	 * Determine health class based on anomaly severities
	 *
	 * @param array $anomalies Array of anomaly data.
	 * @return string CSS class: 'healthy', 'warning', or 'critical'.
	 */
	private function get_health_class( $anomalies ) {
		if ( empty( $anomalies ) ) {
			return 'healthy';
		}

		$now = time();

		foreach ( $anomalies as $anomaly ) {
			$timestamp = isset( $anomaly['timestamp'] ) ? (int) $anomaly['timestamp'] : 0;

			// Only consider anomalies from the last 24 hours.
			if ( ( $now - $timestamp ) > DAY_IN_SECONDS ) {
				continue;
			}

			$severity = isset( $anomaly['severity'] ) ? $anomaly['severity'] : 'low';

			if ( 'critical' === $severity || 'high' === $severity ) {
				return 'critical';
			}
		}

		// If we reach here with anomalies, they are low/medium or older than 24h.
		foreach ( $anomalies as $anomaly ) {
			$timestamp = isset( $anomaly['timestamp'] ) ? (int) $anomaly['timestamp'] : 0;

			if ( ( $now - $timestamp ) > DAY_IN_SECONDS ) {
				continue;
			}

			return 'warning';
		}

		return 'healthy';
	}
}
