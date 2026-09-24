<?php
/**
 * Plugin Name: WCSS – Internal Supplies for WooCommerce
 * Description: Converts WooCommerce into an internal supplies portal with login-only ordering, monthly order quotas, budget enforcement, and approval workflow (scaffold).
 * Version:3.1.2
 * Author:  adex360 LTD
 * Text Domain: wcss
 * Domain Path: /languages
 * required wordpres version: Version 6.8.3 or above
 * php version: 8 or above
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** ----------------------------------------------------------------
 * Constants
 * --------------------------------------------------------------- */
define( 'WCSS_VERSION', '2.1.2' );
define( 'WCSS_FILE', __FILE__ );
define( 'WCSS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCSS_URL', plugin_dir_url( __FILE__ ) );

/** ----------------------------------------------------------------
 * Includes (minimal at this stage)
 * --------------------------------------------------------------- */

require_once WCSS_DIR . 'includes/class-status-policy.php';
require_once WCSS_DIR . 'includes/class-activator.php';
require_once WCSS_DIR . 'includes/class-admin-settings.php';
require_once WCSS_DIR . 'includes/class-store-cpt.php';
require_once WCSS_DIR . 'includes/class-user-store-map.php';
require_once WCSS_DIR . 'includes/class-order-statuses.php';
require_once WCSS_DIR . 'includes/class-approval-workflow.php';
require_once WCSS_DIR . 'includes/class-private-portal.php';
require_once WCSS_DIR . 'includes/class-checkout-store-lock.php';
require_once WCSS_DIR . 'includes/class-myaccount-customize.php';
require_once WCSS_DIR . 'includes/class-order-quota.php';
require_once WCSS_DIR . 'includes/class-notifications.php';
require_once WCSS_DIR . 'includes/class-quota-usage-ui.php';
require_once WCSS_DIR . 'includes/class-store-admin-columns.php';
require_once WCSS_DIR . 'includes/class-store-admin-highlight.php';
require_once WCSS_DIR . 'includes/class-admin-menu-restrict.php';
require_once WCSS_DIR . 'includes/class-order-events.php';

require_once WCSS_DIR . 'includes/class-checkout-guard.php';

// require_once WCSS_DIR . 'includes/class-manager-rest.php';
require_once WCSS_DIR . 'frontend/routes/class-routes-manager.php';

require_once WCSS_DIR . 'includes/rest/class-rest-orders.php';
require_once WCSS_DIR . 'includes/rest/class-rest-products.php';
require_once WCSS_DIR . 'includes/rest/class-rest-stores.php';
require_once WCSS_DIR . 'includes/class-wcss-ledger.php';
require_once WCSS_DIR . 'includes/rest/class-rest-ledger.php';

require_once WCSS_DIR . 'includes/class-wcss-vendors.php';
require_once WCSS_DIR . 'includes/rest/class-rest-vendors.php';
require_once WCSS_DIR . 'includes/rest/class-rest-reports.php';
require_once WCSS_DIR . 'includes/class-email-logger.php';
require_once WCSS_DIR . 'includes/class-email-log-admin.php';




/** ----------------------------------------------------------------
 * Activation / Deactivation
 * --------------------------------------------------------------- */
register_activation_hook( __FILE__, ['WCSS_Activator', 'activate'] );
register_deactivation_hook( __FILE__, ['WCSS_Activator', 'deactivate'] );

register_activation_hook( __FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'wcss_order_events';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(64) NOT NULL,          -- status_change|note|shipment|receiving|incident|exception
        payload LONGTEXT NULL,               -- JSON
        created_at DATETIME NOT NULL,
        created_by BIGINT UNSIGNED NULL,
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY type (type),
        KEY created_at (created_at)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
});

register_activation_hook( __FILE__, function () {
    global $wpdb;
    $table   = $wpdb->prefix . 'wcss_store_monthly_ledger';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        store_id BIGINT UNSIGNED NOT NULL,
        ym CHAR(7) NOT NULL,                 -- 'YYYY-MM'
        orders_count INT UNSIGNED NOT NULL DEFAULT 0,
        spend_total DECIMAL(16,2) NOT NULL DEFAULT 0.00,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_store_month (store_id, ym),
        KEY store_id (store_id),
        KEY ym (ym)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
});


/** ----------------------------------------------------------------
 * Helpers (keep these lightweight & pure)
 * --------------------------------------------------------------- */
/**
 * Get a plugin option with defaults merged.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */

 
function wcss_get_option( $key, $default = null ) {
    $defaults = [
        'visibility_mode'        => 'public_catalog', // public_catalog | fully_private
        'default_monthly_quota'  => 1,
        'budget_enforcement'     => 1,
        'exempt_product_ids'     => '',
        'bypass_method'          => 'internal_requisition', // internal_requisition | cod | coupon
        'count_statuses'         => ['wc-awaiting-approval','wc-approved'],
    ];
    $opts = wp_parse_args( get_option( 'wcss_settings', [] ), $defaults );
    return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
}

/**
 * Convenience: is site fully private (login required for all pages)?
 *
 * @return bool
 */
function wcss_is_fully_private() {
    return wcss_get_option( 'visibility_mode' ) === 'fully_private';
}

/** ----------------------------------------------------------------
 * Bootstrap
 * --------------------------------------------------------------- */
add_action( 'plugins_loaded', function() {

    // Load translations early
    load_plugin_textdomain( 'wcss', false, dirname( plugin_basename( WCSS_FILE ) ) . '/languages' );

    // Require WooCommerce
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'WCSS requires WooCommerce to be active.', 'wcss' ) . '</p></div>';
        } );
        return;
    }

    // Admin settings UI
    if ( is_admin() ) {
        new WCSS_Admin_Settings();
        new WCSS_Email_Log_Admin();
    }
    do_action( 'wcss_bootstrap' );


    
} );

add_action( 'wcss_bootstrap', function () {
    new WCSS_Store_CPT();
    new WCSS_User_Store_Map();
    new WCSS_Order_Statuses();
    new WCSS_Approval_Workflow();
    if ( class_exists( 'WCSS_Status_Policy' ) ) {
        new WCSS_Status_Policy();
    }
    new WCSS_Private_Portal();
    require_once WCSS_DIR . 'includes/class-gateway-internal-requisition.php';
    require_once WCSS_DIR . 'includes/class-gateway-internal-requisition-bootstrap.php';
    new WCSS_Gateway_Internal_Requisition_Bootstrap();
    new WCSS_Checkout_Store_Lock();
    new WCSS_MyAccount_Customize();
    new WCSS_Order_Quota();
    new WCSS_Notifications();
    new WCSS_Quota_Usage_UI();
    new WCSS_Store_Admin_Columns();
    new WCSS_Store_Admin_Highlight(); 
    new WCSS_Admin_Menu_Restrict();
    // new WCSS_Manager_REST();
    new WCSS_Route_Manager();

    new WCSS_REST_Orders();
    new WCSS_REST_Products();
    new WCSS_REST_Stores();
    new WCSS_REST_Ledger();
    new WCSS_Vendors();
    new WCSS_REST_Vendors();
    new WCSS_REST_Reports();
    new WCSS_Checkout_Guard();

} );

// Capture all outgoing emails via wp_mail for logging.
add_filter( 'wp_mail', [ 'WCSS_Email_Logger', 'capture_wp_mail' ], 999, 1 );



// adding logs for orders status changes to make order delivery reporting

add_action( 'woocommerce_order_status_changed', function( $order_id, $old, $new, $order ) {
    WCSS_Order_Events::log( (int) $order_id, 'status_change', [
        'old' => $old, 'new' => $new, 'note' => 'Status changed'
    ] );
}, 10, 4 );

add_action( 'woocommerce_new_order_note', function( $note_id, $args ) {
    // capture public order notes created via UI or API
    if ( ! empty( $args['order_id'] ) ) {
        WCSS_Order_Events::log( (int) $args['order_id'], 'note', [
            'note_id' => (int) $note_id, 'is_customer_note' => (bool) ($args['customer_note'] ?? false)
        ] );
    }
}, 10, 2 );



add_action( 'init', function () {
    foreach ( [ 'administrator', 'shop_manager' /*, 'supply_manager' */ ] as $role_name ) {
        if ( $role = get_role( $role_name ) ) {
            if ( ! $role->has_cap( 'wcss_manage_portal' ) ) {
                $role->add_cap( 'wcss_manage_portal' );
            }
        }
    }
}, 20);


//Update ledger on status transitions


add_action( 'woocommerce_order_status_changed', function( $order_id, $old, $new, $order ){
    // Which statuses count toward monthly usage
    $count_in   = apply_filters( 'wcss_ledger_count_in',   ['approved','processing','completed'] );
    $count_out  = apply_filters( 'wcss_ledger_count_out',  ['rejected','cancelled','refunded'] );

    // Determine delta to apply
    $delta = 0;
    if ( in_array( $old, $count_in, true ) && ! in_array( $new, $count_in, true ) ) { $delta = -1; }
    if ( ! in_array( $old, $count_in, true ) && in_array( $new, $count_in, true ) ) { $delta = +1; }

    // Amount delta: only when crossing in/out of count domain
    $amount = 0.0;
    if ( $delta !== 0 ) {
        $amount = (float) $order->get_total() * ( $delta > 0 ? 1 : -1 );
    }
    // Also treat explicit out statuses as removing if they weren’t already handled
    if ( $delta === 0 && in_array( $new, $count_out, true ) && in_array( $old, $count_in, true ) ) {
        $delta  = -1;
        $amount = - (float) $order->get_total();
    }

    if ( 0 === $delta && 0.0 === $amount ) return;

    // Get store id from order meta (set earlier when we map user->store at checkout)
    $store_id = (int) $order->get_meta( '_wcss_store_id' );
    if ( ! $store_id ) return;

    // Which month? Use order created month (so retro changes keep the original month)
    $created = $order->get_date_created();
    $ym = $created ? $created->date_i18n('Y-m') : WCSS_Ledger::ym_now();

    WCSS_Ledger::bump( $store_id, $ym, $delta, $amount );
}, 10, 4 );



/** ----------------------------------------------------------------
 * Optional: small guard if you later toggle "Fully Private"
 * (We will flesh this out in a later phase with redirects & exceptions.)
 * --------------------------------------------------------------- */
// add_action( 'template_redirect', function() {
//     if ( wcss_is_fully_private() && ! is_user_logged_in() ) {
//         // Allow wp-login, account, or specific endpoints as needed.
//         auth_redirect(); // sends to wp-login.php with redirect back
//     }
// } );

// In your main plugin file (wcss-internal-supplies.php)

register_activation_hook( __FILE__, [ 'WCSS_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WCSS_Plugin', 'deactivate' ] );

class WCSS_Plugin {
    public static function activate() {
        // Make sure your rewrite rules are registered
        $router = new WCSS_Route_Manager();
        $router->register_rewrite();

        // Flush them so WP rewrites with new rules
        flush_rewrite_rules();
    }

    public static function deactivate() {
        // On deactivate, just flush to clean out our custom rules
        flush_rewrite_rules();
    }
}




add_action('init', function(){
    if ($r = get_role('shop_manager')) { $r->add_cap('wcss_manage_portal'); }
}, 20);



add_action('template_redirect', function(){
    // Log what WP parsed
    if ( isset($_GET['wcss_debug']) && current_user_can('manage_options') ) {
        error_log( 'WCSS DEBUG vars: ' . print_r([
            'wcss'        => get_query_var('wcss'),
            'wcss_action' => get_query_var('wcss_action'),
            'wcss_id'     => get_query_var('wcss_id'),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        ], true) );
    }

    // Ultra simple test renderer (short-circuits the theme):
    if ( get_query_var('wcss') === 'dashboard' && isset($_GET['wcss_probe']) ) {
        status_header(200); nocache_headers(); show_admin_bar(false);
        echo '<div style="padding:20px;font:14px/1.4 system-ui">Router OK — dashboard</div>';
        exit;
    }
}, 0);



// saving store information on checkout


add_action('woocommerce_checkout_create_order', function( $order, $data ){
    $store_id = (int) get_user_meta( get_current_user_id(), '_wcss_store_id', true );
    if ( $store_id ) {
        $order->update_meta_data( '_wcss_store_id', $store_id );
    }
}, 10, 2);



add_action( 'admin_init', function(){
    if ( ! current_user_can('manage_options') ) return;
    if ( get_option('wcss_vendor_meta_backfilled') ) return;

    $terms = get_terms([ 'taxonomy'=>'wcss_vendor', 'hide_empty'=>false ]);
    if ( is_wp_error($terms) ) return;
    foreach ( $terms as $t ) {
        foreach ( [ 'wcss_vendor_phone','wcss_vendor_email','wcss_vendor_address' ] as $k ) {
            if ( '' === get_term_meta( $t->term_id, $k, true ) ) {
                update_term_meta( $t->term_id, $k, '' );
            }
        }
    }
    update_option('wcss_vendor_meta_backfilled', 1);
});


// Add this to your plugin or theme functions.php
add_shortcode( 'current_user_name', function() {
    if ( is_user_logged_in() ) {
        $user = wp_get_current_user();
        $first_name = $user->first_name ? $user->first_name : $user->display_name;
        $first_name = esc_html( $first_name );

        return '<p class="current-user-name" style="color: #fff; margin: 0;">' . $first_name . '</p>';
    }
    return '';
});



add_action( 'phpmailer_init', function ( $phpmailer ) {
    // Only override if not already configured by another plugin
    if ( ! $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
        return;
    }

    // Credentials live in wp-config.php (not committed):
    // define( 'WCSS_SMTP_USER', 'no-reply@ccsupplies.ca' );
    // define( 'WCSS_SMTP_PASSWORD', '...' );
    if ( ! defined( 'WCSS_SMTP_PASSWORD' ) || ! WCSS_SMTP_PASSWORD ) {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = 'smtp.office365.com';
    $phpmailer->Port       = 587;
    $phpmailer->SMTPAuth   = true;
    $phpmailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

    $phpmailer->Username   = defined( 'WCSS_SMTP_USER' ) && WCSS_SMTP_USER
        ? WCSS_SMTP_USER
        : 'no-reply@ccsupplies.ca';
    $phpmailer->Password   = WCSS_SMTP_PASSWORD;

    $phpmailer->setFrom( $phpmailer->Username, 'CC Internal Supply Store' );
});
