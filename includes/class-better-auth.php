<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://scneptune.com
 * @since      1.0.0
 *
 * @package    Better_Auth
 * @subpackage Better_Auth/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Better_Auth
 * @subpackage Better_Auth/includes
 * @author     Stephen Neptune <steven.neptune@gmail.com>
 */
class Better_Auth {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Better_Auth_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'BETTER_AUTH_VERSION' ) ) {
			$this->version = BETTER_AUTH_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'better-auth';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Better_Auth_Loader. Orchestrates the hooks of the plugin.
	 * - Better_Auth_i18n. Defines internationalization functionality.
	 * - Better_Auth_Admin. Defines all hooks for the admin area.
	 * - Better_Auth_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-better-auth-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-better-auth-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-better-auth-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-better-auth-public.php';

		$this->loader = new Better_Auth_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Better_Auth_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Better_Auth_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Better_Auth_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'register_settings' );
		$this->loader->add_action( 'admin_notices', $plugin_admin, 'show_admin_notices' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Better_Auth_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Better_Auth_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/*
	|--------------------------------------------------------------------------
	| WordPress ↔ Better Auth User Sync
	|--------------------------------------------------------------------------
	|
	| One-way sync: Better Auth → WordPress.
	|
	| Whenever a user is created through the better-auth JS library, a
	| corresponding WordPress user should be created in wp_users so the
	| person can interact with the full WordPress ecosystem (comments,
	| capabilities, etc.) without any changes to the core WP experience.
	|
	| sync_better_auth_users_to_wp() runs once during activation to pick
	| up any Better Auth users created before the plugin was active.
	| For ongoing sync, call maybe_create_wp_user_for_ba_user() from a
	| REST endpoint or webhook.
	|
	*/

	/**
	 * Create a WordPress user for every Better Auth user that does not yet
	 * have a matching WP account (matched by e-mail address).
	 *
	 * How it works:
	 *  1. Query all rows from the Better Auth user table.
	 *  2. For each row, check if a WP user with the same email exists.
	 *  3. If not → create one with wp_insert_user() and a random password
	 *     (the user authenticates via Better Auth, not wp-login.php).
	 *  4. Store the Better Auth user ID as user-meta so the two records
	 *     can be linked for future lookups.
	 *
	 * @since 1.0.0
	 */
	public static function sync_better_auth_users_to_wp() {
		global $wpdb;

		$ba_user_table = $wpdb->prefix . 'user';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ba_users = $wpdb->get_results(
			"SELECT id, name, email FROM `{$ba_user_table}`"
		);

		if ( empty( $ba_users ) ) {
			return;
		}

		foreach ( $ba_users as $ba_user ) {
			self::maybe_create_wp_user_for_ba_user( $ba_user );
		}
	}

	/**
	 * Create (or link) a single WordPress user for a Better Auth user row.
	 *
	 * Kept as its own method so it can be called individually from a REST
	 * endpoint or webhook later for real-time sync.
	 *
	 * @since  1.0.0
	 * @param  object $ba_user Row from the Better Auth user table (id, name, email).
	 * @return int|WP_Error    The WordPress user ID on success, or WP_Error.
	 */
	public static function maybe_create_wp_user_for_ba_user( $ba_user ) {
		$email = sanitize_email( $ba_user->email );

		// If a WP user already has this email, just make sure the link exists.
		$existing_wp_user = get_user_by( 'email', $email );
		if ( $existing_wp_user ) {
			if ( ! get_user_meta( $existing_wp_user->ID, 'better_auth_user_id', true ) ) {
				update_user_meta(
					$existing_wp_user->ID,
					'better_auth_user_id',
					sanitize_text_field( $ba_user->id )
				);
			}
			return $existing_wp_user->ID;
		}

		// Derive a unique user_login from the name, falling back to the
		// email-local part (everything before @).
		$user_login = ! empty( $ba_user->name )
			? sanitize_user( $ba_user->name, true )
			: sanitize_user( strstr( $ba_user->email, '@', true ), true );

		// Append a short random suffix if the login is already taken.
		if ( username_exists( $user_login ) ) {
			$user_login .= '_' . substr( wp_generate_password( 6, false ), 0, 6 );
		}

		// wp_generate_password() creates a long random string.  The user
		// never needs to know it — they authenticate through Better Auth.
		$wp_user_id = wp_insert_user( array(
			'user_login'   => $user_login,
			'user_email'   => $email,
			'display_name' => sanitize_text_field( $ba_user->name ),
			'user_pass'    => wp_generate_password( 24 ),
			'role'         => 'subscriber',
		) );

		if ( is_wp_error( $wp_user_id ) ) {
			return $wp_user_id;
		}

		// Store the Better Auth ↔ WP link as user-meta.
		update_user_meta(
			$wp_user_id,
			'better_auth_user_id',
			sanitize_text_field( $ba_user->id )
		);

		return $wp_user_id;
	}

	/*
	|--------------------------------------------------------------------------
	| OTP (One-Time Password) Support  –  Stub
	|--------------------------------------------------------------------------
	|
	| Users who sign up via OTP never choose a WordPress password.  If they
	| ever need to log in through wp-login.php (e.g. to access /wp-admin/),
	| they'll need a way to set one.  The method below uses WordPress's
	| built-in password-reset flow to send a "set your password" email.
	|
	| TODO: Wire this into a UI element or REST endpoint when OTP login is
	|       implemented in a future iteration.
	|
	*/

	/**
	 * Send a password-setup / reset email for a user who was created via
	 * OTP and therefore has no known WordPress password.
	 *
	 * Internally this calls retrieve_password() which generates a reset
	 * key and emails the user.
	 *
	 * @since  1.0.0
	 * @param  int $wp_user_id WordPress user ID.
	 * @return true|WP_Error   True on success, WP_Error on failure.
	 */
	public static function send_password_setup_for_otp_user( $wp_user_id ) {
		$user = get_userdata( $wp_user_id );
		if ( ! $user ) {
			return new WP_Error(
				'invalid_user',
				__( 'User not found.', 'better-auth' )
			);
		}

		// Only act on users that were created via Better Auth.
		$ba_id = get_user_meta( $wp_user_id, 'better_auth_user_id', true );
		if ( empty( $ba_id ) ) {
			return new WP_Error(
				'not_better_auth_user',
				__( 'This user is not linked to a Better Auth account.', 'better-auth' )
			);
		}

		// retrieve_password() generates a reset key, stores it in the DB,
		// and emails the user a link to wp-login.php?action=rp.
		return retrieve_password( $user->user_login );
	}

}
