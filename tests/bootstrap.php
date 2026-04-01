<?php
/**
 * PHPUnit bootstrap file for Specflux Marketing Analytics Chat plugin tests.
 *
 * @package Specflux_Marketing_Analytics
 */

// Define WordPress constants for testing.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

// WordPress database constants
if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'wordpress_test' );
}

if ( ! defined( 'DB_USER' ) ) {
	define( 'DB_USER', 'root' );
}

if ( ! defined( 'DB_PASSWORD' ) ) {
	define( 'DB_PASSWORD', '' );
}

if ( ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', 'localhost' );
}

if ( ! defined( 'DB_CHARSET' ) ) {
	define( 'DB_CHARSET', 'utf8mb4' );
}

if ( ! defined( 'DB_COLLATE' ) ) {
	define( 'DB_COLLATE', '' );
}

// WordPress time constants
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
	define( 'MONTH_IN_SECONDS', 2592000 );
}

if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
	define( 'YEAR_IN_SECONDS', 31536000 );
}

// Define plugin constants.
define( 'SPECFLUX_MAC_VERSION', '1.0.0' );
define( 'SPECFLUX_MAC_PATH', dirname( __DIR__ ) . '/' );
define( 'SPECFLUX_MAC_URL', 'http://localhost/wp-content/plugins/specflux-marketing-analytics-chat/' );
define( 'SPECFLUX_MAC_BASENAME', 'specflux-marketing-analytics-chat/specflux-marketing-analytics-chat.php' );

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Mock WP_Error class
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors    = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( empty( $code ) ) {
				return;
			}
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		public function get_error_code() {
			$codes = $this->get_error_codes();
			return ! empty( $codes ) ? $codes[0] : '';
		}

		public function get_error_messages( $code = '' ) {
			if ( empty( $code ) ) {
				$all = array();
				foreach ( $this->errors as $messages ) {
					$all = array_merge( $all, $messages );
				}
				return $all;
			}
			return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			$messages = $this->get_error_messages( $code );
			return ! empty( $messages ) ? $messages[0] : '';
		}

		public function get_error_data( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function has_errors() {
			return ! empty( $this->errors );
		}
	}
}

// Mock WP_Http_Cookie class
if ( ! class_exists( 'WP_Http_Cookie' ) ) {
	class WP_Http_Cookie {
		public $name;
		public $value;

		public function __construct( $data = array() ) {
			$this->name  = $data['name'] ?? '';
			$this->value = $data['value'] ?? '';
		}
	}
}

// Mock WordPress role classes
if ( ! class_exists( 'WP_Role' ) ) {
	/**
	 * Mock WP_Role class.
	 */
	class WP_Role {
		/**
		 * Role name.
		 *
		 * @var string
		 */
		public $name;

		/**
		 * Role capabilities.
		 *
		 * @var array
		 */
		public $capabilities = array();

		/**
		 * Constructor.
		 *
		 * @param string $role Role name.
		 * @param array  $capabilities Role capabilities.
		 */
		public function __construct( $role, $capabilities = array() ) {
			$this->name         = $role;
			$this->capabilities = $capabilities;
		}

		/**
		 * Check if role has capability.
		 *
		 * @param string $cap Capability name.
		 * @return bool
		 */
		public function has_cap( $cap ) {
			return isset( $this->capabilities[ $cap ] ) && $this->capabilities[ $cap ];
		}

		/**
		 * Add capability to role.
		 *
		 * @param string $cap Capability name.
		 * @param bool   $grant Grant capability.
		 */
		public function add_cap( $cap, $grant = true ) {
			$this->capabilities[ $cap ] = $grant;
		}

		/**
		 * Remove capability from role.
		 *
		 * @param string $cap Capability name.
		 */
		public function remove_cap( $cap ) {
			unset( $this->capabilities[ $cap ] );
		}
	}
}

if ( ! class_exists( 'WP_Roles' ) ) {
	/**
	 * Mock WP_Roles class.
	 */
	class WP_Roles {
		/**
		 * List of roles.
		 *
		 * @var array
		 */
		public $roles = array();

		/**
		 * Role objects.
		 *
		 * @var array
		 */
		public $role_objects = array();

		/**
		 * Constructor.
		 */
		public function __construct() {
			// Initialize default WordPress roles
			$this->roles = array(
				'administrator' => array(
					'name'         => 'Administrator',
					'capabilities' => array(
						'manage_options' => true,
					),
				),
				'editor'        => array(
					'name'         => 'Editor',
					'capabilities' => array(
						'edit_posts' => true,
					),
				),
				'author'        => array(
					'name'         => 'Author',
					'capabilities' => array(
						'edit_posts' => true,
					),
				),
				'contributor'   => array(
					'name'         => 'Contributor',
					'capabilities' => array(
						'edit_posts' => true,
					),
				),
				'subscriber'    => array(
					'name'         => 'Subscriber',
					'capabilities' => array(
						'read' => true,
					),
				),
			);

			// Create role objects
			foreach ( $this->roles as $role_slug => $role_data ) {
				$this->role_objects[ $role_slug ] = new WP_Role( $role_slug, $role_data['capabilities'] );
			}
		}
	}
}

// Mock WordPress functions for unit tests.
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Mock get_option function.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		global $mock_options;
		return isset( $mock_options[ $option ] ) ? $mock_options[ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Mock update_option function.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @param bool   $autoload Whether to autoload.
	 * @return bool
	 */
	function update_option( $option, $value, $autoload = null ) {
		global $mock_options;
		$mock_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Mock add_option function.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @param string $deprecated Deprecated.
	 * @param bool   $autoload Whether to autoload.
	 * @return bool
	 */
	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
		global $mock_options;
		if ( isset( $mock_options[ $option ] ) ) {
			return false;
		}
		$mock_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Mock delete_option function.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( $option ) {
		global $mock_options;
		if ( isset( $mock_options[ $option ] ) ) {
			unset( $mock_options[ $option ] );
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Mock wp_json_encode function.
	 *
	 * @param mixed $data Data to encode.
	 * @param int   $options JSON options.
	 * @param int   $depth Max depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Mock sanitize_text_field function.
	 *
	 * @param string $str String to sanitize.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $transient, $value, $expiration = 0 ) {
        global $mock_transients;
        $mock_transients[ $transient ] = array(
            'value'      => $value,
            'expiration' => time() + $expiration,
        );
        return true;
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $transient ) {
        global $mock_transients;
        if ( isset( $mock_transients[ $transient ] ) ) {
            $data = $mock_transients[ $transient ];
            if ( $data['expiration'] > time() ) {
                return $data['value'];
            }
        }
        return false;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $transient ) {
        global $mock_transients;
        unset( $mock_transients[ $transient ] );
        return true;
    }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $show = '', $filter = 'raw' ) {
        $info = array(
            'version' => '6.9',
            'name'    => 'Test Blog',
            'url'     => 'https://example.com',
        );
        return isset( $info[ $show ] ) ? $info[ $show ] : '';
    }
}

if ( ! function_exists( 'has_action' ) ) {
    function has_action( $hook_name, $callback = false ) {
        return false;
    }
}

if ( ! function_exists( 'has_filter' ) ) {
    function has_filter( $hook_name, $callback = false ) {
        return false;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability ) {
        return true; // Mock as admin for tests
    }
}

if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user_id, $capability ) {
        return true; // Mock as admin for tests
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return 1; // Mock admin user
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action = -1 ) {
        return true; // Mock nonce verification
    }
}

if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( $email ) {
        return filter_var( $email, FILTER_SANITIZE_EMAIL );
    }
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Mock wp_die function.
	 *
	 * @param string $message Error message.
	 * @param string $title   Error title.
	 * @param array  $args    Additional arguments.
	 */
	function wp_die( $message = '', $title = '', $args = array() ) {
		if ( is_wp_error( $message ) ) {
			$message = $message->get_error_message();
		}
		throw new \Exception( $message );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Check if variable is a WordPress Error.
	 *
	 * @param mixed $thing Variable to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return ( $thing instanceof \WP_Error );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Mock esc_html function.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Mock esc_attr function.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Mock esc_url function.
	 *
	 * @param string $url URL to escape.
	 * @return string
	 */
	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Mock __ function (translation).
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_e' ) ) {
	/**
	 * Mock _e function (translation echo).
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 */
	function _e( $text, $domain = 'default' ) {
		echo $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Mock esc_html__ function (translation with escaping).
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Mock esc_html_e function (translation with escaping, echo).
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 */
	function esc_html_e( $text, $domain = 'default' ) {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Mock esc_attr__ function.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_attr__( $text, $domain = 'default' ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Mock sanitize_title function.
	 *
	 * @param string $title The title to sanitize.
	 * @return string
	 */
	function sanitize_title( $title ) {
		$title = strtolower( $title );
		$title = preg_replace( '/[^a-z0-9\-]/', '-', $title );
		$title = preg_replace( '/-+/', '-', $title );
		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Mock sanitize_key function.
	 *
	 * @param string $key Key to sanitize.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$key = strtolower( $key );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', $key );
		return $key;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Mock absint function (absolute integer).
	 *
	 * @param mixed $maybeint Value to convert.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Mock apply_filters function.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Additional arguments.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) {
		// In tests, just return the value unfiltered
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Mock do_action function.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Additional arguments.
	 */
	function do_action( $hook_name, ...$args ) {
		// In tests, do nothing
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Mock wp_next_scheduled function.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Hook arguments.
	 * @return false|int
	 */
	function wp_next_scheduled( $hook, $args = array() ) {
		// In tests, return false (no scheduled events)
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	/**
	 * Mock wp_schedule_event function.
	 *
	 * @param int    $timestamp Timestamp.
	 * @param string $recurrence Recurrence.
	 * @param string $hook Hook name.
	 * @param array  $args Hook arguments.
	 * @return bool
	 */
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		// In tests, return true (success)
		return true;
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	/**
	 * Mock wp_roles function.
	 *
	 * @return WP_Roles
	 */
	function wp_roles() {
		static $wp_roles = null;
		if ( null === $wp_roles ) {
			$wp_roles = new WP_Roles();
		}
		return $wp_roles;
	}
}

if ( ! function_exists( 'get_role' ) ) {
	/**
	 * Mock get_role function.
	 *
	 * @param string $role Role name.
	 * @return WP_Role|null
	 */
	function get_role( $role ) {
		$wp_roles = wp_roles();
		if ( isset( $wp_roles->role_objects[ $role ] ) ) {
			return $wp_roles->role_objects[ $role ];
		}
		return null;
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	/**
	 * Mock flush_rewrite_rules function.
	 *
	 * @param bool $hard Whether to flush hard.
	 */
	function flush_rewrite_rules( $hard = true ) {
		// In tests, do nothing
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Mock dbDelta function.
	 *
	 * @param string|array $queries SQL queries.
	 * @return array
	 */
	function dbDelta( $queries ) {
		// In tests, return empty array
		return array();
	}
}

// Mock global $wpdb
global $wpdb;
if ( ! isset( $wpdb ) ) {
    $wpdb = new class {
        public $prefix = 'wp_';
        public $options = 'wp_options';
        public $insert_id = 0;

        public function query( $query ) {
            return true;
        }

        public function get_results( $query, $output = OBJECT ) {
            return array();
        }

        public function get_var( $query, $x = 0, $y = 0 ) {
            return null;
        }

        public function prepare( $query, ...$args ) {
            return $query;
        }

        public function get_row( $query, $output = OBJECT, $y = 0 ) {
            return null;
        }

        public function insert( $table, $data, $format = null ) {
            return true;
        }

        public function update( $table, $data, $where, $format = null, $where_format = null ) {
            return true;
        }

        public function delete( $table, $where, $where_format = null ) {
            return true;
        }

        public function esc_like( $text ) {
            return addcslashes( $text, '_%\\' );
        }

        public function get_charset_collate() {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
    };
}

// Initialize mock transients array
$mock_transients = array();

// Mock WordPress object cache functions
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		global $mock_cache;
		$cache_key = $group . ':' . $key;
		if ( isset( $mock_cache[ $cache_key ] ) ) {
			$found = true;
			return $mock_cache[ $cache_key ];
		}
		$found = false;
		return false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		global $mock_cache;
		$mock_cache[ $group . ':' . $key ] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		global $mock_cache;
		unset( $mock_cache[ $group . ':' . $key ] );
		return true;
	}
}

$mock_cache = array();

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Mock admin_url function.
	 *
	 * @param string $path Path relative to admin URL.
	 * @param string $scheme URL scheme.
	 * @return string
	 */
	function admin_url( $path = '', $scheme = 'admin' ) {
		$url = 'http://localhost/wp-admin/';
		if ( ! empty( $path ) ) {
			$url .= ltrim( $path, '/' );
		}
		return $url;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Mock wp_parse_url function.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component Component to retrieve.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Mock current_time function.
	 *
	 * @param string $type    Type of time to retrieve.
	 * @param bool   $gmt     Whether to use GMT.
	 * @return string|int
	 */
	function current_time( $type, $gmt = false ) {
		if ( 'timestamp' === $type || 'U' === $type ) {
			return time();
		}
		return gmdate( $type );
	}
}

if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $data ) {
		if ( is_array( $data ) ) {
			return array_map( 'esc_sql', $data );
		}
		return addslashes( $data );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		// In tests, track registered hooks but don't execute them.
		global $mock_actions;
		if ( ! isset( $mock_actions ) ) {
			$mock_actions = array();
		}
		$mock_actions[] = array(
			'hook'     => $hook_name,
			'callback' => $callback,
			'priority' => $priority,
		);
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		global $mock_filters;
		if ( ! isset( $mock_filters ) ) {
			$mock_filters = array();
		}
		$mock_filters[] = array(
			'hook'     => $hook_name,
			'callback' => $callback,
			'priority' => $priority,
		);
		return true;
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
		return $menu_slug;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
		return $menu_slug;
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {
		// In tests, do nothing.
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {
		// In tests, do nothing.
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return true;
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $plugin ) {
		return true;
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num_words = 55, $more = '&hellip;' ) {
		$words = explode( ' ', $text );
		if ( count( $words ) > $num_words ) {
			$words = array_slice( $words, 0, $num_words );
			$text  = implode( ' ', $words ) . $more;
		}
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return ( 1 === (int) $number ) ? $single : $plural;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'mock_nonce_' . $action;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return 'http://localhost/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return random_int( $min, $max );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		return new \WP_Error( 'http_request_not_executed', 'Mock: HTTP requests not available in tests' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return 200;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return '';
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status_code = null ) {
		// In tests, do nothing (would normally exit).
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status_code = null ) {
		// In tests, do nothing (would normally exit).
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
		// In tests, do nothing.
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		// In tests, do nothing.
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $args = array() ) {
		// In tests, do nothing.
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src, $deps = array(), $ver = false, $media = 'all' ) {
		// In tests, do nothing.
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		return true;
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return trailingslashit( dirname( $file ) );
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://localhost/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		$dir = basename( dirname( $file ) );
		return $dir . '/' . basename( $file );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Mock wp_register_ability function.
	 *
	 * Tracks registered abilities via the Abilities_Registrar for introspection.
	 *
	 * @param string $name   Ability name.
	 * @param array  $config Ability configuration.
	 * @return bool
	 */
	function wp_register_ability( $name, $config = array() ) {
		\Specflux_Marketing_Analytics\Abilities\Abilities_Registrar::track_ability( $name, $config );
		return true;
	}
}

if ( ! function_exists( 'wp_register_ability_category' ) ) {
	/**
	 * Mock wp_register_ability_category function.
	 *
	 * @param string $name   Category name.
	 * @param array  $config Category configuration.
	 * @return bool
	 */
	function wp_register_ability_category( $name, $config = array() ) {
		return true;
	}
}

if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
	/**
	 * Mock wp_add_dashboard_widget function.
	 *
	 * @param string   $widget_id Widget ID.
	 * @param string   $widget_name Widget name.
	 * @param callable $callback Render callback.
	 * @param callable $control_callback Optional control callback.
	 * @param array    $callback_args Optional callback args.
	 * @param string   $context Dashboard context.
	 * @param string   $priority Widget priority.
	 * @return void
	 */
	function wp_add_dashboard_widget( $widget_id, $widget_name, $callback, $control_callback = null, $callback_args = null, $context = 'normal', $priority = 'core' ) {
		// In tests, do nothing
	}
}

// Define the plugin namespace functions that live in the main plugin file.
// We can't require the main file because it re-defines constants without
// if-defined guards. Instead, we define the namespaced functions here.
require_once __DIR__ . '/bootstrap-functions.php';