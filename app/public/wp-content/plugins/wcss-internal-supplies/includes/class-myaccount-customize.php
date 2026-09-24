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
        // Add "Explore products" linking to the shop page.
        // We'll map its URL below so it doesn't need a registered endpoint.
        $new = [];
    
        // Place it right after "Orders" if present
        foreach ( $items as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'orders' === $key ) {
                $new['wcss-explore'] = __( 'Explore products', 'wcss' );
            }
        }
    
        // If "orders" wasn't present, append it at the end
        if ( ! isset( $new['wcss-explore'] ) ) {
            $new['wcss-explore'] = __( 'Explore products', 'wcss' );
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
    
        ?>
        <style>
          .wcss-profile{border:1px solid #e5e7eb;border-radius:10px;padding:16px;background:#fafafa}
          .wcss-profile h3{margin-top:0}
          .wcss-grid{display:grid;grid-template-columns:160px 1fr;gap:8px}
          .wcss-mono{font-family:ui-monospace, SFMono-Regular, Menlo, monospace}
        </style>
        <div class="wcss-profile">
          <h3><?php esc_html_e('Store Profile', 'wcss'); ?></h3>
          <div class="wcss-grid">
            <div><?php esc_html_e('Store', 'wcss'); ?></div><div><?php echo esc_html( $name ); ?></div>
            <div><?php esc_html_e('Code', 'wcss'); ?></div><div class="wcss-mono"><?php echo esc_html( $code ); ?></div>
            <div><?php esc_html_e('Address', 'wcss'); ?></div><div><?php echo esc_html( $addr ); ?></div>
            <div><?php esc_html_e('City', 'wcss'); ?></div><div><?php echo esc_html( $city ); ?></div>
            <div><?php esc_html_e('State', 'wcss'); ?></div><div><?php echo esc_html( $state ); ?></div>
            <div><?php esc_html_e('Postcode', 'wcss'); ?></div><div><?php echo esc_html( $postcode ); ?></div>
            <div><?php esc_html_e('Country', 'wcss'); ?></div><div><?php echo esc_html( $country ); ?></div>
            <div><?php esc_html_e('Monthly order quota', 'wcss'); ?></div>
            <div><?php echo $quota > 0 ? esc_html( $quota ) : '<em>' . esc_html__('Unlimited','wcss') . '</em>'; ?></div>
            <div><?php esc_html_e('Monthly budget', 'wcss'); ?></div>
            <div>
              <?php
              if ( $has_budget ) {
                  echo wp_kses_post( wc_price( (float) $budget ) );
              } else {
                  echo '<em>' . esc_html__( 'No limit', 'wcss' ) . '</em>';
              }
              ?>
            </div>
          </div>
        </div>
        <?php
    }


}