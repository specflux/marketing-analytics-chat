<?php
/**
 * Admin Interface Handler
 *
 * @package Marketing_Analytics_MCP
 */

namespace Marketing_Analytics_MCP\Admin;

use Marketing_Analytics_MCP\Utils\Permission_Manager;

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
		// Main menu page - Dashboard
		add_menu_page(
			__( 'Marketing Analytics', 'marketing-analytics-chat' ),
			__( 'Marketing Analytics', 'marketing-analytics-chat' ),
			'access_marketing_analytics', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'marketing-analytics-chat',
			array( $this, 'render_dashboard_page' ),
			'dashicons-chart-line',
			30
		);

		// Dashboard submenu (same as main)
		add_submenu_page(
			'marketing-analytics-chat',
			__( 'Dashboard', 'marketing-analytics-chat' ),
			__( 'Dashboard', 'marketing-analytics-chat' ),
			'access_marketing_analytics', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'marketing-analytics-chat',
			array( $this, 'render_dashboard_page' )
		);

		// Connections page
		add_submenu_page(
			'marketing-analytics-chat',
			__( 'Connections', 'marketing-analytics-chat' ),
			__( 'Connections', 'marketing-analytics-chat' ),
			'access_marketing_analytics', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'marketing-analytics-chat-connections',
			array( $this, 'render_connections_page' )
		);

		// Custom Prompts page
		add_submenu_page(
			'marketing-analytics-chat',
			__( 'Custom Prompts', 'marketing-analytics-chat' ),
			__( 'Custom Prompts', 'marketing-analytics-chat' ),
			'access_marketing_analytics', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'marketing-analytics-chat-prompts',
			array( $this, 'render_prompts_page' )
		);

		// Abilities Catalog page
		add_submenu_page(
			'marketing-analytics-chat',
			__( 'Abilities Catalog', 'marketing-analytics-chat' ),
			__( 'Abilities', 'marketing-analytics-chat' ),
			'access_marketing_analytics', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'marketing-analytics-chat-abilities',
			array( $this, 'render_abilities_page' )
		);

		// Settings page
		add_submenu_page(
			'marketing-analytics-chat',
			__( 'Settings', 'marketing-analytics-chat' ),
			__( 'Settings', 'marketing-analytics-chat' ),
			'access_marketing_analytics', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'marketing-analytics-chat-settings',
			array( $this, 'render_settings_page' )
		);

		// AI Chat page
		add_submenu_page(
			'marketing-analytics-chat',
			__( 'AI Assistant', 'marketing-analytics-chat' ),
			__( 'AI Assistant', 'marketing-analytics-chat' ),
			'access_marketing_analytics', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'marketing-analytics-chat-ai-assistant',
			array( $this, 'render_chat_page' )
		);

		/**
		 * Allow pro add-on to register additional submenu pages.
		 */
		do_action( 'marketing_analytics_mcp_admin_menu' );
	}

	/**
	 * Enqueue admin styles
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_styles( $hook ) {
		// Only load on our admin pages
		if ( strpos( $hook, 'marketing-analytics-chat' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'marketing-analytics-chat-admin',
			MARKETING_ANALYTICS_MCP_URL . 'admin/css/admin-styles.css',
			array(),
			MARKETING_ANALYTICS_MCP_VERSION
		);

		// Enqueue chat styles on AI Assistant page
		if ( strpos( $hook, 'marketing-analytics-chat-ai-assistant' ) !== false ) {
			wp_enqueue_style(
				'marketing-analytics-chat-interface',
				MARKETING_ANALYTICS_MCP_URL . 'admin/css/chat-interface.css',
				array(),
				MARKETING_ANALYTICS_MCP_VERSION
			);
		}

		// Enqueue wizard styles on settings page
		if ( strpos( $hook, 'marketing-analytics-chat-settings' ) !== false ) {
			wp_enqueue_style(
				'marketing-analytics-wizard',
				MARKETING_ANALYTICS_MCP_URL . 'admin/css/wizard.css',
				array(),
				MARKETING_ANALYTICS_MCP_VERSION
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
				'marketing-analytics-dashboard-widget',
				MARKETING_ANALYTICS_MCP_URL . 'admin/js/dashboard-widget.js',
				array( 'jquery' ),
				MARKETING_ANALYTICS_MCP_VERSION,
				true
			);

			wp_localize_script(
				'marketing-analytics-dashboard-widget',
				'macDashboardWidget',
				array(
					'nonce' => wp_create_nonce( 'marketing-analytics-chat-admin' ),
				)
			);
		}

		// Only load remaining scripts on our admin pages.
		if ( strpos( $hook, 'marketing-analytics-chat' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'marketing-analytics-chat-admin',
			MARKETING_ANALYTICS_MCP_URL . 'admin/js/admin-scripts.js',
			array( 'jquery' ),
			MARKETING_ANALYTICS_MCP_VERSION,
			true
		);

		// Localize script with data
		wp_localize_script(
			'marketing-analytics-chat-admin',
			'marketingAnalyticsMCP',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'marketing-analytics-chat-admin' ),
				'strings' => array(
					'testing'   => __( 'Testing connection...', 'marketing-analytics-chat' ),
					'success'   => __( 'Connection successful!', 'marketing-analytics-chat' ),
					'error'     => __( 'Connection failed', 'marketing-analytics-chat' ),
					'saveError' => __( 'Error saving settings', 'marketing-analytics-chat' ),
				),
			)
		);

		// Enqueue wizard script on settings page
		if ( strpos( $hook, 'marketing-analytics-chat-settings' ) !== false ) {
			wp_enqueue_script(
				'marketing-analytics-wizard',
				MARKETING_ANALYTICS_MCP_URL . 'admin/js/wizard.js',
				array( 'jquery' ),
				MARKETING_ANALYTICS_MCP_VERSION,
				true
			);

			wp_enqueue_script(
				'marketing-analytics-settings-tools',
				MARKETING_ANALYTICS_MCP_URL . 'admin/js/settings-tools.js',
				array( 'jquery' ),
				MARKETING_ANALYTICS_MCP_VERSION,
				true
			);
		}

		// Enqueue sparklines on the dashboard page.
		if ( 'toplevel_page_marketing-analytics-chat' === $hook ) {
			wp_enqueue_script(
				'marketing-analytics-sparklines',
				MARKETING_ANALYTICS_MCP_URL . 'admin/js/sparklines.js',
				array( 'jquery' ),
				MARKETING_ANALYTICS_MCP_VERSION,
				true
			);

			wp_localize_script(
				'marketing-analytics-sparklines',
				'macDashboardInsights',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'marketing-analytics-dashboard-insights' ),
				)
			);
		}

		// Enqueue onboarding wizard on dashboard page (toplevel only).
		if ( 'toplevel_page_marketing-analytics-chat' === $hook && ! get_option( 'marketing_analytics_mcp_onboarding_complete' ) ) {
			wp_enqueue_script(
				'marketing-analytics-onboarding-wizard',
				MARKETING_ANALYTICS_MCP_URL . 'admin/js/onboarding-wizard.js',
				array( 'jquery' ),
				MARKETING_ANALYTICS_MCP_VERSION,
				true
			);

			wp_localize_script(
				'marketing-analytics-onboarding-wizard',
				'macOnboardingWizard',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'marketing_analytics_mcp_dismiss_wizard' ),
				)
			);
		}

		// Enqueue chat interface script on AI Assistant page.
		if ( strpos( $hook, 'marketing-analytics-chat-ai-assistant' ) !== false ) {
			wp_enqueue_script(
				'marketing-analytics-chat-interface',
				MARKETING_ANALYTICS_MCP_URL . 'admin/js/chat-interface.js',
				array( 'jquery' ),
				MARKETING_ANALYTICS_MCP_VERSION,
				true
			);

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading conversation_id for localization only.
			$active_conversation_id = isset( $_GET['conversation_id'] ) ? absint( $_GET['conversation_id'] ) : 0;

			wp_localize_script(
				'marketing-analytics-chat-interface',
				'marketingAnalyticsMCPChat',
				array(
					'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
					'nonce'             => wp_create_nonce( 'marketing-analytics-chat-admin' ),
					'conversationId'    => $active_conversation_id ? $active_conversation_id : null,
					'userId'            => get_current_user_id(),
					'chatPageUrl'       => admin_url( 'admin.php?page=marketing-analytics-chat-ai-assistant' ),
					'conversationNonce' => wp_create_nonce( 'marketing_analytics_chat_conversation' ),
				)
			);
		}

		// Enqueue abilities catalog filter script.
		if ( strpos( $hook, 'marketing-analytics-chat-abilities' ) !== false ) {
			wp_enqueue_script(
				'marketing-analytics-abilities-catalog',
				MARKETING_ANALYTICS_MCP_URL . 'admin/js/abilities-catalog.js',
				array(),
				MARKETING_ANALYTICS_MCP_VERSION,
				true
			);
		}

		// Enqueue prompts page script.
		if ( strpos( $hook, 'marketing-analytics-chat-prompts' ) !== false ) {
			wp_enqueue_script(
				'marketing-analytics-prompts',
				MARKETING_ANALYTICS_MCP_URL . 'admin/js/prompts.js',
				array( 'jquery' ),
				MARKETING_ANALYTICS_MCP_VERSION,
				true
			);

			$custom_prompts = ( new \Marketing_Analytics_MCP\Prompts\Prompt_Manager() )->get_all_prompts();

			wp_localize_script(
				'marketing-analytics-prompts',
				'macPrompts',
				array(
					'customPrompts' => $custom_prompts,
					'strings'       => array(
						'invalidJson' => __( 'Invalid JSON in Arguments field. Please check your syntax.', 'marketing-analytics-chat' ),
					),
				)
			);
		}

		// Enqueue connection page scripts.
		if ( strpos( $hook, 'marketing-analytics-chat-connections' ) !== false ) {
			$this->enqueue_connection_scripts();
		}
	}

	/**
	 * Enqueue scripts for the connections page
	 */
	private function enqueue_connection_scripts() {
		$nonce = wp_create_nonce( 'marketing-analytics-chat-admin' );

		// GA4 connection script.
		$credential_manager = new \Marketing_Analytics_MCP\Credentials\Credential_Manager();
		$ga4_credentials    = $credential_manager->get_credentials( 'ga4' );
		$is_ga4_authed      = ! empty( $ga4_credentials ) && isset( $ga4_credentials['access_token'] );

		if ( $is_ga4_authed ) {
			wp_enqueue_script(
				'marketing-analytics-ga4-connection',
				MARKETING_ANALYTICS_MCP_URL . 'admin/js/ga4-connection.js',
				array( 'jquery' ),
				MARKETING_ANALYTICS_MCP_VERSION,
				true
			);

			wp_localize_script(
				'marketing-analytics-ga4-connection',
				'macGA4Connection',
				array(
					'nonce'           => $nonce,
					'savedPropertyId' => isset( $ga4_credentials['property_id'] ) ? $ga4_credentials['property_id'] : '',
					'strings'         => array(
						'loading'        => __( 'Loading properties...', 'marketing-analytics-chat' ),
						'selectProperty' => __( '-- Select a property --', 'marketing-analytics-chat' ),
						'loadFailed'     => __( 'Failed to load properties', 'marketing-analytics-chat' ),
						'loadError'      => __( 'Error loading properties', 'marketing-analytics-chat' ),
						'networkError'   => __( 'Network error. Please try again.', 'marketing-analytics-chat' ),
						'selectFirst'    => __( 'Please select a property', 'marketing-analytics-chat' ),
						'saving'         => __( 'Saving...', 'marketing-analytics-chat' ),
						'saveButton'     => __( 'Save Property Selection', 'marketing-analytics-chat' ),
					),
				)
			);
		}

		// GSC connection script.
		$gsc_credentials = $credential_manager->get_credentials( 'gsc' );
		$is_gsc_authed   = ! empty( $gsc_credentials ) && isset( $gsc_credentials['access_token'] );

		if ( $is_gsc_authed ) {
			wp_enqueue_script(
				'marketing-analytics-gsc-connection',
				MARKETING_ANALYTICS_MCP_URL . 'admin/js/gsc-connection.js',
				array( 'jquery' ),
				MARKETING_ANALYTICS_MCP_VERSION,
				true
			);

			wp_localize_script(
				'marketing-analytics-gsc-connection',
				'macGSCConnection',
				array(
					'nonce'        => $nonce,
					'savedSiteUrl' => isset( $gsc_credentials['site_url'] ) ? $gsc_credentials['site_url'] : '',
					'strings'      => array(
						'loading'        => __( 'Loading properties...', 'marketing-analytics-chat' ),
						'selectProperty' => __( '-- Select a property --', 'marketing-analytics-chat' ),
						'loadFailed'     => __( 'Failed to load properties', 'marketing-analytics-chat' ),
						'loadError'      => __( 'Error loading properties', 'marketing-analytics-chat' ),
						'networkError'   => __( 'Network error. Please try again.', 'marketing-analytics-chat' ),
						'selectFirst'    => __( 'Please select a property', 'marketing-analytics-chat' ),
						'saving'         => __( 'Saving...', 'marketing-analytics-chat' ),
						'saveButton'     => __( 'Save Property Selection', 'marketing-analytics-chat' ),
					),
				)
			);
		}
	}

	/**
	 * Render dashboard page
	 */
	public function render_dashboard_page() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once MARKETING_ANALYTICS_MCP_PATH . 'admin/views/dashboard.php';
	}

	/**
	 * Render connections page
	 */
	public function render_connections_page() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once MARKETING_ANALYTICS_MCP_PATH . 'admin/views/connections.php';
	}

	/**
	 * Render prompts page
	 */
	public function render_prompts_page() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once MARKETING_ANALYTICS_MCP_PATH . 'admin/views/prompts.php';
	}

	/**
	 * Render abilities catalog page
	 */
	public function render_abilities_page() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once MARKETING_ANALYTICS_MCP_PATH . 'admin/views/abilities-catalog.php';
	}

	/**
	 * Render AI chat page
	 */
	public function render_chat_page() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once MARKETING_ANALYTICS_MCP_PATH . 'admin/views/chat.php';
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		require_once MARKETING_ANALYTICS_MCP_PATH . 'admin/views/settings.php';
	}

	/**
	 * Register the WordPress dashboard widget
	 */
	public function register_dashboard_widget() {
		if ( ! Permission_Manager::can_access_plugin() ) {
			return;
		}

		wp_add_dashboard_widget(
			'marketing_analytics_dashboard_widget',
			__( 'Marketing Analytics', 'marketing-analytics-chat' ),
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

		require_once MARKETING_ANALYTICS_MCP_PATH . 'admin/views/widgets/dashboard-widget.php';
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

		$anomalies    = get_option( 'marketing_analytics_recent_anomalies', array() );
		$anomalies    = is_array( $anomalies ) ? $anomalies : array();
		$health_class = $this->get_health_class( $anomalies );
		$badge_count  = count( $anomalies );

		// Build title with health dot and optional badge.
		$title  = '<span class="mac-pulse-dot ' . esc_attr( $health_class ) . '"></span> ';
		$title .= esc_html__( 'Analytics', 'marketing-analytics-chat' );

		if ( $badge_count > 0 ) {
			$title .= ' <span class="mac-anomaly-badge">' . esc_html( $badge_count ) . '</span>';
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'marketing-analytics-pulse',
				'title' => $title,
				'href'  => esc_url( admin_url( 'admin.php?page=marketing-analytics-chat' ) ),
				'meta'  => array(
					'class' => 'marketing-analytics-pulse-node',
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
					'id'     => 'marketing-analytics-anomaly-' . $index,
					'parent' => 'marketing-analytics-pulse',
					'title'  => '<span class="mac-bar-severity mac-bar-severity-' . esc_attr( $severity ) . '">' . esc_html( ucfirst( $severity ) ) . '</span> ' . esc_html( $anomaly_title ),
					'href'   => esc_url( admin_url( 'admin.php?page=marketing-analytics-chat' ) ),
				)
			);
		}

		// "View Dashboard" link as final child node.
		$wp_admin_bar->add_node(
			array(
				'id'     => 'marketing-analytics-view-dashboard',
				'parent' => 'marketing-analytics-pulse',
				'title'  => esc_html__( 'View Dashboard', 'marketing-analytics-chat' ),
				'href'   => esc_url( admin_url( 'admin.php?page=marketing-analytics-chat' ) ),
			)
		);

		/**
		 * Fires after admin bar items are added, allowing premium to extend.
		 *
		 * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
		 */
		do_action( 'marketing_analytics_mcp_admin_bar_items', $wp_admin_bar );
	}

	/**
	 * Output inline CSS for admin bar analytics pulse
	 *
	 * Outputs styles early since the admin bar loads before plugin stylesheets.
	 */
	public function admin_bar_styles() {
		if ( ! is_admin() || ! Permission_Manager::can_access_plugin() ) {
			return;
		}
		?>
		<style>
			#wp-admin-bar-marketing-analytics-pulse .mac-pulse-dot {
				display: inline-block;
				width: 10px;
				height: 10px;
				border-radius: 50%;
				margin-right: 4px;
				vertical-align: middle;
			}
			#wp-admin-bar-marketing-analytics-pulse .mac-pulse-dot.healthy {
				background-color: #00a32a;
			}
			#wp-admin-bar-marketing-analytics-pulse .mac-pulse-dot.warning {
				background-color: #dba617;
			}
			#wp-admin-bar-marketing-analytics-pulse .mac-pulse-dot.critical {
				background-color: #d63638;
			}
			#wp-admin-bar-marketing-analytics-pulse .mac-anomaly-badge {
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
			#wp-admin-bar-marketing-analytics-pulse .mac-bar-severity {
				display: inline-block;
				padding: 1px 6px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 600;
				margin-right: 4px;
			}
			#wp-admin-bar-marketing-analytics-pulse .mac-bar-severity-critical {
				background: #d63638;
				color: #fff;
			}
			#wp-admin-bar-marketing-analytics-pulse .mac-bar-severity-high {
				background: #e65100;
				color: #fff;
			}
			#wp-admin-bar-marketing-analytics-pulse .mac-bar-severity-medium {
				background: #dba617;
				color: #1d2327;
			}
			#wp-admin-bar-marketing-analytics-pulse .mac-bar-severity-low {
				background: #00a32a;
				color: #fff;
			}
			#wp-admin-bar-marketing-analytics-pulse .ab-submenu .ab-item {
				height: auto !important;
				line-height: 1.4 !important;
				padding: 6px 10px !important;
			}
		</style>
		<?php
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
