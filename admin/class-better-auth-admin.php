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
		$this->maybe_handle_api_key_actions();

		register_setting(
			'general',
			'better_auth_delete_users_on_uninstall',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			'general',
			'better_auth_api_keys',
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_api_keys_option' ),
			)
		);

		add_settings_section(
			'better_auth_settings',
			__( 'Better Auth', 'better-auth' ),
			array( $this, 'render_settings_section' ),
			'general'
		);

		add_settings_field(
			'better_auth_api_keys',
			__( 'API Credentials', 'better-auth' ),
			array( $this, 'render_api_keys_field' ),
			'general',
			'better_auth_settings'
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
	 * Sanitize keyring option value.
	 *
	 * @since 1.0.1
	 * @param mixed $value Incoming option value.
	 * @return array
	 */
	public function sanitize_api_keys_option( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$key_id = isset( $entry['key_id'] ) ? sanitize_text_field( $entry['key_id'] ) : '';
			$secret = isset( $entry['secret'] ) ? sanitize_text_field( $entry['secret'] ) : '';
			$status = isset( $entry['status'] ) ? sanitize_text_field( $entry['status'] ) : 'active';

			if ( empty( $key_id ) || empty( $secret ) ) {
				continue;
			}

			$sanitized[] = array(
				'key_id'       => $key_id,
				'secret'       => $secret,
				'status'       => in_array( $status, array( 'active', 'revoked' ), true ) ? $status : 'active',
				'created_at'   => isset( $entry['created_at'] ) ? (int) $entry['created_at'] : time(),
				'revoked_at'   => isset( $entry['revoked_at'] ) ? (int) $entry['revoked_at'] : 0,
				'last_used_at' => isset( $entry['last_used_at'] ) ? (int) $entry['last_used_at'] : 0,
			);
		}

		return $sanitized;
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
	 * Render the "API Secret Key" text field.
	 *
	 * @since 1.0.0
	 */
	public function render_api_keys_field() {
		$keys      = get_option( 'better_auth_api_keys', array() );
		$generated = get_transient( 'better_auth_api_last_generated' );

		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		?>
		<div>
			<p class="description">
				<?php esc_html_e( 'Use these credentials for signing Better Auth sync requests. Keep secrets private and rotate if compromised.', 'better-auth' ); ?>
			</p>

			<?php if ( ! empty( $generated ) && is_array( $generated ) ) : ?>
				<div class="notice notice-warning" style="padding:10px; margin:10px 0;">
					<p><strong><?php esc_html_e( 'New credentials generated. Copy now; the secret is shown only once.', 'better-auth' ); ?></strong></p>
					<p><?php esc_html_e( 'Key ID:', 'better-auth' ); ?> <code><?php echo esc_html( $generated['key_id'] ); ?></code></p>
					<p><?php esc_html_e( 'Client Secret:', 'better-auth' ); ?> <code><?php echo esc_html( $generated['secret'] ); ?></code></p>
				</div>
				<?php delete_transient( 'better_auth_api_last_generated' ); ?>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'better_auth_api_keys_action', 'better_auth_api_keys_nonce' ); ?>
				<input type="hidden" name="better_auth_api_key_action" value="generate" />
				<?php submit_button( __( 'Generate New Credentials', 'better-auth' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" style="margin-top:8px;">
				<?php wp_nonce_field( 'better_auth_api_keys_action', 'better_auth_api_keys_nonce' ); ?>
				<input type="hidden" name="better_auth_api_key_action" value="rotate" />
				<?php submit_button( __( 'Rotate Active Credentials', 'better-auth' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( ! empty( $keys ) ) : ?>
				<table class="widefat striped" style="margin-top:10px; max-width:900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Key ID', 'better-auth' ); ?></th>
							<th><?php esc_html_e( 'Status', 'better-auth' ); ?></th>
							<th><?php esc_html_e( 'Created', 'better-auth' ); ?></th>
							<th><?php esc_html_e( 'Last Used', 'better-auth' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'better-auth' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $keys as $entry ) : ?>
							<?php if ( ! is_array( $entry ) || empty( $entry['key_id'] ) ) { continue; } ?>
							<tr>
								<td><code><?php echo esc_html( $entry['key_id'] ); ?></code></td>
								<td><?php echo esc_html( isset( $entry['status'] ) ? $entry['status'] : 'active' ); ?></td>
								<td><?php echo esc_html( ! empty( $entry['created_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry['created_at'] ) . ' UTC' : '-' ); ?></td>
								<td><?php echo esc_html( ! empty( $entry['last_used_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry['last_used_at'] ) . ' UTC' : '-' ); ?></td>
								<td>
									<?php if ( ! empty( $entry['status'] ) && 'active' === $entry['status'] ) : ?>
										<form method="post" style="display:inline;">
											<?php wp_nonce_field( 'better_auth_api_keys_action', 'better_auth_api_keys_nonce' ); ?>
											<input type="hidden" name="better_auth_api_key_action" value="revoke" />
											<input type="hidden" name="key_id" value="<?php echo esc_attr( $entry['key_id'] ); ?>" />
											<?php submit_button( __( 'Revoke', 'better-auth' ), 'delete', 'submit', false ); ?>
										</form>
									<?php else : ?>
										<?php esc_html_e( 'Revoked', 'better-auth' ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle API key management actions from the settings page.
	 *
	 * @since 1.0.1
	 */
	private function maybe_handle_api_key_actions() {
		if ( ! isset( $_POST['better_auth_api_key_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'better_auth_api_keys_action', 'better_auth_api_keys_nonce' );

		$action = sanitize_text_field( wp_unslash( $_POST['better_auth_api_key_action'] ) );

		if ( 'generate' === $action ) {
			$this->generate_api_key_credentials();
		} elseif ( 'rotate' === $action ) {
			$this->rotate_api_key_credentials();
		} elseif ( 'revoke' === $action ) {
			$key_id = isset( $_POST['key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['key_id'] ) ) : '';
			if ( ! empty( $key_id ) ) {
				$this->revoke_api_key_credentials( $key_id );
			}
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'options-general.php' ) );
		exit;
	}

	/**
	 * Generate a new keyring entry and expose it once via transient.
	 *
	 * @since 1.0.1
	 */
	private function generate_api_key_credentials() {
		$keys = get_option( 'better_auth_api_keys', array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		$key_id = 'ba_' . bin2hex( random_bytes( 8 ) );
		$secret = bin2hex( random_bytes( 32 ) );

		$keys[] = array(
			'key_id'       => $key_id,
			'secret'       => $secret,
			'status'       => 'active',
			'created_at'   => time(),
			'revoked_at'   => 0,
			'last_used_at' => 0,
		);

		update_option( 'better_auth_api_keys', $keys, false );

		set_transient(
			'better_auth_api_last_generated',
			array(
				'key_id' => $key_id,
				'secret' => $secret,
			),
			300
		);
	}

	/**
	 * Revoke an active key in the keyring.
	 *
	 * @since 1.0.1
	 * @param string $key_id Key id to revoke.
	 */
	private function revoke_api_key_credentials( $key_id ) {
		$keys = get_option( 'better_auth_api_keys', array() );
		if ( ! is_array( $keys ) || empty( $keys ) ) {
			return;
		}

		$updated = false;
		foreach ( $keys as $index => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['key_id'] ) ) {
				continue;
			}

			if ( hash_equals( (string) $entry['key_id'], (string) $key_id ) ) {
				$keys[ $index ]['status']     = 'revoked';
				$keys[ $index ]['revoked_at'] = time();
				$updated = true;
				break;
			}
		}

		if ( $updated ) {
			update_option( 'better_auth_api_keys', $keys, false );
		}
	}

	/**
	 * Rotate credentials by revoking active keys and generating a new pair.
	 *
	 * @since 1.0.1
	 */
	private function rotate_api_key_credentials() {
		$keys = get_option( 'better_auth_api_keys', array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		$changed = false;
		foreach ( $keys as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$status = isset( $entry['status'] ) ? $entry['status'] : 'active';
			if ( 'active' === $status ) {
				$keys[ $index ]['status']     = 'revoked';
				$keys[ $index ]['revoked_at'] = time();
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'better_auth_api_keys', $keys, false );
		}

		$this->generate_api_key_credentials();
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
