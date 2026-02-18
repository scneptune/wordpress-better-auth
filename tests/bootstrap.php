<?php
/**
 * PHPUnit bootstrap for Better Auth plugin tests.
 *
 * Provides WordPress constants and minimal stubs so the plugin classes
 * can be loaded without a full WordPress installation.  Brain\Monkey
 * handles mocking of individual WP functions per test.
 *
 * @package Better_Auth\Tests
 */

// Composer autoloader (loads Brain\Monkey, Mockery, etc.).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WordPress constants the plugin files expect.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'BETTER_AUTH_VERSION' ) ) {
	define( 'BETTER_AUTH_VERSION', '1.0.0' );
}

// Create the stub upgrade.php that activator require_once's.
// This provides a no-op dbDelta() so the real file doesn't need to exist.
$upgrade_dir  = ABSPATH . 'wp-admin/includes';
$upgrade_file = $upgrade_dir . '/upgrade.php';
if ( ! is_dir( $upgrade_dir ) ) {
	mkdir( $upgrade_dir, 0755, true );
}
if ( ! file_exists( $upgrade_file ) ) {
	file_put_contents(
		$upgrade_file,
		"<?php\n// Stub for unit tests.\nif ( ! function_exists( 'dbDelta' ) ) {\n\tfunction dbDelta( \$queries = '', \$execute = true ) { return array(); }\n}\n"
	);
}

/**
 * Minimal WP_Error stub used by several plugin methods.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

/**
 * Stub is_wp_error() — Brain\Monkey can override per test if needed.
 */
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

/**
 * Minimal WP_REST_Server stub — only the CREATABLE constant is needed.
 */
if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const CREATABLE = 'POST';
	}
}

/**
 * Minimal WP_REST_Response stub.
 */
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

/**
 * Minimal WP_REST_Request stub.
 */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params  = array();
		private $headers = array();
		private $content_type = array();

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		public function get_param( $key ) {
			return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
		}

		public function set_header( $key, $value ) {
			$this->headers[ strtolower( $key ) ] = $value;
		}

		public function get_header( $key ) {
			$key = strtolower( $key );
			return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
		}

		public function set_content_type( $value ) {
			$this->content_type = $value;
		}

		public function get_content_type() {
			return $this->content_type;
		}
	}
}
