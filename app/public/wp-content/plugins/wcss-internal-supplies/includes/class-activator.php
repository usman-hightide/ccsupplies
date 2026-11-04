<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Activator {

    public static function activate() {
        self::maybe_create_roles();
        self::maybe_create_tables();
        self::maybe_add_default_options();
        // Flush rewrite just in case (CPTs will be added later)
        flush_rewrite_rules();
    }

    public static function deactivate() {
        // Keep data by default; just flush rewrites
        flush_rewrite_rules();
    }

    private static function maybe_add_default_options() {
        $defaults = [
            'visibility_mode'     => 'public_catalog', // public_catalog | fully_private
            'default_monthly_quota' => 1,
            'budget_enforcement'  => 1,
            'exempt_product_ids'  => '',
            'bypass_method'       => 'internal_requisition', // internal_requisition | cod | coupon
            'count_statuses'      => ['wc-awaiting-approval','wc-approved'],
        ];
        add_option( 'wcss_settings', $defaults, '', false );
    }

    private static function maybe_create_roles() {
        // Store employee: can shop but not see other stores’ data (caps handled later in flows)
        add_role( 'store_employee', __( 'Store Employee', 'wcss' ), [
            'read' => true,
        ] );

        // // Supply admin: start with Shop Manager caps if available
        // $shop_manager = get_role( 'shop_manager' );
        // if ( $shop_manager ) {
        //     add_role( 'supply_admin', __( 'Supply Admin', 'wcss' ), $shop_manager->capabilities );
        // } else {
        //     add_role( 'supply_admin', __( 'Supply Admin', 'wcss' ), [ 'read' => true, 'manage_woocommerce' => true ] );
        // }
    }

    private static function maybe_create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Budget ledger table (existing)
        $budget_table = $wpdb->prefix . 'wcss_budget_ledger';
        $sql_budget   = "CREATE TABLE IF NOT EXISTS $budget_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            period_yyyymm CHAR(6) NOT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(32) NOT NULL DEFAULT 'committed', -- committed | approved | released
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY store_period (store_id, period_yyyymm),
            KEY order_idx (order_id)
        ) $charset_collate;";

        // Email log table
        $email_table = $wpdb->prefix . 'wcss_email_logs';
        $sql_email   = "CREATE TABLE IF NOT EXISTS $email_table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            mailed_to TEXT NOT NULL,
            subject TEXT NOT NULL,
            message LONGTEXT NULL,
            headers LONGTEXT NULL,
            context VARCHAR(191) NULL,
            sent_by BIGINT UNSIGNED NULL,
            request_uri TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY context (context)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_budget );
        dbDelta( $sql_email );
    }


}
