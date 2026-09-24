<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Private_Portal {

    public function __construct() {

        add_action( 'login_init', [ $this, 'redirect_wp_login_to_account' ] );
        add_action( 'admin_init', [ $this, 'block_admin_for_non_admins' ] );
        // Force login for the entire front end
        add_action( 'template_redirect', [ $this, 'force_login_everywhere' ], 1 );

        // Disable all WooCommerce account registrations
        add_filter( 'woocommerce_registration_enabled', '__return_false', 999 );
        add_filter( 'woocommerce_enable_myaccount_registration', '__return_false', 999 );
        add_filter( 'woocommerce_checkout_registration_enabled', '__return_false', 999 );

        // If someone hits the WC "register" endpoint, send them to login
        add_action( 'template_redirect', [ $this, 'block_wc_register_endpoint' ], 2 );

        // Optional: hide the "Create an account" UI if any theme/template tries to show it
        add_filter( 'woocommerce_account_menu_items', [ $this, 'remove_register_menu_item' ], 999 );
        add_filter( 'woocommerce_login_redirect', [ $this, 'login_redirect' ], 10, 2 );

        add_action( 'template_redirect',          [ $this, 'redirect_manager_from_account' ], 8 );


    }

    public function redirect_manager_from_account() {
        // Skip in admin / REST / AJAX
        if ( is_admin() ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
    
        if ( ! is_user_logged_in() ) return;
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;
        if ( ! current_user_can( 'wcss_manage_portal' ) ) return;
    
        // Allow Woo logout endpoint to pass through
        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'customer-logout' ) ) return;
    
        // Avoid loops if we're already on /manager/
        $req = strtolower( $_SERVER['REQUEST_URI'] ?? '' );
        if ( strpos( $req, '/manager/' ) !== false ) return;
    
        wp_safe_redirect( home_url( '/manager/' ) );
        exit;
    }

    
    public function login_redirect( $redirect, $user ) {
        if ( user_can( $user, 'manage_options' ) ) {             // Admins
            return admin_url();
        }
        if ( user_can( $user, 'manage_woocommerce' ) ) {         // Shop Managers
            return home_url( '/manager/' );
        }
        // Store employees / supply managers
        return wc_get_page_permalink( 'myaccount' );
    }
    
    public function redirect_wp_login_to_account() {
        // Only redirect non-admins
        if ( ! current_user_can( 'manage_options' ) ) {
            $account_url = wc_get_page_permalink( 'myaccount' );
            if ( $account_url ) {
                wp_safe_redirect( $account_url );
                exit;
            }
        }
    }

    public function block_admin_for_non_admins() {
        // Admins can access wp-admin
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }
        // Allow admin-ajax for everyone
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }
        // Shop Managers should NOT access wp-admin → send to /manager/
        if ( current_user_can( 'manage_woocommerce' ) ) {
            wp_safe_redirect( home_url( '/manager/' ) );
            exit;
        }

        // Everyone else (employees/customers) → My Account
        wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
        exit;
    }

    public function force_login_everywhere() {


        // $req = $_SERVER['REQUEST_URI'] ?? '';
        // if ( preg_match( '#^/manager(/|$)#', $req ) ) { return; }


        if ( is_user_logged_in() ) {
            return;
        }
    
        // Allow Woo My Account as the single login page
        if ( function_exists('is_account_page') && is_account_page() ) {
            return;
        }
    
        // Allow WC lost-password / reset-password endpoints on My Account if your theme uses them
        if ( function_exists('is_wc_endpoint_url') && ( is_wc_endpoint_url('lost-password') || is_wc_endpoint_url('reset-password') ) ) {
            return;
        }

        // Inside force_login_everywhere()
        if ( is_user_logged_in() ) {
            // Prevent Shop Managers from accessing Woo's My Account
            if ( function_exists('is_account_page') && is_account_page() && current_user_can('manage_woocommerce') ) {
                wp_safe_redirect( home_url( '/manager/' ) );
                exit;
            }
            return;
        }
        
        // Allow REST & AJAX
        if ( defined('REST_REQUEST') && REST_REQUEST ) return;
        if ( wp_doing_ajax() ) return;
    
        // Everything else → My Account
        wp_safe_redirect( wc_get_page_permalink('myaccount') );
        exit;
    }
    /**
     * Block WC register endpoint entirely.
     */
    public function block_wc_register_endpoint() {
        if ( is_user_logged_in() ) return;

        // If theme routes to My Account / Register tab
        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            // If a ?action=register or endpoint path looks like "register"
            $is_register =
                ( isset( $_GET['action'] ) && $_GET['action'] === 'register' ) ||
                ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'register' ) );

            if ( $is_register ) {
                // Send to login form instead
                wp_safe_redirect( wp_login_url( home_url( '/' ) ) );
                exit;
            }
        }
    }

    public function remove_register_menu_item( $items ) {
        // unset( $items['customer-logout'] ); // keep logout as-is later if you want
        // There isn't a default "register" menu item in WC, but some themes add it.
        // If you know the key, unset it here.
        return $items;
    }

    private function is_wp_login_or_lostpassword() {
        // wp-login.php
        if ( isset( $GLOBALS['pagenow'] ) && $GLOBALS['pagenow'] === 'wp-login.php' ) {
            return true;
        }
        // Some themes use My Account lost-password endpoint; still okay to allow lost password on wp-login.php only.
        // If you want to also allow WC lost-password endpoint, uncomment the lines below:
        /*
        if ( function_exists( 'is_account_page' ) && is_account_page() && function_exists( 'is_wc_endpoint_url' ) ) {
            if ( is_wc_endpoint_url( 'lost-password' ) ) {
                return true;
            }
        }
        */
        return false;
    }


}