<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Prefills checkout billing from the user's assigned store, keeps fields editable,
 * and stamps the order with store meta. Shipping / coupons stay off (internal requisition).
 */
class WCSS_Checkout_Store_Lock {

    public function __construct() {
        add_filter( 'woocommerce_checkout_fields', [ $this, 'prefill_checkout_fields' ], 20 );
        add_filter( 'woocommerce_checkout_get_value', [ $this, 'prefill_checkout_value' ], 20, 2 );

        add_action( 'woocommerce_checkout_create_order', [ $this, 'attach_store_meta_to_order' ], 10, 2 );

        add_filter( 'woocommerce_cart_needs_shipping', '__return_false', 99 );
        add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false', 99 );
        add_filter( 'woocommerce_coupons_enabled', '__return_false', 99 );
    }

    /**
     * Active store for the current user + billing defaults + snapshot.
     */
    private function get_user_store() {
        $store_id = WCSS_User_Store_Map::get_user_store_id();
        if ( ! $store_id ) {
            return [ 0, [], [] ];
        }

        $title    = get_the_title( $store_id );
        $code     = get_post_meta( $store_id, WCSS_Store_CPT::META_CODE, true );
        $addr1    = get_post_meta( $store_id, WCSS_Store_CPT::META_ADDR, true );
        $city     = get_post_meta( $store_id, WCSS_Store_CPT::META_CITY, true );
        $state    = get_post_meta( $store_id, WCSS_Store_CPT::META_STATE, true );
        $postcode = get_post_meta( $store_id, WCSS_Store_CPT::META_POSTCODE, true );
        $country  = get_post_meta( $store_id, WCSS_Store_CPT::META_COUNTRY, true );

        $user  = wp_get_current_user();
        $email = ( $user && $user->user_email ) ? $user->user_email : '';
        $first = ( $user && $user->first_name ) ? $user->first_name : '';
        $last  = ( $user && $user->last_name ) ? $user->last_name : '';

        $vals = [
            'billing_company'   => $title,
            'billing_first_name'=> $first,
            'billing_last_name' => $last,
            'billing_email'     => $email,
            'billing_phone'     => '',
            'billing_address_1' => $addr1,
            'billing_address_2' => '',
            'billing_city'      => $city,
            'billing_postcode'  => $postcode,
            'billing_state'     => $state,
            'billing_country'   => $country ?: 'CA',
        ];

        $snapshot = [
            'store_name'     => $title,
            'store_code'     => $code,
            'store_address'  => $addr1,
            'store_city'     => $city,
            'store_state'    => $state,
            'store_postcode' => $postcode,
            'store_country'  => $country,
        ];

        return [ $store_id, $vals, $snapshot ];
    }

    /**
     * Prefill billing from the store; leave fields editable. Drop shipping (disabled).
     */
    public function prefill_checkout_fields( $fields ) {
        if ( ! is_user_logged_in() ) {
            return $fields;
        }

        // Internal requisition: no shipping form.
        unset( $fields['shipping'] );

        if ( isset( $fields['order']['order_comments'] ) ) {
            $fields['order']['order_comments']['label']       = __( 'Order Notes (optional)', 'wcss' );
            $fields['order']['order_comments']['placeholder'] = __( 'Add any special instructions for admin', 'wcss' );
        }

        list( $store_id, $vals ) = $this->get_user_store();
        if ( ! $store_id || empty( $fields['billing'] ) ) {
            return $fields;
        }

        foreach ( $vals as $key => $val ) {
            if ( ! isset( $fields['billing'][ $key ] ) ) {
                continue;
            }
            // Only seed empty defaults so a returning customer’s saved address wins if set.
            $current = isset( $fields['billing'][ $key ]['default'] ) ? $fields['billing'][ $key ]['default'] : '';
            if ( $current === '' || $current === null ) {
                $fields['billing'][ $key ]['default'] = $val;
            }
        }

        // Company = store name — helpful label for ops.
        if ( isset( $fields['billing']['billing_company'] ) ) {
            $fields['billing']['billing_company']['label'] = __( 'Store / company', 'wcss' );
        }

        return $fields;
    }

    /**
     * Seed checkout inputs from the store when the customer has no saved value.
     */
    public function prefill_checkout_value( $value, $input ) {
        if ( ! is_user_logged_in() || ( $value !== null && $value !== '' ) ) {
            return $value;
        }
        list( $store_id, $vals ) = $this->get_user_store();
        if ( ! $store_id || ! isset( $vals[ $input ] ) ) {
            return $value;
        }
        return $vals[ $input ];
    }

    /**
     * Attach store snapshot to order meta for auditing / policy.
     */
    public function attach_store_meta_to_order( $order, $data ) {
        if ( ! is_user_logged_in() ) {
            return;
        }
        list( $store_id, $vals, $snapshot ) = $this->get_user_store();
        if ( ! $store_id ) {
            return;
        }

        $order->update_meta_data( '_wcss_store_id', $store_id );
        $order->update_meta_data( '_wcss_store_name', $snapshot['store_name'] );
        $order->update_meta_data( '_wcss_store_code', $snapshot['store_code'] );
        $order->update_meta_data( '_wcss_store_address', $snapshot['store_address'] );
        $order->update_meta_data( '_wcss_store_city', $snapshot['store_city'] );
        $order->update_meta_data( '_wcss_store_state', $snapshot['store_state'] );
        $order->update_meta_data( '_wcss_store_postcode', $snapshot['store_postcode'] );
        $order->update_meta_data( '_wcss_store_country', $snapshot['store_country'] );
    }
}
