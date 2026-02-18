<?php

/**
 * Fired when the plugin is *deleted* (not just deactivated).
 *
 * Responsibilities:
 *  1. Send password-reset emails to every WordPress user that was created
 *     via Better Auth sync so they can set their own password.
 *  2. Optionally delete those WP users entirely (when the admin has
 *     enabled the "better_auth_delete_users_on_uninstall" setting).
 *  3. Drop the four Better Auth schema tables.
 *  4. Clean up all plugin options and usermeta.
 *
 * @link       https://scneptune.com
 * @since      1.0.0
 *
 * @package    Better_Auth
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
|--------------------------------------------------------------------------
| 1. Send password-reset emails to Better Auth-synced WP users
|--------------------------------------------------------------------------
|
| Every WP user that has the `better_auth_user_id` meta was created (or
| linked) by this plugin.  Their WP password was randomly generated, so
| they need a way to set their own before we remove the Better Auth
| tables.  WordPress's built-in retrieve_password() sends a standard
| "reset your password" email.
|
*/

$ba_linked_users = get_users( array(
	'meta_key'   => 'better_auth_user_id',
	'meta_compare' => 'EXISTS',
	'fields'     => 'all',
) );

$force_delete_users = get_option( 'better_auth_delete_users_on_uninstall', false );

if ( ! empty( $ba_linked_users ) ) {
	// Ensure retrieve_password() is available (it lives in wp-login.php
	// helpers which may not be loaded in every context).
	if ( ! function_exists( 'retrieve_password' ) ) {
		require_once ABSPATH . 'wp-login.php';
	}

	foreach ( $ba_linked_users as $user ) {
		// Send the password-reset email so the user can set a real
		// password now that Better Auth authentication is going away.
		retrieve_password( $user->user_login );

		if ( $force_delete_users ) {
			// Reassign any content owned by this user to the site's
			// primary admin (user ID 1) rather than deleting content.
			// This prevents orphaned posts/comments.
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user->ID, 1 );
		} else {
			// Just remove the Better Auth link — the user stays in WP
			// as a normal subscriber.
			delete_user_meta( $user->ID, 'better_auth_user_id' );
		}
	}
}

/*
|--------------------------------------------------------------------------
| 2. Drop the Better Auth schema tables
|--------------------------------------------------------------------------
|
| The four tables we created during activation.  We drop them in an order
| that respects conceptual foreign-key relationships (children first) even
| though MySQL doesn't enforce FK constraints via dbDelta.
|
*/

$tables_to_drop = array(
	$wpdb->prefix . 'ba_verification',
	$wpdb->prefix . 'ba_account',
	$wpdb->prefix . 'ba_session',
	$wpdb->prefix . 'ba_user',
);

foreach ( $tables_to_drop as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

/*
|--------------------------------------------------------------------------
| 3. Clean up plugin options
|--------------------------------------------------------------------------
*/

delete_option( 'better_auth_delete_users_on_uninstall' );
delete_option( 'better_auth_api_secret' );

// Remove any lingering transients.
delete_transient( 'better_auth_deactivation_notice' );
