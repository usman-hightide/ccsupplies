<?php
if ( ! defined('ABSPATH') ) exit;

class WCSS_Checkout_Guard {
    public function __construct() {
        add_action( 'woocommerce_checkout_process', [ $this, 'require_store_assignment' ] );
    }

    public function require_store_assignment() {
        // Only enforce for your portal users; skip for admins etc. If you want to enforce for everyone logged in, remove the role check.
        $user_id = get_current_user_id();
        if ( ! $user_id ) return;

        // If this is meant for store employees only, enforce here:
        if ( ! current_user_can('store_employee') ) return;

        $store_id = (int) get_user_meta( $user_id, '_wcss_store_id', true );
        if ( $store_id <= 0 || get_post_type( $store_id ) !== 'store_location' ) {
            wc_add_notice( __( 'You must be assigned to a store before placing an order. Please contact your administrator.', 'wcss' ), 'error' );
        }
    }
}