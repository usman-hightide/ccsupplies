<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Ledger {
    private static function table(){ global $wpdb; return $wpdb->prefix . 'wcss_store_monthly_ledger'; }
    public static function ym_now(): string { return gmdate('Y-m'); }

    public static function install(): void {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            store_id BIGINT UNSIGNED NOT NULL,
            ym CHAR(7) NOT NULL,
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
    }

    /** Quick existence check */
    public static function exists(): bool {
        global $wpdb;
        $table = self::table();
        $found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        return ( $found === $table );
    }
    

    public static function bump( int $store_id, string $ym, int $orders_delta, float $amount_delta ): void {
        global $wpdb;
        $t = self::table();
        $now = current_time( 'mysql' );
        // Upsert
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $t (store_id, ym, orders_count, spend_total, updated_at)
             VALUES (%d,%s,%d,%f,%s)
             ON DUPLICATE KEY UPDATE
               orders_count = GREATEST(orders_count + VALUES(orders_count), 0),
               spend_total  = GREATEST(spend_total  + VALUES(spend_total),  0),
               updated_at   = VALUES(updated_at)",
            $store_id, $ym, $orders_delta, $amount_delta, $now
        ) );
    }

    public static function get( int $store_id, string $ym ): array {
        global $wpdb;
        $t = self::table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT orders_count, spend_total FROM $t WHERE store_id=%d AND ym=%s",
            $store_id, $ym
        ), ARRAY_A );
        return $row ?: [ 'orders_count' => 0, 'spend_total' => 0.0 ];
    }
}