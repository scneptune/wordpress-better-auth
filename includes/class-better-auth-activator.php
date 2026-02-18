<?php

/**
 * Fired during plugin activation
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
 * Fired during plugin activation.
 *
 * Creates the Better Auth schema tables (user, session, account, verification)
 * and performs a one-time sync of any existing Better Auth users into the
 * WordPress users table.
 *
 * @since      1.0.0
 * @package    Better_Auth
 * @subpackage Better_Auth/includes
 * @author     Stephen Neptune <steven.neptune@gmail.com>
 */
class Better_Auth_Activator {

	/**
	 * Check whether all four Better Auth core tables already exist.
	 *
	 * @since  1.0.0
	 * @return bool True when every table is present, false otherwise.
	 */
	public static function check_for_existing_tables() {
		global $wpdb;

		$core_suffixes = array( 'verification', 'account', 'session', 'user' );

		foreach ( $core_suffixes as $suffix ) {
			$table = $wpdb->prefix . 'ba_' . $suffix;

			// Use $wpdb->prepare() so the table name is safely quoted inside
			// the LIKE pattern instead of being concatenated into raw SQL.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			);

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Create the `{prefix}user` table for Better Auth users.
	 *
	 * @since 1.0.0
	 */
	public static function migrate_user_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'ba_user';
		$charset_collate = $wpdb->get_charset_collate();

		// "boolean" is an alias for tinyint(1) in MySQL. Using tinyint(1)
		// explicitly makes intent clearer and avoids dbDelta edge-cases.
		$sql = "CREATE TABLE {$table_name} (
			id varchar(255) NOT NULL,
			name varchar(255) DEFAULT '',
			email varchar(255) DEFAULT '',
			emailVerified tinyint(1) NOT NULL DEFAULT 0,
			image text,
			createdAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updatedAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY email (email)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the `{prefix}session` table for Better Auth sessions.
	 *
	 * userId references {prefix}user.id conceptually. The relationship is
	 * enforced at the application level because WordPress's dbDelta() does
	 * not support FOREIGN KEY constraints.
	 *
	 * @since 1.0.0
	 */
	public static function migrate_session_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'ba_session';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id varchar(255) NOT NULL,
			userId varchar(255) NOT NULL,
			token text NOT NULL,
			expiresAt datetime NOT NULL,
			ipAddress varchar(255) DEFAULT NULL,
			userAgent text DEFAULT NULL,
			createdAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updatedAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY userId (userId)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the `{prefix}account` table for Better Auth provider accounts.
	 *
	 * @since 1.0.0
	 */
	public static function migrate_account_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'ba_account';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id varchar(255) NOT NULL,
			userId varchar(255) NOT NULL,
			accountId varchar(255) NOT NULL,
			providerId varchar(255) NOT NULL,
			accessToken text,
			refreshToken text,
			accessTokenExpiresAt datetime DEFAULT NULL,
			refreshTokenExpiresAt datetime DEFAULT NULL,
			scope text,
			idToken text,
			password varchar(255) DEFAULT NULL,
			createdAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updatedAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY userId (userId)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Create the `{prefix}verification` table for Better Auth verification tokens.
	 *
	 * @since 1.0.0
	 */
	public static function migrate_verification_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'ba_verification';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id varchar(255) NOT NULL,
			identifier text NOT NULL,
			value text NOT NULL,
			expiresAt datetime DEFAULT NULL,
			createdAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updatedAt datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Run all activation tasks.
	 *
	 * dbDelta() is idempotent — it creates tables that don't exist and
	 * alters existing ones if the schema has changed.  There is no need
	 * to guard with check_for_existing_tables() first.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		// 1. Ensure the Better Auth schema tables exist.
		self::migrate_user_table();
		self::migrate_session_table();
		self::migrate_account_table();
		self::migrate_verification_table();
	}
}
