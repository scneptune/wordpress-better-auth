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
     * Request signer and verifier service.
     *
     * @since 1.0.1
     * @var Better_Auth_Request_Signer
     */
    private $request_signer;

    /**
     * Create a new user sync service instance.
     *
     * @since 1.0.1
     * @param Better_Auth_Request_Signer|null $request_signer Optional signer dependency.
     */
    public function __construct( $request_signer = null ) {
        if ( null === $request_signer ) {
            $request_signer = new Better_Auth_Request_Signer();
        }

        $this->request_signer = $request_signer;
    }

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
                'permission_callback' => array( $this, 'verify_hmac_sync_signature' ),
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

            register_rest_route(
                'better-auth/v1',
                '/sync/shipping',
                array(
                    'methods'             => 'PATCH',
                    'callback'            => array( $this, 'sync_shipping_details' ),
                    'permission_callback' => array( $this, 'verify_hmac_sync_signature' ),
                    'args'                => array(
                        'ba_user_id' => array(
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                        'shipping_address' => array(
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
        return $this->request_signer->verify_request( $request );
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
                array( 'wp_user_id' => $wp_user_id ),
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
        return $this->sync_address_details( $request, 'billing' );
    }

    /**
     * Create or update WooCommerce/WordPress shipping details for a Better Auth user.
     *
     * @since 1.0.1
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response|WP_Error
     */
    public function sync_shipping_details( $request ) {
        return $this->sync_address_details( $request, 'shipping' );
    }

    /**
     * Shared address sync logic for billing and shipping updates.
     *
     * @since 1.0.1
     * @param WP_REST_Request $request      Incoming request.
     * @param string          $address_type Address type: billing|shipping.
     * @return WP_REST_Response|WP_Error
     */
    private function sync_address_details( $request, $address_type ) {
        if ( ! $this->is_woocommerce_available() ) {
            return new WP_Error(
                'rest_woocommerce_unavailable',
                __( 'WooCommerce is not active. Address sync endpoint is unavailable.', 'better-auth' ),
                array( 'status' => 503 )
            );
        }

        if ( ! in_array( $address_type, array( 'billing', 'shipping' ), true ) ) {
            return new WP_Error(
                'rest_invalid_address_type',
                __( 'Invalid address type for sync.', 'better-auth' ),
                array( 'status' => 400 )
            );
        }

        $address_param = $address_type . '_address';
        $ba_user_id    = sanitize_text_field( $request->get_param( 'ba_user_id' ) );
        $address_input = $request->get_param( $address_param );

        if ( empty( $ba_user_id ) ) {
            return new WP_Error(
                'rest_missing_ba_user_id',
                __( 'The "ba_user_id" field is required.', 'better-auth' ),
                array( 'status' => 400 )
            );
        }

        if ( ! is_array( $address_input ) ) {
            return new WP_Error(
                'rest_invalid_address_payload',
                __( 'The address payload must be an object.', 'better-auth' ),
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

        $address = array(
            'first_name' => sanitize_text_field( isset( $address_input['first_name'] ) ? $address_input['first_name'] : '' ),
            'last_name'  => sanitize_text_field( isset( $address_input['last_name'] ) ? $address_input['last_name'] : '' ),
            'address_1'  => sanitize_text_field( isset( $address_input['address_1'] ) ? $address_input['address_1'] : '' ),
            'address_2'  => sanitize_text_field( isset( $address_input['address_2'] ) ? $address_input['address_2'] : '' ),
            'city'       => sanitize_text_field( isset( $address_input['city'] ) ? $address_input['city'] : '' ),
            'state'      => sanitize_text_field( isset( $address_input['state'] ) ? $address_input['state'] : '' ),
            'postcode'   => sanitize_text_field( isset( $address_input['postcode'] ) ? $address_input['postcode'] : '' ),
            'country'    => sanitize_text_field( isset( $address_input['country'] ) ? $address_input['country'] : '' ),
        );

        if ( 'billing' === $address_type ) {
            $address['email'] = sanitize_email( isset( $address_input['email'] ) ? $address_input['email'] : '' );
            $address['phone'] = sanitize_text_field( isset( $address_input['phone'] ) ? $address_input['phone'] : '' );

            if ( ! empty( $address_input['email'] ) && empty( $address['email'] ) ) {
                return new WP_Error(
                    'rest_invalid_email',
                    __( 'Invalid billing email address.', 'better-auth' ),
                    array( 'status' => 400 )
                );
            }
        }

        $meta_map = array(
            'first_name' => $address_type . '_first_name',
            'last_name'  => $address_type . '_last_name',
            'address_1'  => $address_type . '_address_1',
            'address_2'  => $address_type . '_address_2',
            'city'       => $address_type . '_city',
            'state'      => $address_type . '_state',
            'postcode'   => $address_type . '_postcode',
            'country'    => $address_type . '_country',
        );

        if ( 'billing' === $address_type ) {
            $meta_map['email'] = 'billing_email';
            $meta_map['phone'] = 'billing_phone';
        }

        foreach ( $meta_map as $field_key => $meta_key ) {
            update_user_meta( $wp_user_id, $meta_key, isset( $address[ $field_key ] ) ? $address[ $field_key ] : '' );
        }

        if ( 'billing' === $address_type ) {
            // Keep the OTP contact value synchronized with billing phone.
            update_user_meta( $wp_user_id, 'phone_number', $address['phone'] );

            if ( ! empty( $address['email'] ) ) {
                $user_update_result = wp_update_user(
                    array(
                        'ID'         => $wp_user_id,
                        'user_email' => $address['email'],
                    )
                );

                if ( is_wp_error( $user_update_result ) ) {
                    return $user_update_result;
                }
            }
        }

        $woocommerce_customer_updated = $this->update_woocommerce_customer_address( $wp_user_id, $address_type, $address );

        return new WP_REST_Response(
            array(
                'wp_user_id'                   => $wp_user_id,
                'better_auth_user_id'          => $ba_user_id,
                'woocommerce_customer_updated' => $woocommerce_customer_updated,
                $address_param                 => $address,
            ),
            200
        );
    }

    /**
     * Apply billing/shipping fields onto the WooCommerce customer object.
     *
     * @since 1.0.1
     * @param int    $wp_user_id   WordPress user id.
     * @param string $address_type Address type: billing|shipping.
     * @param array  $address      Sanitized address data.
     * @return bool
     */
    private function update_woocommerce_customer_address( $wp_user_id, $address_type, $address ) {
        if ( ! $this->is_woocommerce_available() ) {
            return false;
        }

        $customer_class = 'WC_Customer';
        $customer       = new $customer_class( $wp_user_id );

        $setter_prefix = 'set_' . $address_type . '_';
        $address_keys  = array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

        foreach ( $address_keys as $key ) {
            $setter = $setter_prefix . $key;
            if ( method_exists( $customer, $setter ) ) {
                $customer->{$setter}( isset( $address[ $key ] ) ? $address[ $key ] : '' );
            }
        }

        if ( 'billing' === $address_type ) {
            if ( method_exists( $customer, 'set_billing_phone' ) ) {
                $customer->set_billing_phone( isset( $address['phone'] ) ? $address['phone'] : '' );
            }
            if ( method_exists( $customer, 'set_billing_email' ) ) {
                $customer->set_billing_email( isset( $address['email'] ) ? $address['email'] : '' );
            }
            if ( ! empty( $address['email'] ) && method_exists( $customer, 'set_email' ) ) {
                $customer->set_email( $address['email'] );
            }
        }

        $customer->save();

        return true;
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

}