<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Order_Events {
    public static function log( int $order_id, string $type, array $payload = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcss_order_events';
        $wpdb->insert( $table, [
            'order_id'   => $order_id,
            'type'       => sanitize_key( $type ),
            'payload'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
            'created_at' => current_time( 'mysql', false ),
            'created_by' => get_current_user_id() ?: null,
        ] );
    }

    public static function for_order( int $order_id, int $limit = 50 ) : array {
        global $wpdb;
        $table = $wpdb->prefix . 'wcss_order_events';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE order_id=%d ORDER BY id DESC LIMIT %d",
            $order_id, $limit
        ), ARRAY_A );
        foreach ( $rows as &$r ) {
            $r['payload'] = $r['payload'] ? json_decode( $r['payload'], true ) : [];
        }
        return $rows ?: [];
    }
}
