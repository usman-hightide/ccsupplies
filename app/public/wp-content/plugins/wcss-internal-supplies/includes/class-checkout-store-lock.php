<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Checkout_Store_Lock {

    public function __construct() {
        // Prefill + lock fields
        add_filter( 'woocommerce_checkout_fields', [ $this, 'prefill_and_lock_fields' ] );

        // Hard-enforce values (even if someone tampers with the DOM)
        add_action( 'woocommerce_after_checkout_validation', [ $this, 'enforce_store_values' ], 10, 2 );

        // Stamp order with store metadata snapshot
        add_action( 'woocommerce_checkout_create_order', [ $this, 'attach_store_meta_to_order' ], 10, 2 );

        // Optional: prevent editing addresses in My Account
        add_filter( 'woocommerce_customer_meta_fields', [ $this, 'hide_my_account_address_edit' ] );

        add_action( 'woocommerce_checkout_before_customer_details', [ $this, 'render_store_summary' ] );

        add_filter( 'woocommerce_cart_needs_shipping', '__return_false', 99 );

        add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false', 99 );

        add_filter( 'woocommerce_coupons_enabled', '__return_false', 99 );

        add_filter( 'woocommerce_default_address_fields', [ $this, 'make_address_fields_optional' ], 99 );

        add_filter( 'woocommerce_billing_fields', [ $this, 'make_billing_fields_optional' ], 99 );

        add_filter( 'woocommerce_shipping_fields', [ $this, 'make_shipping_fields_optional' ], 99 );


    }

    /**
     * Get active store for current user + its fields.
     */

     public function make_address_fields_optional( $fields ) {
        foreach ( $fields as &$f ) { $f['required'] = false; }
        return $fields;
    }
    public function make_billing_fields_optional( $fields ) {
        foreach ( $fields as &$f ) { $f['required'] = false; }
        // we still provide a billing_email default from the user profile
        return $fields;
    }
    public function make_shipping_fields_optional( $fields ) {
        foreach ( $fields as &$f ) { $f['required'] = false; }
        return $fields;
    }

    

     private function get_user_store() {
        $store_id = WCSS_User_Store_Map::get_user_store_id();
        if ( ! $store_id ) { return [ 0, [], [] ]; }
    
        $title   = get_the_title( $store_id );
        $code    = get_post_meta( $store_id, WCSS_Store_CPT::META_CODE, true );
        $addr1   = get_post_meta( $store_id, WCSS_Store_CPT::META_ADDR, true );
        $city    = get_post_meta( $store_id, WCSS_Store_CPT::META_CITY, true );
        $state   = get_post_meta( $store_id, WCSS_Store_CPT::META_STATE, true );
        $postcode= get_post_meta( $store_id, WCSS_Store_CPT::META_POSTCODE, true );
        $country = get_post_meta( $store_id, WCSS_Store_CPT::META_COUNTRY, true );
    
        $user = wp_get_current_user();
        $email = $user && $user->user_email ? $user->user_email : '';
    
        $vals = [
            // Billing (minimal, but valid)
            'billing_company'    => $title,
            'billing_first_name' => '',
            'billing_last_name'  => '',
            'billing_email'      => $email,
            'billing_phone'      => '',
            'billing_address_1'  => $addr1,
            'billing_address_2'  => '',
            'billing_city'       => $city,
            'billing_postcode'   => $postcode,
            'billing_state'      => $state,
            'billing_country'    => $country,
    
            // Shipping mirror
            'shipping_company'    => $title,
            'shipping_first_name' => '',
            'shipping_last_name'  => '',
            'shipping_address_1'  => $addr1,
            'shipping_address_2'  => '',
            'shipping_city'       => $city,
            'shipping_postcode'   => $postcode,
            'shipping_state'      => $state,
            'shipping_country'    => $country,
        ];
    
        $snapshot = [
            'store_name'    => $title,
            'store_code'    => $code,
            'store_address' => $addr1,
            'store_city'    => $city,
            'store_state'   => $state,
            'store_postcode'=> $postcode,
            'store_country' => $country,
        ];
    
        return [ $store_id, $vals, $snapshot ];
    }


    public function render_store_summary() {
        if ( ! is_user_logged_in() ) { return; }
        list( $store_id, , $snap ) = $this->get_user_store();
        if ( ! $store_id ) { return; }
    
        // Minimal, theme-agnostic card + CSS to hide headings/extra sections
        ?>
        <style>
          /* Hide empty Woo sections/headings since we removed fields */
          .woocommerce-billing-fields,
          .woocommerce-shipping-fields,
          .woocommerce-account-fields,
          .woocommerce-shipping-fields__field-wrapper,
          .woocommerce-billing-fields__field-wrapper,
          .woocommerce-additional-fields h3 { display:none !important; }
          .wcss-store-summary{border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:0 0 16px 0;background:#fafafa;}
          .wcss-store-summary h3{margin:0 0 8px 0;font-size:16px;}
          .wcss-store-summary dl{display:grid;grid-template-columns:140px 1fr;gap:6px;margin:0}
          .wcss-store-summary dt{color:#555}
          .wcss-store-summary dd{margin:0}
        </style>
        <div class="wcss-store-summary">
          <h3><?php echo esc_html__( 'Store Summary', 'wcss' ); ?></h3>
          <dl>
            <dt><?php esc_html_e( 'Store', 'wcss' ); ?></dt><dd><?php echo esc_html( $snap['store_name'] ); ?></dd>
            <dt><?php esc_html_e( 'Code', 'wcss' ); ?></dt><dd><?php echo esc_html( $snap['store_code'] ); ?></dd>
            <dt><?php esc_html_e( 'Address', 'wcss' ); ?></dt><dd><?php echo esc_html( $snap['store_address'] ); ?></dd>
            <dt><?php esc_html_e( 'City', 'wcss' ); ?></dt><dd><?php echo esc_html( $snap['store_city'] ); ?></dd>
            <dt><?php esc_html_e( 'State', 'wcss' ); ?></dt><dd><?php echo esc_html( $snap['store_state'] ); ?></dd>
            <dt><?php esc_html_e( 'Postcode', 'wcss' ); ?></dt><dd><?php echo esc_html( $snap['store_postcode'] ); ?></dd>
            <dt><?php esc_html_e( 'Country', 'wcss' ); ?></dt><dd><?php echo esc_html( $snap['store_country'] ); ?></dd>
          </dl>
        </div>
        <?php
    }


    /**
     * Prefill checkout fields and make them readonly/hidden.
     */

     public function prefill_and_lock_fields( $fields ) {
        if ( ! is_user_logged_in() ) return $fields;
    
        list( $store_id, $vals ) = $this->get_user_store();
        if ( ! $store_id ) return $fields;
    
        // First: make all checkout fields non-required, then remove them from UI.
        foreach ( [ 'billing', 'shipping', 'account' ] as $section ) {
            if ( isset( $fields[ $section ] ) ) {
                foreach ( $fields[ $section ] as $k => &$def ) {
                    $def['required'] = false;
                }
            }
        }
    
        // Keep only Order Notes in the 'order' section (if you want to keep it)
        if ( isset( $fields['order'] ) ) {
            $keep = [ 'order_comments' ];
            foreach ( array_keys( $fields['order'] ) as $k ) {
                if ( ! in_array( $k, $keep, true ) ) {
                    unset( $fields['order'][ $k ] );
                }
            }
            // Optional: relabel notes
            if ( isset( $fields['order']['order_comments'] ) ) {
                $fields['order']['order_comments']['label'] = __( 'Order Notes (optional)', 'wcss' );
                $fields['order']['order_comments']['placeholder'] = __( 'Add any special instructions for admin', 'wcss' );
            }
        }
    
        // Hide billing & shipping sections entirely from the UI
        unset( $fields['billing'], $fields['shipping'] );
    
        // We still enforce/store values server-side in enforce_store_values()
        return $fields;
    }

    /**
     * Force the posted values to match the store snapshot (anti-tamper).
     */
    public function enforce_store_values( $data, $errors ) {
        if ( ! is_user_logged_in() ) { return; }
        list( $store_id, $vals ) = $this->get_user_store();
        if ( ! $store_id ) { return; }

        // Overwrite $_POST values to guarantee consistency
        foreach ( $vals as $key => $val ) {
            $_POST[ $key ] = $val;
        }
    }

    /**
     * Attach store snapshot to order meta for auditing.
     */
    public function attach_store_meta_to_order( $order, $data ) {
        if ( ! is_user_logged_in() ) { return; }
        list( $store_id, $vals, $snapshot ) = $this->get_user_store();
        if ( ! $store_id ) { return; }

        $order->update_meta_data( '_wcss_store_id', $store_id );
        $order->update_meta_data( '_wcss_store_name', $snapshot['store_name'] );
        $order->update_meta_data( '_wcss_store_code', $snapshot['store_code'] );
        $order->update_meta_data( '_wcss_store_address', $snapshot['store_address'] );
        $order->update_meta_data( '_wcss_store_city', $snapshot['store_city'] );
        $order->update_meta_data( '_wcss_store_state', $snapshot['store_state'] );
        $order->update_meta_data( '_wcss_store_postcode', $snapshot['store_postcode'] );
        $order->update_meta_data( '_wcss_store_country', $snapshot['store_country'] );
    }

    /**
     * Optionally remove address editing in My Account (keeps everything admin-driven).
     */
    public function hide_my_account_address_edit( $fields ) {
        // Remove/disable most fields in the backend customer meta UI
        foreach ( [ 'billing','shipping' ] as $sec ) {
            if ( isset( $fields[ $sec ] ) ) {
                foreach ( $fields[ $sec ]['fields'] as $k => &$def ) {
                    $def['custom_attributes']['readonly'] = 'readonly';
                    $def['description'] = __( 'Managed by Internal Supplies system.', 'wcss' );
                }
            }
        }
        return $fields;
    }
}