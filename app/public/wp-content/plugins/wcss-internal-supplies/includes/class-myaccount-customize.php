<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_MyAccount_Customize {

    public function __construct() {

        add_filter( 'woocommerce_account_menu_items', [ $this, 'filter_menu_items' ], 999 );
        add_action( 'wp_head', [ $this, 'disable_edit_account_css' ] );
        add_action( 'woocommerce_save_account_details_errors', [ $this, 'block_account_details_save' ], 10, 2 );
        add_filter( 'woocommerce_get_endpoint_url', [ $this, 'map_explore_link' ], 10, 4 );


        add_action( 'init', [ $this, 'add_profile_endpoint' ] );
        add_filter( 'woocommerce_get_query_vars', [ $this, 'add_profile_query_var' ] );

        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_profile_menu' ], 998 );
        add_action( 'woocommerce_account_profile_endpoint', [ $this, 'render_profile' ] );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
        add_action( 'woocommerce_before_account_orders', [ $this, 'orders_heading' ] );
        add_action( 'woocommerce_my_account_my_orders_column_order-date', [ $this, 'order_date_cell' ] );
        add_action( 'woocommerce_my_account_my_orders_column_order-status', [ $this, 'order_status_cell' ] );
        add_action( 'woocommerce_my_account_my_orders_column_order-total', [ $this, 'order_total_cell' ] );
        add_action( 'woocommerce_edit_account_form_start', [ $this, 'account_details_note' ] );
        add_action( 'woocommerce_view_order', [ $this, 'view_order_header' ], 5 );

    }

    public function enqueue_styles() {
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
        $file = 'frontend/assets/css/my-account.css';
        wp_enqueue_style( 'wcss-my-account', WCSS_URL . $file, [], filemtime( WCSS_DIR . $file ) );
    }

    public function orders_heading( $has_orders ) {
        if ( ! $has_orders ) return;
        echo '<div class="wcss-acc-head"><h2>' . esc_html__( 'Your orders', 'wcss' ) . '</h2><p>' . esc_html__( 'Track every order your store has placed.', 'wcss' ) . '</p></div>';
    }

    public function order_date_cell( $order ) {
        $date = $order->get_date_created();
        if ( $date ) {
            echo '<time datetime="' . esc_attr( $date->date( 'c' ) ) . '">' . esc_html( $date->date_i18n( 'M j, Y' ) ) . '</time>';
        }
    }

    public function order_status_cell( $order ) {
        $tones = [
            'approved'          => 'is-ok',
            'processing'        => 'is-ok',
            'completed'         => 'is-ok',
            'awaiting-approval' => 'is-wait',
            'on-hold'           => 'is-wait',
            'rejected'          => 'is-bad',
            'cancelled'         => 'is-bad',
            'failed'            => 'is-bad',
        ];
        $status = $order->get_status();
        echo '<span class="wcss-status ' . esc_attr( $tones[ $status ] ?? 'is-neutral' ) . '">' . esc_html( wc_get_order_status_name( $status ) ) . '</span>';
        if ( doing_action( 'woocommerce_my_account_my_orders_column_order-status' ) ) $this->order_delivery_line( $order );
    }

    /** Order list: a short delivery line under the status once there is a date or tracking. */
    public function order_delivery_line( $order ) {
        if ( ! class_exists( 'WCSS_Shipment' ) || ! in_array( $order->get_status(), [ 'approved', 'processing', 'completed' ], true ) ) return;
        $sh = WCSS_Shipment::get( $order );
        if ( $sh['edd_label'] !== '' ) {
            echo '<span class="wcss-acc-items">' . esc_html( sprintf( __( 'Expected %s', 'wcss' ), $sh['edd_label'] ) ) . '</span>';
        } elseif ( $sh['tracking'] !== '' ) {
            echo '<span class="wcss-acc-items">' . esc_html__( 'Shipped', 'wcss' ) . '</span>';
        }
    }

    /** Single order: delivery date, carrier and a tracking link. */
    public function view_order_delivery( WC_Order $order ) {
        if ( ! class_exists( 'WCSS_Shipment' ) ) return;
        $status = $order->get_status();
        if ( ! in_array( $status, [ 'approved', 'processing', 'completed' ], true ) ) return;
        $sh = WCSS_Shipment::get( $order );
        ?>
        <div class="wcss-acc-card wcss-acc-delivery">
          <h3><?php esc_html_e( 'Delivery', 'wcss' ); ?></h3>
          <?php if ( ! $sh['has'] ) : ?>
            <p class="wcss-acc-muted"><?php esc_html_e( 'Your order is with the supplier. The delivery date and tracking show here as soon as we have them.', 'wcss' ); ?></p>
          <?php else : ?>
            <dl>
              <?php if ( $sh['edd_label'] !== '' ) : ?>
                <div><dt><?php esc_html_e( 'Expected delivery', 'wcss' ); ?></dt><dd><?php echo esc_html( $sh['edd_label'] ); ?></dd></div>
              <?php endif; ?>
              <?php if ( $sh['carrier_label'] !== '' ) : ?>
                <div><dt><?php esc_html_e( 'Carrier', 'wcss' ); ?></dt><dd><?php echo esc_html( $sh['carrier_label'] ); ?></dd></div>
              <?php endif; ?>
              <?php if ( $sh['tracking'] !== '' ) : ?>
                <div><dt><?php esc_html_e( 'Tracking number', 'wcss' ); ?></dt><dd>
                  <?php if ( $sh['url'] ) : ?>
                    <a href="<?php echo esc_url( $sh['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $sh['tracking'] ); ?> &rarr;</a>
                  <?php else : echo esc_html( $sh['tracking'] ); endif; ?>
                </dd></div>
              <?php endif; ?>
            </dl>
          <?php endif; ?>
        </div>
        <?php
    }

    public function order_total_cell( $order ) {
        $count = (int) $order->get_item_count();
        echo '<span class="wcss-acc-total">' . wp_kses_post( $order->get_formatted_order_total() ) . '</span>';
        /* translators: %d: number of items */
        echo '<span class="wcss-acc-items">' . esc_html( sprintf( _n( '%d item', '%d items', $count, 'wcss' ), $count ) ) . '</span>';
    }

    /** Top of a single order. Replaces WooCommerce's "Order #x was placed on…" sentence. */
    public function view_order_header( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $date = $order->get_date_created();
        ?>
        <a class="wcss-acc-back" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">&larr; <?php esc_html_e( 'Back to orders', 'wcss' ); ?></a>
        <div class="wcss-acc-card wcss-acc-orderhead">
          <div>
            <h2><?php echo esc_html( sprintf( __( 'Order #%s', 'wcss' ), $order->get_order_number() ) ); ?></h2>
            <?php if ( $date ) : ?>
              <p><?php echo esc_html( sprintf( __( 'Placed %s', 'wcss' ), $date->date_i18n( 'M j, Y' ) ) ); ?></p>
            <?php endif; ?>
          </div>
          <?php $this->order_status_cell( $order ); ?>
        </div>
        <?php
        $this->view_order_delivery( $order );
    }

    public function account_details_note() {
        echo '<div class="wcss-acc-head"><h2>' . esc_html__( 'Account details', 'wcss' ) . '</h2></div>';
        echo '<div class="wcss-acc-readonly"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg><span>'
            . esc_html__( 'These details are managed by Supply Ops. Contact them if anything needs to change.', 'wcss' ) . '</span></div>';
    }

    public function map_explore_link( $url, $endpoint, $value, $permalink ) {
        if ( 'wcss-explore' === $endpoint ) {
            $shop_url = wc_get_page_permalink( 'shop' );
            if ( ! $shop_url ) {
                // Fallback to home if shop isn't set
                $shop_url = home_url( '/' );
            }
            return $shop_url;
        }
        return $url;
    }
    
    /**
     * Hide menu entries: Downloads, Addresses
     */
    public function filter_menu_items( $items ) {
        unset( $items['downloads'] );     // Downloads
        unset( $items['edit-address'] );  // Addresses
        unset( $items['dashboard']);
        // Add "Explore Products" linking to the shop page.
        // We'll map its URL below so it doesn't need a registered endpoint.
        $new = [];
    
        // Place it right after "Orders" if present
        foreach ( $items as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'orders' === $key ) {
                $new['wcss-explore'] = __( 'Explore Products', 'wcss' );
            }
        }
    
        // If "orders" wasn't present, append it at the end
        if ( ! isset( $new['wcss-explore'] ) ) {
            $new['wcss-explore'] = __( 'Explore Products', 'wcss' );
        }
    
        return $new;
    }
    /**
     * Visually disable the Account Details form (inputs + submit) on that endpoint.
     * We keep it visible for transparency, but users can't edit.
     */
    public function disable_edit_account_css() {
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
        if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'edit-account' ) ) return;
        ?>
        <style>
          .woocommerce-EditAccountForm input,
          .woocommerce-EditAccountForm select,
          .woocommerce-EditAccountForm textarea {
            pointer-events: none !important;
            background: #f6f6f6 !important;
          }
          .woocommerce-EditAccountForm fieldset,
          .woocommerce-EditAccountForm .woocommerce-Button,
          .woocommerce-EditAccountForm button[type="submit"],
          .woocommerce-EditAccountForm input[type="submit"] {
            display: none !important;
          }
          /* Optional: hide password change fields */
          .woocommerce-EditAccountForm fieldset legend,
          .woocommerce-EditAccountForm fieldset p {
            display: none !important;
          }
        </style>
        <?php
    }

    /**
     * Hard-block saving account details (server-side safety).
     */
    public function block_account_details_save( $errors, $user ) {
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
        // Always prevent saves from the edit-account endpoint
        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-account' ) ) {
            $errors->add( 'wcss_readonly', __( 'Account details are managed by the administrator.', 'wcss' ) );
        }
    }

    public function add_profile_endpoint() {
        add_rewrite_endpoint( 'profile', EP_ROOT | EP_PAGES );
        // You can flush permalinks once manually in Settings → Permalinks (save)
    }

    // Tell Woo what the endpoint key is
    public function add_profile_query_var( $vars ) {
        $vars['profile'] = 'profile';
        return $vars;
    }
    
    public function add_profile_menu( $items ) {
        // Insert "Profile" as first item
        $new = [ 'profile' => __( 'Profile', 'wcss' ) ];
        return $new + $items;
    }
    
    public function render_profile() {
        if ( ! is_user_logged_in() ) { return; }
        $store_id = WCSS_User_Store_Map::get_user_store_id();
        if ( ! $store_id ) {
            echo '<p>' . esc_html__( 'Your account is not linked to a store. Please contact an administrator.', 'wcss' ) . '</p>';
            return;
        }
    
        $name     = get_the_title( $store_id );
        $code     = get_post_meta( $store_id, WCSS_Store_CPT::META_CODE, true );
        $addr     = get_post_meta( $store_id, WCSS_Store_CPT::META_ADDR, true );
        $city     = get_post_meta( $store_id, WCSS_Store_CPT::META_CITY, true );
        $state    = get_post_meta( $store_id, WCSS_Store_CPT::META_STATE, true );
        $postcode = get_post_meta( $store_id, WCSS_Store_CPT::META_POSTCODE, true );
        $country  = get_post_meta( $store_id, WCSS_Store_CPT::META_COUNTRY, true );
    
        $quota_meta = get_post_meta( $store_id, WCSS_Store_CPT::META_QUOTA, true );
        $quota = ( $quota_meta === '' ) ? (int) wcss_get_option( 'default_monthly_quota', 0 ) : (int) $quota_meta;
    
        $enf    = (bool) wcss_get_option( 'budget_enforcement', 1 );
        $budget = get_post_meta( $store_id, WCSS_Store_CPT::META_BUDGET, true );
        $has_budget = $enf && $budget !== '' && (float) $budget > 0;
    
        $countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : [];
        $code_c    = strtoupper( (string) ( $country ?: 'CA' ) ); // Stores default to Canada, same as checkout.
        $country   = $countries[ $code_c ] ?? $code_c;
        $phone     = get_post_meta( $store_id, '_wcss_store_phone', true );
        ?>
        <div class="wcss-acc-head"><h2><?php esc_html_e( 'Store profile', 'wcss' ); ?></h2></div>
        <div class="wcss-acc-card wcss-acc-store">
          <div>
            <strong><?php echo esc_html( $name ); ?></strong>
            <span><?php echo esc_html( trim( $city . ( $city && $state ? ', ' : '' ) . $state ) ); ?></span>
          </div>
          <?php if ( $code !== '' ) : ?>
            <span class="wcss-acc-code"><?php echo esc_html( sprintf( __( 'Store %s', 'wcss' ), $code ) ); ?></span>
          <?php endif; ?>
        </div>
        <div class="wcss-acc-card">
          <h3><?php esc_html_e( 'Ship-to address', 'wcss' ); ?></h3>
          <dl class="wcss-acc-rows">
            <div><dt><?php esc_html_e( 'Address', 'wcss' ); ?></dt><dd><?php echo esc_html( $addr ); ?></dd></div>
            <div><dt><?php esc_html_e( 'City', 'wcss' ); ?></dt><dd><?php echo esc_html( $city ); ?></dd></div>
            <div><dt><?php esc_html_e( 'Province', 'wcss' ); ?></dt><dd><?php echo esc_html( $state ); ?></dd></div>
            <div><dt><?php esc_html_e( 'Postal code', 'wcss' ); ?></dt><dd><?php echo esc_html( $postcode ); ?></dd></div>
            <div><dt><?php esc_html_e( 'Country', 'wcss' ); ?></dt><dd><?php echo esc_html( $country ); ?></dd></div>
            <?php if ( $phone !== '' ) : ?>
              <div><dt><?php esc_html_e( 'Phone', 'wcss' ); ?></dt><dd><?php echo esc_html( $phone ); ?></dd></div>
            <?php endif; ?>
          </dl>
        </div>
        <div class="wcss-acc-card">
          <h3><?php esc_html_e( 'Monthly limits', 'wcss' ); ?></h3>
          <dl class="wcss-acc-rows">
            <div><dt><?php esc_html_e( 'Orders per month', 'wcss' ); ?></dt><dd><?php echo $quota > 0 ? esc_html( $quota ) : esc_html__( 'Unlimited', 'wcss' ); ?></dd></div>
            <div><dt><?php esc_html_e( 'Monthly budget', 'wcss' ); ?></dt><dd><?php echo $has_budget ? wp_kses_post( wc_price( (float) $budget ) ) : esc_html__( 'No limit', 'wcss' ); ?></dd></div>
          </dl>
        </div>
        <p class="wcss-acc-note"><?php esc_html_e( 'Contact Supply Ops if any of these details need updating.', 'wcss' ); ?></p>
        <?php
    }


}