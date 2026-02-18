<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://scneptune.com
 * @since      1.0.0
 *
 * @package    Better_Auth
 * @subpackage Better_Auth/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Better_Auth
 * @subpackage Better_Auth/admin
 * @author     Stephen Neptune <steven.neptune@gmail.com>
 */
class Better_Auth_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register the plugin settings with the WordPress Settings API.
	 *
	 * Adds a "Better Auth" section to the General Settings page with a
	 * checkbox that controls whether Better Auth-created WordPress users
	 * are deleted when the plugin is uninstalled.
	 *
	 * @since 1.0.0
	 */
	public function register_settings() {
		register_setting(
			'general',
			'better_auth_delete_users_on_uninstall',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		add_settings_section(
			'better_auth_settings',
			__( 'Better Auth', 'better-auth' ),
			array( $this, 'render_settings_section' ),
			'general'
		);

		add_settings_field(
			'better_auth_delete_users_on_uninstall',
			__( 'Delete synced users on uninstall', 'better-auth' ),
			array( $this, 'render_delete_users_field' ),
			'general',
			'better_auth_settings'
		);
	}

	/**
	 * Render the Better Auth settings section description.
	 *
	 * @since 1.0.0
	 */
	public function render_settings_section() {
		echo '<p>' . esc_html__( 'Settings for the Better Auth plugin.', 'better-auth' ) . '</p>';
	}

	/**
	 * Render the "Delete synced users on uninstall" checkbox.
	 *
	 * @since 1.0.0
	 */
	public function render_delete_users_field() {
		$value = get_option( 'better_auth_delete_users_on_uninstall', false );
		?>
		<label>
			<input
				type="checkbox"
				name="better_auth_delete_users_on_uninstall"
				value="1"
				<?php checked( $value ); ?>
			/>
			<?php esc_html_e(
				'When the plugin is deleted, also delete WordPress users that were created by Better Auth sync. Each user will receive a password-reset email before their Better Auth data is removed.',
				'better-auth'
			); ?>
		</label>
		<?php
	}

	/**
	 * Display any admin notices set by the plugin (e.g. deactivation warning).
	 *
	 * @since 1.0.0
	 */
	public function show_admin_notices() {
		$notice = get_transient( 'better_auth_deactivation_notice' );
		if ( $notice ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html( $notice )
			);
			delete_transient( 'better_auth_deactivation_notice' );
		}
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Better_Auth_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Better_Auth_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/better-auth-admin.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Better_Auth_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Better_Auth_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/better-auth-admin.js', array( 'jquery' ), $this->version, false );

	}

}
