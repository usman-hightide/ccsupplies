<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Admin_Menu_Restrict {

    // 🔐 CHANGE THIS to the only user who can see everything:
    private string $exempt_user_login = 'umer@adex360.com'; // e.g., 'umer'

    public function __construct() {
        
        // Classic menus
        add_action( 'admin_menu',       [ $this, 'hide_left_menu' ], 9999 );
        add_action( 'admin_bar_menu',   [ $this, 'hide_admin_bar' ], 999 );

        // wc-admin (React) navigation + features
        add_filter( 'woocommerce_navigation_core_menu_items', [ $this, 'filter_wc_navigation_items' ], 999 );
        add_filter( 'woocommerce_navigation_menu_items',      [ $this, 'filter_wc_navigation_items' ], 999 ); // older alias
        add_filter( 'woocommerce_admin_features',             [ $this, 'filter_wc_admin_features' ], 999 );

        // Guard direct access to blocked pages
        add_action( 'current_screen',   [ $this, 'guard_direct_access' ] );

        // Final safety: hide by CSS if anything survives
        add_action( 'admin_head',       [ $this, 'css_hide_leftovers' ] );
    }

    // private function is_exempt_user(): bool {
    //     if ( ! is_user_logged_in() ) return false;
    //     $u = wp_get_current_user();
    //     if ( ! $u || ! $u->exists() ) return false;

    //     // A) by login
    //     if ( $u->user_login === $this->exempt_user_login ) return true;

    //     // B) by ID (optional)
    //     // if ( (int) $u->ID === 123 ) return true;

    //     return false;
    // }

    private function is_exempt_user(): bool {
        if ( ! is_user_logged_in() ) return false;
    
        $u = wp_get_current_user();
        if ( ! $u || ! $u->exists() ) return false;
    
        // Bypass for site admins / portal managers / network super admins
        if ( user_can( $u, 'manage_options' ) || user_can( $u, 'wcss_manage_portal' ) || is_super_admin( $u->ID ) ) {
            return true;
        }
    
        // Also allow the explicitly exempted identity (login OR email)
        if ( $u->user_login === $this->exempt_user_login || $u->user_email === $this->exempt_user_login ) {
            return true;
        }
    
        return false;
    }
    

    /* ------------------------------
     * Classic wp-admin sidebar
     * ------------------------------*/

    public function hide_left_menu() {
        if ( $this->is_exempt_user() ) return;

        // Core
        remove_menu_page( 'edit.php' );          // Posts
        remove_menu_page( 'edit-comments.php' ); // Comments

        // Woo classic submenus under "WooCommerce"
        $remove_subs = [
            'wc-reports', 'wc-settings', 'wc-status', 'wc-addons',
            'edit.php?post_type=shop_coupon', // Coupons (classic)
            // sometimes aliased:
            'woocommerce-reports', 'woocommerce-analytics', 'woocommerce-marketing', 'woocommerce-payments',
        ];
        foreach ( $remove_subs as $slug ) {
            remove_submenu_page( 'woocommerce', $slug );
        }

        // Prune wc-admin SPA entries that are registered as submenus
        global $submenu;
        if ( isset( $submenu['woocommerce'] ) && is_array( $submenu['woocommerce'] ) ) {
            foreach ( $submenu['woocommerce'] as $i => $item ) {
                $slug = $item[2] ?? '';
                if ( $this->is_wc_admin_spa_slug_to_hide( $slug ) ) {
                    unset( $submenu['woocommerce'][ $i ] );
                }
            }
        }
    }

    private function is_wc_admin_spa_slug_to_hide( string $slug ): bool {
        // Any wc-admin virtual submenu with these path prefixes should go
        if ( stripos( $slug, 'wc-admin&path=' ) === false ) return false;
        foreach ( ['/analytics','/marketing','/payments','/customers','/coupons','/extensions'] as $n ) {
            if ( stripos( $slug, $n ) !== false ) return true;
        }
        return false;
    }

    /* ------------------------------
     * wc-admin (React) NAV items
     * ------------------------------*/
    public function filter_wc_navigation_items( array $items ): array {
        if ( $this->is_exempt_user() ) return $items;

        // Item IDs used by wc-admin navigation
        $block_ids = [
            'woocommerce-analytics',
            'woocommerce-marketing',
            'woocommerce-payments',
            'woocommerce-customers',
            'woocommerce-coupons',
            'woocommerce-extensions',
        ];

        // Items may be associative (id => config) or numerically indexed arrays with ['id'=>...]
        $filtered = [];
        foreach ( $items as $key => $item ) {
            $id = is_array( $item ) && isset( $item['id'] ) ? $item['id'] : ( is_string( $key ) ? $key : '' );
            if ( in_array( $id, $block_ids, true ) ) {
                continue; // drop it
            }
            $filtered[ $key ] = $item;
        }
        return $filtered;
    }

    /**
     * Disable wc-admin feature modules for non-exempt users.
     * Prevents React app from enabling these sections.
     */
    public function filter_wc_admin_features( array $features ): array {
        if ( $this->is_exempt_user() ) return $features;

        $remove = [ 'analytics', 'marketing', 'payments' ];
        return array_values( array_diff( $features, $remove ) );
    }

    /* ------------------------------
     * Admin bar
     * ------------------------------*/
    public function hide_admin_bar( WP_Admin_Bar $bar ) {
        if ( $this->is_exempt_user() ) return;

        $bar->remove_node( 'comments' );
        $bar->remove_node( 'new-post' );

        // wc-admin potential nodes
        foreach ( [
            'woocommerce-analytics','woocommerce-marketing','woocommerce-payments',
            'woocommerce-coupons','woocommerce-customers','woocommerce-extensions'
        ] as $id ) {
            $bar->remove_node( $id );
        }
    }

    /* ------------------------------
     * Direct access guard
     * ------------------------------*/
    public function guard_direct_access( WP_Screen $screen ) {
        if ( $this->is_exempt_user() ) return;

        // Block Posts/Comments screens
        $blocked_screens = [ 'edit-post','post','edit-comments','comment' ];
        if ( in_array( $screen->id, $blocked_screens, true ) ) {
            wp_safe_redirect( admin_url() ); exit;
        }

        // Block Woo classic screens
        $blocked_wc_screens = [
            'woocommerce_page_wc-reports',
            'woocommerce_page_wc-settings',
            'woocommerce_page_wc-status',
            'woocommerce_page_wc-addons',
            'shop_coupon', 'edit-shop_coupon',
        ];
        if ( in_array( $screen->id, $blocked_wc_screens, true ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=woocommerce' ) ); exit;
        }

        // Block wc-admin SPA routes
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'wc-admin' ) {
            $path = strtolower( (string) ( $_GET['path'] ?? '' ) );
            foreach ( ['/analytics','/marketing','/payments','/customers','/coupons','/extensions'] as $n ) {
                if ( str_starts_with( $path, $n ) ) {
                    wp_safe_redirect( admin_url( 'admin.php?page=woocommerce' ) ); exit;
                }
            }
        }
    }

    /* ------------------------------
     * CSS fallback (just in case)
     * ------------------------------*/
    public function css_hide_leftovers() {
        if ( $this->is_exempt_user() ) return;
        ?>
        <style>
            /* Sidebar wc-admin items can render late; hide by href as a last resort */
            #adminmenu a[href*="wc-admin&path=/analytics"],
            #adminmenu a[href*="wc-admin&path=/marketing"],
            #adminmenu a[href*="wc-admin&path=/payments"],
            #adminmenu a[href*="wc-admin&path=/customers"],
            #adminmenu a[href*="wc-admin&path=/coupons"],
            #adminmenu a[href*="wc-admin&path=/extensions"] { display:none !important; }
        </style>
        <?php
    }
}