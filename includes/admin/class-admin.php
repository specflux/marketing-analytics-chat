<?php
/**
 * Admin Interface Handler
 *
 * @package Marketing_Analytics_MCP
 */

namespace Marketing_Analytics_MCP\Admin;

use Marketing_Analytics_MCP\Utils\Permission_Manager;

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

		// Settings page
		add_submenu_page(
			'marketing-analytics-chat',
			__( 'Settings', 'marketing-analytics-chat' ),
			__( 'Settings', 'marketing-analytics-chat' ),
			'access_marketing_analytics', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability registered on activation.
			'marketing-analytics-chat-settings',
			array( $this, 'render_settings_page' )
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
		// Only load on our admin pages
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
}
