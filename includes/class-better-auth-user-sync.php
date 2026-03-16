<?php

/**
 * Better Auth user sync REST routes.
 *
 * @package Better_Auth
 * @subpackage Better_Auth/includes
 */

// Prevent direct file access.
defined( 'ABSPATH' ) || exit;

/**
 * Handles creating/linking WordPress users for Better Auth users.
 */
class Better_Auth_User_Sync {

    /**
     * HMAC timestamp drift tolerance in seconds.
     *
     * @since 1.0.1
     * @var int
     */
    const SIGNATURE_TIMESTAMP_TTL = 300;

    /**
     * Nonce replay cache TTL in seconds.
     *
     * @since 1.0.1
     * @var int
     */
    const NONCE_CACHE_TTL = 600;

    /**
     * Register Better Auth user sync routes.
     *
     * @since 1.0.0
     */
    public function register_routes() {
        register_rest_route(
            'better-auth/v1',
            '/create-user',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'create_wp_user_from_better_auth_user' ),
                'permission_callback' => array( $this, 'verify_sync_secret' ),
                'args'                => array(
                    'email' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'format'            => 'email',
                        'sanitize_callback' => 'sanitize_email',
                    ),
                    'ba_user_id' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'name' => array(
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'phone' => array(
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'otp_method' => array(
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );

        if ( $this->is_woocommerce_available() ) {
            register_rest_route(
                'better-auth/v1',
                '/sync/billing',
                array(
                    'methods'             => 'PATCH',
                    'callback'            => array( $this, 'sync_billing_details' ),
                    'permission_callback' => array( $this, 'verify_hmac_sync_signature' ),
                    'args'                => array(
                        'ba_user_id' => array(
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                        'billing_address' => array(
                            'required' => true,
                            'type'     => 'object',
                        ),
                    ),
                )
            );
        }
    }

    /**
     * Verify HMAC signature and replay protection headers.
     *
     * Required headers:
     * - X-BA-Key-Id
     * - X-BA-Timestamp
     * - X-BA-Nonce
     * - X-BA-Signature
     *
     * Signature payload:
     * METHOD + "\n" + ROUTE + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + SHA256(BODY)
     *
     * @since 1.0.1
     * @param WP_REST_Request $request Incoming request.
     * @return true|WP_Error
     */
    public function verify_hmac_sync_signature( $request ) {
        $stored_secret = get_option( 'better_auth_api_secret', '' );
        if ( empty( $stored_secret ) ) {
            return new WP_Error(
                'rest_forbidden_no_secret',
                __( 'The Better Auth API secret has not been configured.', 'better-auth' ),
                array( 'status' => 403 )
            );
        }

        $key_id         = (string) $request->get_header( 'X-BA-Key-Id' );
        $timestamp      = (string) $request->get_header( 'X-BA-Timestamp' );
        $nonce          = (string) $request->get_header( 'X-BA-Nonce' );
        $sent_signature = (string) $request->get_header( 'X-BA-Signature' );

        if ( empty( $timestamp ) || empty( $nonce ) || empty( $sent_signature ) ) {
            return new WP_Error(
                'rest_bad_signature_headers',
                __( 'Missing required signature headers.', 'better-auth' ),
                array( 'status' => 400 )
            );
        }

        if ( ! ctype_digit( $timestamp ) ) {
            return new WP_Error(
                'rest_bad_signature_timestamp',
                __( 'Invalid signature timestamp.', 'better-auth' ),
                array( 'status' => 400 )
            );
        }

        $expected_key_id = get_option( 'better_auth_api_key_id', '' );
        if ( ! empty( $expected_key_id ) && ( empty( $key_id ) || ! hash_equals( $expected_key_id, $key_id ) ) ) {
            return new WP_Error(
                'rest_forbidden_invalid_key_id',
                __( 'Invalid key id.', 'better-auth' ),
                array( 'status' => 403 )
            );
        }

        $timestamp_int = (int) $timestamp;
        if ( abs( time() - $timestamp_int ) > self::SIGNATURE_TIMESTAMP_TTL ) {
            return new WP_Error(
                'rest_forbidden_stale_request',
                __( 'Stale request timestamp.', 'better-auth' ),
                array( 'status' => 403 )
            );
        }

        if ( $this->is_nonce_replayed( $nonce ) ) {
            return new WP_Error(
                'rest_forbidden_replay',
                __( 'Replay detected for nonce.', 'better-auth' ),
                array( 'status' => 409 )
            );
        }

        $method    = method_exists( $request, 'get_method' ) ? strtoupper( $request->get_method() ) : 'PATCH';
        $route     = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '/better-auth/v1/sync/billing';
        $raw_body  = $this->get_request_body_for_signature( $request );
        $body_hash = hash( 'sha256', $raw_body );

        $payload = implode(
            "\n",
            array(
                $method,
                $route,
                $timestamp,
                $nonce,
                $body_hash,
            )
        );

        $expected_signature = hash_hmac( 'sha256', $payload, $stored_secret );
        if ( ! hash_equals( $expected_signature, $sent_signature ) ) {
            return new WP_Error(
                'rest_forbidden_invalid_signature',
                __( 'Invalid request signature.', 'better-auth' ),
                array( 'status' => 403 )
            );
        }

        $this->mark_nonce_as_used( $nonce );

        return true;
    }

    /**
     * Verify the shared secret used by the sync route.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request The incoming request.
     * @return true|WP_Error
     */
    public function verify_sync_secret( $request ) {
        $stored_secret = get_option( 'better_auth_api_secret', '' );
        $request_secret = (string) $request->get_header( 'X-Better-Auth-Secret' );

        if ( empty( $stored_secret ) ) {
            return new WP_Error(
                'rest_forbidden_no_secret',
                __( 'The Better Auth API secret has not been configured.', 'better-auth' ),
                array( 'status' => 403 )
            );
        }

        if ( empty( $request_secret ) || ! hash_equals( $stored_secret, $request_secret ) ) {
            return new WP_Error(
                'rest_forbidden_invalid_secret',
                __( 'Invalid or missing sync secret.', 'better-auth' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Create or link a WordPress user for a Better Auth user.
     *
     * @since 1.0.0
     * @param WP_REST_Request $request The incoming request.
     * @return WP_REST_Response|WP_Error
     */
    public function create_wp_user_from_better_auth_user( $request ) {
        $email      = sanitize_email( $request->get_param( 'email' ) );
        $name       = sanitize_text_field( $request->get_param( 'name' ) );
        $ba_id      = sanitize_text_field( $request->get_param( 'ba_user_id' ) );
        $phone      = sanitize_text_field( $request->get_param( 'phone' ) );
        $otp_method = sanitize_text_field( $request->get_param( 'otp_method' ) );

        if ( empty( $email ) || empty( $ba_id ) ) {
            return new WP_Error(
                'rest_missing_params',
                __( 'Both "email" and "ba_user_id" are required.', 'better-auth' ),
                array( 'status' => 400 )
            );
        }

        // This sync flow is managed by Better Auth, so suppress native
        // "new account" emails from WP/WooCommerce for this request.
        $this->add_account_email_suppression_filters();

        try {
            $existing_wp_user = get_user_by( 'email', $email );

            if ( $existing_wp_user ) {
                $wp_user_id = (int) $existing_wp_user->ID;
            } else {
                $local_part = strstr( $email, '@', true );
                $user_login = ! empty( $local_part ) ? sanitize_user( $local_part, true ) : sanitize_user( $email, true );

                if ( empty( $user_login ) ) {
                    $user_login = 'better_auth_user';
                }

                if ( username_exists( $user_login ) ) {
                    $user_login .= '_' . strtolower( wp_generate_password( 6, false ) );
                }
                // Use 'customer' role if WooCommerce is active, otherwise fall back to 'subscriber'.
                $default_role = $this->is_woocommerce_available() ? 'customer' : 'subscriber';

                $wp_user_id = wp_insert_user(
                    array(
                        'user_login'   => $user_login,
                        'user_email'   => $email,
                        // Random password since auth is via Better Auth and we never login directly with WP credentials.
                        'user_pass'    => wp_generate_password( 32, true, true ),
                        'display_name' => $name,
                        'role'         => $default_role,
                    )
                );

                if ( is_wp_error( $wp_user_id ) ) {
                    return new WP_REST_Response(
                        array( 'error' => $wp_user_id->get_error_message() ),
                        400
                    );
                }
            }

            if ( ! empty( $phone ) ) {
                update_user_meta( $wp_user_id, 'phone_number', $phone );
            }

            if ( ! empty( $otp_method ) ) {
                update_user_meta( $wp_user_id, 'better_auth_otp_method', $otp_method );
            }

            $woocommerce_customer_created = $this->maybe_create_woocommerce_customer( $wp_user_id, $email, $name, $phone );

            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'ba_user',
                array( 'wpUserId' => $wp_user_id ),
                array( 'id' => $ba_id ),
                array( '%d' ),
                array( '%s' )
            );

            return new WP_REST_Response(
                array(
                    'wp_user_id'                   => $wp_user_id,
                    'woocommerce_customer_created' => $woocommerce_customer_created,
                ),
                201
            );
        } finally {
            $this->remove_account_email_suppression_filters();
        }
    }

    /**
     * Create or update WooCommerce/WordPress billing details for a Better Auth user.
     *
     * @since 1.0.1
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response|WP_Error
     */
    public function sync_billing_details( $request ) {
        if ( ! $this->is_woocommerce_available() ) {
            return new WP_Error(
                'rest_woocommerce_unavailable',
                __( 'WooCommerce is not active. Billing sync endpoint is unavailable.', 'better-auth' ),
                array( 'status' => 503 )
            );
        }

        $ba_user_id       = sanitize_text_field( $request->get_param( 'ba_user_id' ) );
        $billing_address  = $request->get_param( 'billing_address' );

        if ( empty( $ba_user_id ) ) {
            return new WP_Error(
                'rest_missing_ba_user_id',
                __( 'The "ba_user_id" field is required.', 'better-auth' ),
                array( 'status' => 400 )
            );
        }

        if ( ! is_array( $billing_address ) ) {
            return new WP_Error(
                'rest_invalid_billing_payload',
                __( 'The "billing_address" field must be an object.', 'better-auth' ),
                array( 'status' => 400 )
            );
        }

        $wp_user_id = $this->resolve_wp_user_id_from_ba_user_id( $ba_user_id );
        if ( $wp_user_id < 1 ) {
            return new WP_Error(
                'rest_user_not_found',
                __( 'No WordPress user linked to this Better Auth user ID.', 'better-auth' ),
                array( 'status' => 404 )
            );
        }

        $billing = array(
            'first_name' => sanitize_text_field( isset( $billing_address['first_name'] ) ? $billing_address['first_name'] : '' ),
            'last_name'  => sanitize_text_field( isset( $billing_address['last_name'] ) ? $billing_address['last_name'] : '' ),
            'address_1'  => sanitize_text_field( isset( $billing_address['address_1'] ) ? $billing_address['address_1'] : '' ),
            'address_2'  => sanitize_text_field( isset( $billing_address['address_2'] ) ? $billing_address['address_2'] : '' ),
            'city'       => sanitize_text_field( isset( $billing_address['city'] ) ? $billing_address['city'] : '' ),
            'state'      => sanitize_text_field( isset( $billing_address['state'] ) ? $billing_address['state'] : '' ),
            'postcode'   => sanitize_text_field( isset( $billing_address['postcode'] ) ? $billing_address['postcode'] : '' ),
            'country'    => sanitize_text_field( isset( $billing_address['country'] ) ? $billing_address['country'] : '' ),
            'email'      => sanitize_email( isset( $billing_address['email'] ) ? $billing_address['email'] : '' ),
            'phone'      => sanitize_text_field( isset( $billing_address['phone'] ) ? $billing_address['phone'] : '' ),
        );

        if ( ! empty( $billing_address['email'] ) && empty( $billing['email'] ) ) {
            return new WP_Error(
                'rest_invalid_email',
                __( 'Invalid billing email address.', 'better-auth' ),
                array( 'status' => 400 )
            );
        }

        $meta_map = array(
            'first_name' => 'billing_first_name',
            'last_name'  => 'billing_last_name',
            'address_1'  => 'billing_address_1',
            'address_2'  => 'billing_address_2',
            'city'       => 'billing_city',
            'state'      => 'billing_state',
            'postcode'   => 'billing_postcode',
            'country'    => 'billing_country',
            'email'      => 'billing_email',
            'phone'      => 'billing_phone',
        );

        foreach ( $meta_map as $field_key => $meta_key ) {
            update_user_meta( $wp_user_id, $meta_key, $billing[ $field_key ] );
        }

        // Keep the OTP contact value synchronized with billing phone.
        update_user_meta( $wp_user_id, 'phone_number', $billing['phone'] );

        if ( ! empty( $billing['email'] ) ) {
            $user_update_result = wp_update_user(
                array(
                    'ID'         => $wp_user_id,
                    'user_email' => $billing['email'],
                )
            );

            if ( is_wp_error( $user_update_result ) ) {
                return $user_update_result;
            }
        }

        $woocommerce_customer_updated = false;

        if ( $this->is_woocommerce_available() ) {
            $customer_class = 'WC_Customer';
            $customer       = new $customer_class( $wp_user_id );

            $customer->set_billing_first_name( $billing['first_name'] );
            $customer->set_billing_last_name( $billing['last_name'] );
            $customer->set_billing_address_1( $billing['address_1'] );
            $customer->set_billing_address_2( $billing['address_2'] );
            $customer->set_billing_city( $billing['city'] );
            $customer->set_billing_state( $billing['state'] );
            $customer->set_billing_postcode( $billing['postcode'] );
            $customer->set_billing_country( $billing['country'] );
            $customer->set_billing_phone( $billing['phone'] );
            $customer->set_billing_email( $billing['email'] );

            if ( ! empty( $billing['email'] ) && method_exists( $customer, 'set_email' ) ) {
                $customer->set_email( $billing['email'] );
            }

            $customer->save();
            $woocommerce_customer_updated = true;
        }

        return new WP_REST_Response(
            array(
                'wp_user_id'                  => $wp_user_id,
                'better_auth_user_id'         => $ba_user_id,
                'woocommerce_customer_updated' => $woocommerce_customer_updated,
                'billing_address'             => $billing,
            ),
            200
        );
    }

    /**
     * Temporarily disable new-account emails in WordPress and WooCommerce.
     *
     * @since 1.0.1
     */
    private function add_account_email_suppression_filters() {
        add_filter( 'wp_send_new_user_notification_to_admin', array( $this, 'suppress_wp_user_notification' ), 10, 1 );
        add_filter( 'wp_send_new_user_notification_to_user', array( $this, 'suppress_wp_user_notification' ), 10, 1 );
        add_filter( 'woocommerce_email_enabled_customer_new_account', array( $this, 'suppress_wc_customer_new_account_email' ), 10, 2 );
    }

    /**
     * Remove temporary new-account email suppression filters.
     *
     * @since 1.0.1
     */
    private function remove_account_email_suppression_filters() {
        remove_filter( 'wp_send_new_user_notification_to_admin', array( $this, 'suppress_wp_user_notification' ), 10 );
        remove_filter( 'wp_send_new_user_notification_to_user', array( $this, 'suppress_wp_user_notification' ), 10 );
        remove_filter( 'woocommerce_email_enabled_customer_new_account', array( $this, 'suppress_wc_customer_new_account_email' ), 10 );
    }

    /**
     * Force WordPress new-user notification filters to false.
     *
     * @since 1.0.1
     * @param bool $should_send Current filter value.
     * @return bool
     */
    public function suppress_wp_user_notification( $should_send ) {
        return false;
    }

    /**
     * Force WooCommerce customer-new-account email filter to false.
     *
     * @since 1.0.1
     * @param bool     $enabled Current filter value.
     * @param WC_Email $email   WooCommerce email object.
     * @return bool
     */
    public function suppress_wc_customer_new_account_email( $enabled, $email ) {
        return false;
    }

    /**
     * Create/update the WooCommerce customer when WooCommerce is active.
     *
     * @since 1.0.0
     * @param int    $wp_user_id WordPress user ID.
     * @param string $email      User email.
     * @param string $name       User display name.
     * @return bool
     */
    private function maybe_create_woocommerce_customer( $wp_user_id, $email, $name, $phone ) {
        if ( ! $this->is_woocommerce_available() ) {
            return false;
        }

        $name_parts = preg_split( '/\s+/', trim( $name ) );
        $first_name = ! empty( $name_parts[0] ) ? $name_parts[0] : '';
        $last_name  = count( $name_parts ) > 1 ? implode( ' ', array_slice( $name_parts, 1 ) ) : '';

        $customer_class = 'WC_Customer';
        $customer       = new $customer_class( $wp_user_id );
        $customer->set_email( $email );
        $customer->set_first_name( $first_name );
        $customer->set_last_name( $last_name );
        if ( ! empty( $phone ) ) {
            $customer->set_billing_phone( $phone );
        }
        $customer->save();

        return true;
    }

    /**
     * Check whether WooCommerce is available and loaded.
     *
     * @since 1.0.0
     * @return bool
     */
    private function is_woocommerce_available() {
        return class_exists( 'WooCommerce' ) && class_exists( 'WC_Customer' );
    }

    /**
     * Resolve a Better Auth user id to linked WordPress user id.
     *
     * @since 1.0.1
     * @param string $ba_user_id Better Auth user id.
     * @return int
     */
    private function resolve_wp_user_id_from_ba_user_id( $ba_user_id ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ba_user';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT wpUserId FROM `{$table_name}` WHERE id = %s",
                $ba_user_id
            )
        );

        if ( ! $row || empty( $row->wpUserId ) ) {
            return 0;
        }

        return (int) $row->wpUserId;
    }

    /**
     * Return a stable raw body string used for signature hashing.
     *
     * @since 1.0.1
     * @param WP_REST_Request $request Incoming request.
     * @return string
     */
    private function get_request_body_for_signature( $request ) {
        if ( method_exists( $request, 'get_body' ) ) {
            return (string) $request->get_body();
        }

        return '';
    }

    /**
     * Determine if nonce has already been used.
     *
     * @since 1.0.1
     * @param string $nonce Nonce value.
     * @return bool
     */
    private function is_nonce_replayed( $nonce ) {
        $cache_key = 'better_auth_sig_nonce_' . md5( $nonce );
        return (bool) get_transient( $cache_key );
    }

    /**
     * Persist nonce to short-lived cache for replay protection.
     *
     * @since 1.0.1
     * @param string $nonce Nonce value.
     */
    private function mark_nonce_as_used( $nonce ) {
        $cache_key = 'better_auth_sig_nonce_' . md5( $nonce );
        set_transient( $cache_key, 1, self::NONCE_CACHE_TTL );
    }
}