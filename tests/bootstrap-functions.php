<?php
/**
 * Namespaced plugin functions for tests.
 *
 * The main plugin file re-defines constants without guards,
 * so we replicate its namespaced functions here for testing.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics;

if ( ! function_exists( 'Specflux_Marketing_Analytics\check_plugin_dependencies' ) ) {
	/**
	 * Mock plugin dependency check.
	 *
	 * @return bool
	 */
	function check_plugin_dependencies() {
		return true;
	}
}

if ( ! function_exists( 'Specflux_Marketing_Analytics\activate_specflux_mac' ) ) {
	/**
	 * Activation hook callback.
	 */
	function activate_specflux_mac() {
		Activator::activate();
	}
}

if ( ! function_exists( 'Specflux_Marketing_Analytics\deactivate_specflux_mac' ) ) {
	/**
	 * Deactivation hook callback.
	 */
	function deactivate_specflux_mac() {
		Deactivator::deactivate();
	}
}

if ( ! function_exists( 'Specflux_Marketing_Analytics\run_specflux_mac' ) ) {
	/**
	 * Initialize and run the plugin.
	 */
	function run_specflux_mac() {
		if ( ! check_plugin_dependencies() ) {
			return;
		}

		if ( ! class_exists( __NAMESPACE__ . '\Plugin' ) ) {
			return;
		}

		$plugin = new Plugin();
		$plugin->run();
	}
}
