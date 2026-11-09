<?php
/**
 * Permanently delete an order and every record linked to it (6 Nov 2026 — Chris: cleanup).
 *
 * Removes: the WooCommerce order (HPOS tables, legacy placeholder post, line items + item meta,
 * refunds), order notes, analytics lookup rows, held stock, scheduled vendor-submit jobs,
 * Supply Manager history (wcss_order_events), budget ledger rows and the email log entries
 * for that order. Stock reduced by the order is put back first, and the order is taken back
 * out of the store's monthly ledger totals.
 *
 * Does NOT cancel anything already placed with Amazon / Grand & Toy.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Order_Delete {

    /** Action Scheduler hooks that carry [ 'order_id' => N ] args. */
    const ORDER_JOBS = [ 'cc_auto_vendor_submit_async' ];

    /**
     * @return array{ok:bool,order_id:int,number:string,removed:array<string,int>}|WP_Error
     */
    public static function purge( int $order_id ) {
        global $wpdb;

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_type() !== 'shop_order' ) {
            return new WP_Error( 'not_found', 'Order not found.', [ 'status' => 404 ] );
        }
        $number = (string) $order->get_order_number();
        $ids    = array_merge( [ $order_id ], array_map( function ( $r ) { return (int) $r->get_id(); }, $order->get_refunds() ) );
        $in     = implode( ',', array_map( 'intval', $ids ) );
        $p      = $wpdb->prefix;
        $removed = [];

        // 1) Put back stock this order took, and release any checkout hold.
        if ( function_exists( 'wc_increase_stock_levels' ) ) {
            wc_increase_stock_levels( $order_id );
        }
        if ( function_exists( 'wc_release_stock_for_order' ) ) {
            wc_release_stock_for_order( $order );
        }

        // Monthly totals are kept per store, not per order, so nothing below can clear this.
        $removed += self::reverse_monthly_ledger( $order );

        // 2) Cancel pending vendor-submit jobs, then remove their history rows.
        foreach ( self::ORDER_JOBS as $hook ) {
            if ( function_exists( 'as_unschedule_all_actions' ) ) {
                as_unschedule_all_actions( $hook, [ 'order_id' => $order_id ] );
            }
        }
        if ( self::table_exists( "{$p}actionscheduler_actions" ) ) {
            $hooks  = "'" . implode( "','", array_map( 'esc_sql', self::ORDER_JOBS ) ) . "'";
            $args   = wp_json_encode( [ 'order_id' => $order_id ] );
            $action_ids = $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM {$p}actionscheduler_actions WHERE hook IN ($hooks) AND args = %s", $args ) );
            if ( $action_ids ) {
                $a = implode( ',', array_map( 'intval', $action_ids ) );
                if ( self::table_exists( "{$p}actionscheduler_logs" ) ) {
                    $wpdb->query( "DELETE FROM {$p}actionscheduler_logs WHERE action_id IN ($a)" );
                }
                $removed['scheduled_jobs'] = (int) $wpdb->query( "DELETE FROM {$p}actionscheduler_actions WHERE action_id IN ($a)" );
            }
        }

        // 3) Order notes (comments) + their meta.
        $note_ids = $wpdb->get_col( "SELECT comment_ID FROM {$p}comments WHERE comment_post_ID IN ($in)" );
        if ( $note_ids ) {
            $c = implode( ',', array_map( 'intval', $note_ids ) );
            $wpdb->query( "DELETE FROM {$p}commentmeta WHERE comment_id IN ($c)" );
            $removed['order_notes'] = (int) $wpdb->query( "DELETE FROM {$p}comments WHERE comment_ID IN ($c)" );
        }

        // 4) The order itself (HPOS + items + refunds). Fires woocommerce_delete_order for analytics.
        foreach ( $order->get_refunds() as $refund ) {
            $refund->delete( true );
        }
        $order->delete( true );
        $removed['order'] = 1;

        // 5) Safety sweep — anything Woo or the sync layer left behind.
        $sweep = [
            'order_items'        => [ "{$p}woocommerce_order_itemmeta", "order_item_id IN (SELECT order_item_id FROM {$p}woocommerce_order_items WHERE order_id IN ($in))" ],
            'order_items_rows'   => [ "{$p}woocommerce_order_items", "order_id IN ($in)" ],
            'hpos_meta'          => [ "{$p}wc_orders_meta", "order_id IN ($in)" ],
            'hpos_addresses'     => [ "{$p}wc_order_addresses", "order_id IN ($in)" ],
            'hpos_operational'   => [ "{$p}wc_order_operational_data", "order_id IN ($in)" ],
            'hpos_orders'        => [ "{$p}wc_orders", "id IN ($in) OR parent_order_id = " . (int) $order_id ],
            'legacy_postmeta'    => [ "{$p}postmeta", "post_id IN ($in)" ],
            'legacy_posts'       => [ "{$p}posts", "ID IN ($in) AND post_type IN ('shop_order','shop_order_placehold','shop_order_refund')" ],
            'analytics_stats'    => [ "{$p}wc_order_stats", "order_id IN ($in)" ],
            'analytics_products' => [ "{$p}wc_order_product_lookup", "order_id IN ($in)" ],
            'analytics_tax'      => [ "{$p}wc_order_tax_lookup", "order_id IN ($in)" ],
            'analytics_coupons'  => [ "{$p}wc_order_coupon_lookup", "order_id IN ($in)" ],
            'reserved_stock'     => [ "{$p}wc_reserved_stock", "order_id IN ($in)" ],
            'download_perms'     => [ "{$p}woocommerce_downloadable_product_permissions", "order_id IN ($in)" ],
            'history'            => [ "{$p}wcss_order_events", "order_id IN ($in)" ],
            'budget_ledger'      => [ "{$p}wcss_budget_ledger", "order_id IN ($in)" ],
        ];
        foreach ( $sweep as $key => [ $table, $where ] ) {
            if ( ! self::table_exists( $table ) ) continue;
            $n = (int) $wpdb->query( "DELETE FROM {$table} WHERE {$where}" );
            if ( $n ) $removed[ $key ] = ( $removed[ $key ] ?? 0 ) + $n;
        }

        // 6) Email log entries about this order (subjects carry "#<number>").
        if ( self::table_exists( "{$p}wcss_email_logs" ) && $number !== '' ) {
            $re = '#' . preg_quote( $number, '' ) . '([^0-9]|$)';
            $n  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wcss_email_logs WHERE subject REGEXP %s", $re ) );
            if ( $n ) $removed['email_logs'] = $n;
        }

        if ( function_exists( 'wp_cache_flush_group' ) ) wp_cache_flush_group( 'orders' );
        clean_post_cache( $order_id );

        if ( function_exists( 'wc_get_logger' ) ) {
            $u = wp_get_current_user();
            wc_get_logger()->info(
                sprintf( 'Order #%s (ID %d) permanently deleted by %s (user %d). Removed: %s', $number, $order_id, $u ? $u->user_login : '?', get_current_user_id(), wp_json_encode( $removed ) ),
                [ 'source' => 'wcss-order-delete' ]
            );
        }
        do_action( 'wcss_order_deleted', $order_id, $number, $removed );

        return [ 'ok' => true, 'order_id' => $order_id, 'number' => $number, 'removed' => $removed ];
    }

    /** Undo what the status-change hook in wcss-internal-supplies.php added for this order. */
    private static function reverse_monthly_ledger( WC_Order $order ): array {
        $store_id = (int) $order->get_meta( '_wcss_store_id' );
        $counted  = in_array( $order->get_status(), apply_filters( 'wcss_ledger_count_in', [ 'approved', 'processing', 'completed' ] ), true );
        $is_ink   = class_exists( 'WCSS_Ink_Order' ) && WCSS_Ink_Order::order_is_ink( $order );
        if ( ! $store_id || ! $counted || $is_ink || ! class_exists( 'WCSS_Ledger' ) ) {
            return [];
        }
        $created = $order->get_date_created();
        $ym      = $created ? $created->date_i18n( 'Y-m' ) : WCSS_Ledger::ym_now();
        WCSS_Ledger::bump( $store_id, $ym, -1, - (float) $order->get_total() );
        return [ 'monthly_ledger' => 1 ];
    }

    private static function table_exists( string $table ): bool {
        global $wpdb;
        static $seen = [];
        if ( ! isset( $seen[ $table ] ) ) {
            $seen[ $table ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        }
        return $seen[ $table ];
    }
}
