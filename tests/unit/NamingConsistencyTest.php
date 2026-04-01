<?php
/**
 * Tests naming consistency across the entire plugin.
 *
 * Validates that text domains, option prefixes, hook names, menu slugs,
 * constants, AJAX actions, and CSS classes all follow the expected patterns.
 * This test is critical for catching issues during plugin renames.
 *
 * @package Specflux_Marketing_Analytics
 */

namespace Specflux_Marketing_Analytics\Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Naming consistency test class.
 */
class NamingConsistencyTest extends TestCase {

	/**
	 * Expected text domain used in all translation functions.
	 *
	 * @var string
	 */
	private $expected_text_domain = 'specflux-marketing-analytics-chat';

	/**
	 * Expected slug used in menu pages and URLs.
	 *
	 * @var string
	 */
	private $expected_slug = 'specflux-marketing-analytics-chat';

	/**
	 * Expected option prefix for database options.
	 *
	 * @var string
	 */
	private $expected_option_prefix = 'specflux_mac_';

	/**
	 * Expected hook prefix for actions and filters.
	 *
	 * @var string
	 */
	private $expected_hook_prefix = 'specflux_mac_';

	/**
	 * Expected constant prefix.
	 *
	 * @var string
	 */
	private $expected_constant_prefix = 'SPECFLUX_MAC_';

	/**
	 * Expected namespace.
	 *
	 * @var string
	 */
	private $expected_namespace = 'Specflux_Marketing_Analytics';

	/**
	 * Expected AJAX action prefix.
	 *
	 * @var string
	 */
	private $expected_ajax_prefix = 'specflux_mac_';

	/**
	 * Get all PHP files in the plugin directory (excluding vendor and tests).
	 *
	 * @return array List of PHP file paths.
	 */
	private function get_plugin_php_files() {
		$plugin_dir = SPECFLUX_MAC_PATH;
		$files      = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $plugin_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			$path = $file->getPathname();

			// Skip vendor and tests directories.
			if ( strpos( $path, '/vendor/' ) !== false || strpos( $path, '/tests/' ) !== false ) {
				continue;
			}

			$files[] = $path;
		}

		return $files;
	}

	/**
	 * Test that all translation function calls use the expected text domain.
	 */
	public function test_text_domain_consistency(): void {
		$files  = $this->get_plugin_php_files();
		$errors = array();

		// Match __(), _e(), esc_html__(), esc_html_e(), esc_attr__(), esc_attr_e(), _n(), _x(), _nx()
		$pattern = '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_n|_x|_nx)\s*\([^)]*,\s*[\'"]([^\'"]+)[\'"]\s*\)/';

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			$lines   = explode( "\n", $content );

			foreach ( $lines as $line_num => $line ) {
				if ( preg_match_all( $pattern, $line, $matches ) ) {
					foreach ( $matches[1] as $domain ) {
						if ( $domain !== $this->expected_text_domain ) {
							$relative = str_replace( SPECFLUX_MAC_PATH, '', $file );
							$errors[] = "{$relative}:" . ( $line_num + 1 ) . " uses text domain '{$domain}' instead of '{$this->expected_text_domain}'";
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$errors,
			"Text domain inconsistencies found:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test that the plugin header text domain matches the expected slug.
	 */
	public function test_plugin_header_text_domain(): void {
		$main_file = SPECFLUX_MAC_PATH . 'specflux-marketing-analytics-chat.php';
		$this->assertFileExists( $main_file, 'Main plugin file should exist' );

		$content = file_get_contents( $main_file );

		$this->assertMatchesRegularExpression(
			'/Text Domain:\s*' . preg_quote( $this->expected_text_domain, '/' ) . '/',
			$content,
			'Plugin header Text Domain should match expected slug'
		);
	}

	/**
	 * Test that Plugin Name in header is set correctly.
	 */
	public function test_plugin_header_name(): void {
		$main_file = SPECFLUX_MAC_PATH . 'specflux-marketing-analytics-chat.php';
		$content   = file_get_contents( $main_file );

		$this->assertMatchesRegularExpression(
			'/Plugin Name:\s*.+/',
			$content,
			'Plugin header must have a Plugin Name'
		);

		// Extract plugin name.
		preg_match( '/Plugin Name:\s*(.+)/', $content, $matches );
		$plugin_name = trim( $matches[1] );

		$this->assertNotEmpty( $plugin_name, 'Plugin Name should not be empty' );

		// Plugin name is validated — readme cross-reference tested separately.
	}

	/**
	 * Test that readme.txt plugin name matches plugin header.
	 */
	public function test_readme_matches_plugin_header(): void {
		$main_file = SPECFLUX_MAC_PATH . 'specflux-marketing-analytics-chat.php';
		$content   = file_get_contents( $main_file );

		preg_match( '/Plugin Name:\s*(.+)/', $content, $header_matches );
		$header_name = trim( $header_matches[1] );

		$readme_file = SPECFLUX_MAC_PATH . 'readme.txt';
		$this->assertFileExists( $readme_file, 'readme.txt should exist' );

		$readme_content = file_get_contents( $readme_file );

		// readme.txt first line should be === Plugin Name ===
		preg_match( '/^===\s*(.+?)\s*===/', $readme_content, $readme_matches );
		$this->assertNotEmpty( $readme_matches, 'readme.txt should have === Plugin Name === header' );

		$readme_name = trim( $readme_matches[1] );

		$this->assertEquals(
			$header_name,
			$readme_name,
			'Plugin name in readme.txt must match Plugin Name in main plugin header'
		);
	}

	/**
	 * Test that all defined constants use the expected prefix.
	 */
	public function test_constant_prefix_consistency(): void {
		$main_file = SPECFLUX_MAC_PATH . 'specflux-marketing-analytics-chat.php';
		$content   = file_get_contents( $main_file );

		// Find all define() calls in the main plugin file.
		preg_match_all( "/define\s*\(\s*'([^']+)'/", $content, $matches );

		$this->assertNotEmpty( $matches[1], 'Main plugin file should define constants' );

		foreach ( $matches[1] as $constant ) {
			$this->assertStringStartsWith(
				$this->expected_constant_prefix,
				$constant,
				"Constant '{$constant}' should start with '{$this->expected_constant_prefix}'"
			);
		}
	}

	/**
	 * Test that SPECFLUX_MAC_BASENAME matches main file slug.
	 */
	public function test_basename_constant_matches_slug(): void {
		$basename = SPECFLUX_MAC_BASENAME;

		$this->assertStringContainsString(
			$this->expected_slug,
			$basename,
			'SPECFLUX_MAC_BASENAME should contain the plugin slug'
		);

		// Should be in format: slug/slug.php
		$expected_basename = $this->expected_slug . '/' . $this->expected_slug . '.php';
		$this->assertEquals(
			$expected_basename,
			$basename,
			'BASENAME constant should be slug/slug.php'
		);
	}

	/**
	 * Test that all option names use the expected prefix.
	 */
	public function test_option_name_consistency(): void {
		$files  = $this->get_plugin_php_files();
		$errors = array();

		// Match get_option/update_option/add_option/delete_option calls with string literals.
		$pattern = '/\b(?:get_option|update_option|add_option|delete_option)\s*\(\s*[\'"]([^\'"]+)[\'"]/';

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			$lines   = explode( "\n", $content );

			foreach ( $lines as $line_num => $line ) {
				if ( preg_match_all( $pattern, $line, $matches ) ) {
					foreach ( $matches[1] as $option_name ) {
						// Skip WordPress core options.
						if ( strpos( $option_name, 'siteurl' ) === 0 || strpos( $option_name, 'blogname' ) === 0 ) {
							continue;
						}

						if ( strpos( $option_name, $this->expected_option_prefix ) !== 0 ) {
							$relative = str_replace( SPECFLUX_MAC_PATH, '', $file );
							$errors[] = "{$relative}:" . ( $line_num + 1 ) . " option '{$option_name}' doesn't use prefix '{$this->expected_option_prefix}'";
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$errors,
			"Option name inconsistencies found:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test that option name constants in classes use the expected prefix.
	 */
	public function test_option_constants_use_correct_prefix(): void {
		$classes_with_options = array(
			'Specflux_Marketing_Analytics\Credentials\Credential_Manager' => 'OPTION_PREFIX',
			'Specflux_Marketing_Analytics\Credentials\Encryption'         => 'KEY_OPTION',
			'Specflux_Marketing_Analytics\Prompts\Prompt_Manager'         => 'OPTION_NAME',
		);

		foreach ( $classes_with_options as $class => $constant ) {
			$this->assertTrue(
				class_exists( $class ),
				"Class {$class} should exist"
			);

			$reflection = new \ReflectionClass( $class );
			$value      = $reflection->getConstant( $constant );

			$this->assertNotFalse( $value, "{$class}::{$constant} should be defined" );
			$this->assertStringStartsWith(
				$this->expected_option_prefix,
				$value,
				"{$class}::{$constant} value '{$value}' should start with '{$this->expected_option_prefix}'"
			);
		}
	}

	/**
	 * Test that all hook names (do_action/apply_filters) use the expected prefix.
	 */
	public function test_hook_name_consistency(): void {
		$files  = $this->get_plugin_php_files();
		$errors = array();

		// Match do_action() and apply_filters() with literal hook names.
		$pattern = '/\b(?:do_action|apply_filters)\s*\(\s*[\'"]([^\'"]+)[\'"]/';

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			$lines   = explode( "\n", $content );

			foreach ( $lines as $line_num => $line ) {
				if ( preg_match_all( $pattern, $line, $matches ) ) {
					foreach ( $matches[1] as $hook_name ) {
						// Skip WordPress core hooks.
						if ( strpos( $hook_name, 'admin_' ) === 0 ||
							strpos( $hook_name, 'wp_' ) === 0 ||
							strpos( $hook_name, 'plugins_' ) === 0 ||
							strpos( $hook_name, 'init' ) === 0 ||
							strpos( $hook_name, 'widgets_' ) === 0 ) {
							continue;
						}

						if ( strpos( $hook_name, $this->expected_hook_prefix ) !== 0 ) {
							$relative = str_replace( SPECFLUX_MAC_PATH, '', $file );
							$errors[] = "{$relative}:" . ( $line_num + 1 ) . " hook '{$hook_name}' doesn't use prefix '{$this->expected_hook_prefix}'";
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$errors,
			"Hook name inconsistencies found:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test that all AJAX action registrations use the expected prefix.
	 */
	public function test_ajax_action_consistency(): void {
		$files  = $this->get_plugin_php_files();
		$errors = array();

		// Match add_action('wp_ajax_...')
		$pattern = "/add_action\s*\(\s*['\"]wp_ajax_([^'\"]+)['\"]/";

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			$lines   = explode( "\n", $content );

			foreach ( $lines as $line_num => $line ) {
				if ( preg_match_all( $pattern, $line, $matches ) ) {
					foreach ( $matches[1] as $action_name ) {
						if ( strpos( $action_name, $this->expected_ajax_prefix ) !== 0 ) {
							$relative = str_replace( SPECFLUX_MAC_PATH, '', $file );
							$errors[] = "{$relative}:" . ( $line_num + 1 ) . " AJAX action '{$action_name}' doesn't use prefix '{$this->expected_ajax_prefix}'";
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$errors,
			"AJAX action inconsistencies found:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test that admin menu slugs use the expected plugin slug.
	 */
	public function test_menu_slug_consistency(): void {
		$files  = $this->get_plugin_php_files();
		$errors = array();

		// Match add_menu_page and add_submenu_page slug parameters.
		// add_menu_page: 7th param (0-indexed: param 3 is slug).
		// add_submenu_page: param 0 is parent slug, param 3 is slug.
		$menu_pattern    = "/add_menu_page\s*\([^)]*?['\"]({$this->expected_slug}[^'\"]*?)['\"]/s";
		$submenu_pattern = "/add_submenu_page\s*\(\s*['\"]([^'\"]+)['\"]/";

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );

			// Check submenu parent slugs.
			if ( preg_match_all( $submenu_pattern, $content, $matches ) ) {
				foreach ( $matches[1] as $parent_slug ) {
					if ( strpos( $parent_slug, $this->expected_slug ) !== 0 ) {
						$relative = str_replace( SPECFLUX_MAC_PATH, '', $file );
						$errors[] = "{$relative}: submenu parent slug '{$parent_slug}' should start with '{$this->expected_slug}'";
					}
				}
			}
		}

		$this->assertEmpty(
			$errors,
			"Menu slug inconsistencies found:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test that nonce actions use consistent naming.
	 */
	public function test_nonce_action_consistency(): void {
		$files  = $this->get_plugin_php_files();
		$errors = array();

		// Match wp_verify_nonce and wp_create_nonce calls.
		$pattern = "/(?:wp_verify_nonce|wp_create_nonce)\s*\([^,]*,\s*['\"]([^'\"]+)['\"]/";

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			$lines   = explode( "\n", $content );

			foreach ( $lines as $line_num => $line ) {
				if ( preg_match_all( $pattern, $line, $matches ) ) {
					foreach ( $matches[1] as $nonce_action ) {
						// Nonce actions should contain the plugin slug or option prefix.
						if ( strpos( $nonce_action, 'specflux' ) === false &&
							strpos( $nonce_action, 'wp_rest' ) === false ) {
							$relative = str_replace( SPECFLUX_MAC_PATH, '', $file );
							$errors[] = "{$relative}:" . ( $line_num + 1 ) . " nonce action '{$nonce_action}' doesn't reference the plugin";
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$errors,
			"Nonce action inconsistencies found:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test that all PHP files use the expected namespace.
	 */
	public function test_namespace_consistency(): void {
		$files  = $this->get_plugin_php_files();
		$errors = array();

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );

			// Skip main plugin file (uses namespace at top level).
			if ( basename( $file ) === 'specflux-marketing-analytics-chat.php' ) {
				// Main file should have namespace declaration.
				$this->assertStringContainsString(
					"namespace {$this->expected_namespace};",
					$content,
					'Main plugin file should use expected namespace'
				);
				continue;
			}

			// Check namespace declarations in includes/ files.
			if ( strpos( $file, '/includes/' ) !== false ) {
				if ( preg_match( '/namespace\s+([^;]+);/', $content, $ns_matches ) ) {
					$namespace = $ns_matches[1];
					if ( strpos( $namespace, $this->expected_namespace ) !== 0 ) {
						$relative = str_replace( SPECFLUX_MAC_PATH, '', $file );
						$errors[] = "{$relative}: namespace '{$namespace}' should start with '{$this->expected_namespace}'";
					}
				}
			}
		}

		$this->assertEmpty(
			$errors,
			"Namespace inconsistencies found:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test that transient names use the expected prefix pattern.
	 */
	public function test_transient_prefix_consistency(): void {
		$files  = $this->get_plugin_php_files();
		$errors = array();

		// Match set_transient/get_transient/delete_transient with literal names.
		$pattern = '/\b(?:set_transient|get_transient|delete_transient)\s*\(\s*[\'"]([^\'"]+)[\'"]/';

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			$lines   = explode( "\n", $content );

			foreach ( $lines as $line_num => $line ) {
				if ( preg_match_all( $pattern, $line, $matches ) ) {
					foreach ( $matches[1] as $transient_name ) {
						// Transients should reference the specflux_mac prefix.
						if ( strpos( $transient_name, 'specflux_mac' ) === false ) {
							$relative = str_replace( SPECFLUX_MAC_PATH, '', $file );
							$errors[] = "{$relative}:" . ( $line_num + 1 ) . " transient '{$transient_name}' doesn't use expected prefix";
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$errors,
			"Transient prefix inconsistencies found:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test that database table names use the expected prefix.
	 */
	public function test_database_table_prefix(): void {
		$files  = $this->get_plugin_php_files();
		$errors = array();

		// Match $wpdb->prefix . 'specflux_mac_...' patterns.
		$pattern = '/\$wpdb->prefix\s*\.\s*[\'"]([^\'"]+)[\'"]/';

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			$lines   = explode( "\n", $content );

			foreach ( $lines as $line_num => $line ) {
				if ( preg_match_all( $pattern, $line, $matches ) ) {
					foreach ( $matches[1] as $table_suffix ) {
						if ( strpos( $table_suffix, 'specflux_mac_' ) !== 0 ) {
							$relative = str_replace( SPECFLUX_MAC_PATH, '', $file );
							$errors[] = "{$relative}:" . ( $line_num + 1 ) . " table '{$table_suffix}' doesn't use prefix 'specflux_mac_'";
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$errors,
			"Database table prefix inconsistencies found:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test that script and style handles use the expected prefix.
	 */
	public function test_script_handle_consistency(): void {
		$files  = $this->get_plugin_php_files();
		$errors = array();

		// Match wp_enqueue_script/wp_enqueue_style handle parameter.
		$pattern = '/\bwp_(?:enqueue|register)_(?:script|style)\s*\(\s*[\'"]([^\'"]+)[\'"]/';

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			$lines   = explode( "\n", $content );

			foreach ( $lines as $line_num => $line ) {
				if ( preg_match_all( $pattern, $line, $matches ) ) {
					foreach ( $matches[1] as $handle ) {
						// Skip WordPress core handles.
						if ( strpos( $handle, 'wp-' ) === 0 || strpos( $handle, 'jquery' ) === 0 ) {
							continue;
						}

						if ( strpos( $handle, $this->expected_slug ) !== 0 ) {
							$relative = str_replace( SPECFLUX_MAC_PATH, '', $file );
							$errors[] = "{$relative}:" . ( $line_num + 1 ) . " handle '{$handle}' should start with '{$this->expected_slug}'";
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$errors,
			"Script/style handle inconsistencies found:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test that CSS class usage in PHP views uses expected prefix.
	 */
	public function test_css_class_prefix_in_views(): void {
		$views_dir = SPECFLUX_MAC_PATH . 'admin/views/';

		if ( ! is_dir( $views_dir ) ) {
			$this->markTestSkipped( 'Views directory not found.' );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $views_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		$found_mac_classes = false;

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			$content = file_get_contents( $file->getPathname() );

			// Check for .smac- class usage in HTML.
			if ( preg_match( '/class=["\'][^"\']*\bsmac-/', $content ) ) {
				$found_mac_classes = true;
			}
		}

		$this->assertTrue(
			$found_mac_classes,
			'Views should use smac- CSS class prefix'
		);
	}

	/**
	 * Test that CSS file uses --smac- design token prefix.
	 */
	public function test_css_design_tokens(): void {
		$css_file = SPECFLUX_MAC_PATH . 'admin/css/admin-styles.css';

		if ( ! file_exists( $css_file ) ) {
			$this->markTestSkipped( 'CSS file not found.' );
		}

		$content = file_get_contents( $css_file );

		$this->assertStringContainsString(
			'--smac-',
			$content,
			'CSS file should use --smac- design token prefix'
		);
	}

	/**
	 * Test that composer.json name matches plugin slug.
	 */
	public function test_composer_name_matches_slug(): void {
		$composer_file = SPECFLUX_MAC_PATH . 'composer.json';
		$this->assertFileExists( $composer_file, 'composer.json should exist' );

		$composer = json_decode( file_get_contents( $composer_file ), true );
		$this->assertNotNull( $composer, 'composer.json should be valid JSON' );
		$this->assertArrayHasKey( 'name', $composer, 'composer.json should have a name field' );

		$this->assertStringContainsString(
			$this->expected_slug,
			$composer['name'],
			'composer.json name should contain the plugin slug'
		);
	}

	/**
	 * Test that the .pot translation file references match.
	 */
	public function test_pot_file_references(): void {
		$pot_file = SPECFLUX_MAC_PATH . 'languages/specflux-marketing-analytics-chat.pot';

		if ( ! file_exists( $pot_file ) ) {
			$this->markTestSkipped( '.pot translation file not found.' );
		}

		$content = file_get_contents( $pot_file );

		$this->assertStringContainsString(
			'X-Domain: ' . $this->expected_text_domain,
			$content,
			'.pot file X-Domain should match text domain'
		);
	}

	/**
	 * Test that admin page URLs reference the correct slug.
	 */
	public function test_admin_url_slug_references(): void {
		$files  = $this->get_plugin_php_files();
		$errors = array();

		// Match admin.php?page= references.
		$pattern = '/admin\.php\?page=([a-z0-9_-]+)/';

		foreach ( $files as $file ) {
			$content = file_get_contents( $file );
			$lines   = explode( "\n", $content );

			foreach ( $lines as $line_num => $line ) {
				if ( preg_match_all( $pattern, $line, $matches ) ) {
					foreach ( $matches[1] as $page_slug ) {
						if ( strpos( $page_slug, $this->expected_slug ) !== 0 ) {
							$relative = str_replace( SPECFLUX_MAC_PATH, '', $file );
							$errors[] = "{$relative}:" . ( $line_num + 1 ) . " admin page slug '{$page_slug}' should start with '{$this->expected_slug}'";
						}
					}
				}
			}
		}

		$this->assertEmpty(
			$errors,
			"Admin URL slug inconsistencies found:\n" . implode( "\n", $errors )
		);
	}

	/**
	 * Test that all expected extensibility hooks exist in the codebase.
	 */
	public function test_required_extensibility_hooks_exist(): void {
		$required_hooks = array(
			'specflux_mac_loaded',
			'specflux_mac_register_pro_abilities',
			'specflux_mac_admin_menu',
			'specflux_mac_register_ajax_handlers',
			'specflux_mac_all_platforms_connected',
		);

		$files        = $this->get_plugin_php_files();
		$all_content  = '';

		foreach ( $files as $file ) {
			$all_content .= file_get_contents( $file );
		}

		foreach ( $required_hooks as $hook ) {
			$this->assertStringContainsString(
				"'{$hook}'",
				$all_content,
				"Required extensibility hook '{$hook}' should exist in the codebase"
			);
		}
	}

	/**
	 * Test that the main plugin file name matches the slug.
	 */
	public function test_main_file_name_matches_slug(): void {
		$expected_file = SPECFLUX_MAC_PATH . $this->expected_slug . '.php';
		$this->assertFileExists(
			$expected_file,
			"Main plugin file should be named '{$this->expected_slug}.php'"
		);
	}

	/**
	 * Test that cache key prefix is consistent.
	 */
	public function test_cache_prefix_constant(): void {
		$this->assertTrue(
			class_exists( 'Specflux_Marketing_Analytics\Cache\Cache_Manager' ),
			'Cache_Manager class should exist'
		);

		$reflection = new \ReflectionClass( 'Specflux_Marketing_Analytics\Cache\Cache_Manager' );
		$prefix     = $reflection->getConstant( 'CACHE_PREFIX' );

		$this->assertNotFalse( $prefix, 'CACHE_PREFIX constant should be defined' );
		$this->assertStringContainsString(
			'specflux',
			$prefix,
			'CACHE_PREFIX should reference the plugin name'
		);
	}

	/**
	 * Test that ability names use the expected prefix pattern.
	 */
	public function test_ability_name_prefix(): void {
		// Register abilities.
		$registrar = new \Specflux_Marketing_Analytics\Abilities\Abilities_Registrar();
		$registrar->reset();
		$registrar->register_all_abilities();

		$tools = $registrar->get_registered_tools();

		if ( empty( $tools ) ) {
			$this->markTestSkipped( 'No abilities registered (credentials not configured).' );
		}

		foreach ( $tools as $tool_name => $tool_config ) {
			$name = is_string( $tool_name ) ? $tool_name : ( $tool_config['name'] ?? '' );
			if ( ! empty( $name ) ) {
				$this->assertMatchesRegularExpression(
					'/^marketing-analytics\//',
					$name,
					"Ability name '{$name}' should start with 'marketing-analytics/'"
				);
			}
		}
	}

	/**
	 * Test that the chat cache group uses expected prefix.
	 */
	public function test_chat_cache_group(): void {
		$this->assertTrue(
			class_exists( 'Specflux_Marketing_Analytics\Chat\Chat_Manager' ),
			'Chat_Manager class should exist'
		);

		$reflection = new \ReflectionClass( 'Specflux_Marketing_Analytics\Chat\Chat_Manager' );
		$property   = $reflection->getProperty( 'cache_group' );
		$property->setAccessible( true );

		$manager     = new \Specflux_Marketing_Analytics\Chat\Chat_Manager();
		$cache_group = $property->getValue( $manager );

		$this->assertStringContainsString(
			'specflux_mac',
			$cache_group,
			'Chat_Manager cache group should reference the plugin name'
		);
	}
}
