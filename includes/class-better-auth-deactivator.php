<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://scneptune.com
 * @since      1.0.0
 *
 * @package    Better_Auth
 * @subpackage Better_Auth/includes
 */

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Fired during plugin deactivation.
 *
 * Deactivation is NON-DESTRUCTIVE — tables and user data are preserved so
 * the plugin can be safely re-activated.  Permanent cleanup (table drops,
 * usermeta removal) happens in uninstall.php when the plugin is *deleted*.
 *
 * @since      1.0.0
 * @package    Better_Auth
 * @subpackage Better_Auth/includes
 * @author     Stephen Neptune <steven.neptune@gmail.com>
 */
class Better_Auth_Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * 1. Log a warning if Better Auth user records exist (helps admins
	 *    understand that data is still in the database).
	 * 2. Clean up any scheduled cron events the plugin may have registered.
	 * 3. Flush rewrite rules so any custom endpoints are removed.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		self::warn_if_ba_users_exist();
		self::clear_scheduled_events();

		// Flush rewrite rules so any custom REST routes or permalink
		// structures added by the plugin are removed from the cache.
		flush_rewrite_rules();
	}

	/**
	 * Log a notice if the Better Auth user table contains records.
	 *
	 * This doesn't block deactivation — it simply leaves a breadcrumb
	 * in the error log so the admin is aware the data is still there.
	 * The admin notice is stored as a transient so it can be displayed
	 * on the next page load before the plugin is fully unloaded.
	 *
	 * @since 1.0.0
	 */
	private static function warn_if_ba_users_exist() {
		global $wpdb;

		$table = $wpdb->prefix . 'user';

		// Guard: the table may not exist if activation never completed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( $table_exists !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}`"
		);

		if ( $user_count > 0 ) {
			// Store a transient admin notice that will survive until
			// the next admin page load (or 60 seconds, whichever comes
			// first).  The plugin's admin class can display it, or any
			// admin_notices handler can pick it up.
			set_transient(
				'better_auth_deactivation_notice',
				sprintf(
					/* translators: %d: number of Better Auth user records */
					__( 'Better Auth has been deactivated. %d Better Auth user record(s) remain in the database. To remove them permanently, delete the plugin from the Plugins page.', 'better-auth' ),
					$user_count
				),
				60
			);

			// Also log for visibility in wp-content/debug.log.
			error_log(
				sprintf(
					'[Better Auth] Plugin deactivated with %d user record(s) still in the Better Auth tables. Data is preserved — delete the plugin to remove it.',
					$user_count
				)
			);
		}
	}

	/**
	 * Unschedule any WP-Cron events the plugin may have registered.
	 *
	 * Currently a no-op, but structured so future cron jobs (e.g. a
	 * periodic sync) are automatically cleaned up on deactivation.
	 *
	 * @since 1.0.0
	 */
	private static function clear_scheduled_events() {
		$hooks = array(
			'better_auth_sync_users',
		);

		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}
}
