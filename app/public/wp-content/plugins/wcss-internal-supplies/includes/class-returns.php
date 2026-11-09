<?php
/**
 * Return requests (SOW 1.24).
 *
 * A store asks to return items from an order it received: ordered in error, delivered damaged,
 * wrong item or missing items. Supply Ops sees every request in Supply Manager → Returns, approves
 * or rejects it with a note, then closes it once the vendor has taken the item back or credited it.
 * The store gets an email at each step and sees the status on its order in My Account.
 *
 * Arranging the return with Amazon or Grand & Toy stays with Supply Ops (their own portals);
 * nothing is sent to a vendor from here.
 *
 * Each request is a private post of type wcss_return, so nothing needs a new database table.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Returns {
    const POST_TYPE   = 'wcss_return';
    const META_ORDER  = '_wcss_ret_order';
    const META_STORE  = '_wcss_ret_store';
    const META_STATUS = '_wcss_ret_status';
    const META_DATA   = '_wcss_ret_data';
    const NOTE_MAX    = 1000;

    /** Status => [ label for Supply Ops and the store, pill tone ]. */
    const STATUSES = [
        'submitted' => [ 'Waiting for review', 'pending' ],
        'approved'  => [ 'Approved', 'approved' ],
        'rejected'  => [ 'Rejected', 'rejected' ],
        'closed'    => [ 'Closed', 'neutral' ],
    ];

    /** Reason => [ label, note required ]. */
    const REASONS = [
        'ordered_in_error' => [ 'Ordered in error', false ],
        'damaged'          => [ 'Delivered damaged', true ],
        'wrong_item'       => [ 'Wrong item received', true ],
        'missing'          => [ 'Items missing from the delivery', false ],
        'other'            => [ 'Other', true ],
    ];

    /** Which way a request can move. Closing means the vendor took it back or credited it. */
    const MOVES = [
        'submitted' => [ 'approved', 'rejected' ],
        'approved'  => [ 'closed', 'rejected' ],
        'rejected'  => [],
        'closed'    => [],
    ];

    /** Orders a store can return from: the ones that went to a supplier. */
    const ORDER_STATUSES = [ 'approved', 'processing', 'completed' ];

    public function __construct() {
        add_action( 'init', [ __CLASS__, 'register' ] );
        add_action( 'wcss_order_deleted', [ __CLASS__, 'delete_for_order' ] );
    }

    public static function register() {
        register_post_type( self::POST_TYPE, [
            'public'              => false,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'rewrite'             => false,
            'query_var'           => false,
            'supports'            => [ 'title' ],
            'label'               => 'Return requests',
        ] );
    }

    public static function status_label( string $s ): string {
        return self::STATUSES[ $s ][0] ?? $s;
    }

    public static function reason_label( string $r ): string {
        return self::REASONS[ $r ][0] ?? $r;
    }

    public static function number( int $id ): string {
        return 'R-' . $id;
    }

    /* ===================== Who can return what ===================== */

    /** The store user may return from this order: it is their store's, or they placed it. */
    public static function user_can_request( WC_Order $order, int $user_id ): bool {
        if ( ! $user_id ) return false;
        $store = (int) $order->get_meta( '_wcss_store_id' );
        $mine  = class_exists( 'WCSS_User_Store_Map' ) ? WCSS_User_Store_Map::get_user_store_id( $user_id ) : 0;
        return ( $store && $mine === $store ) || (int) $order->get_customer_id() === $user_id;
    }

    /** Null when the order can take a return request, otherwise why not. */
    public static function order_blocker( WC_Order $order ): ?string {
        if ( ! in_array( $order->get_status(), self::ORDER_STATUSES, true ) ) {
            return __( 'Returns can be requested once the order has been approved and sent to the supplier.', 'wcss' );
        }
        $days = (int) apply_filters( 'wcss_return_days', 0 ); // 0 = no time limit until Supply Ops sets one.
        $made = $order->get_date_created();
        if ( $days > 0 && $made && ( time() - $made->getTimestamp() ) > $days * DAY_IN_SECONDS ) {
            return sprintf( __( 'Returns can be requested up to %d days after the order.', 'wcss' ), $days );
        }
        return null;
    }

    /**
     * Quantity still open for return on each line: ordered minus what is already in a request
     * that wasn't rejected. [ item_id => [ name, sku, ordered, open, unit ] ].
     */
    public static function returnable( WC_Order $order ): array {
        $used = [];
        foreach ( self::for_order( $order->get_id() ) as $r ) {
            if ( $r['status'] === 'rejected' ) continue;
            foreach ( $r['lines'] as $l ) {
                $used[ $l['item_id'] ] = ( $used[ $l['item_id'] ] ?? 0 ) + (int) $l['qty'];
            }
        }
        $out = [];
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            $qty = (int) $item->get_quantity();
            if ( $qty <= 0 ) continue;
            $product = $item->get_product();
            $out[ (int) $item_id ] = [
                'name'    => html_entity_decode( $item->get_name(), ENT_QUOTES ),
                'sku'     => $product ? (string) $product->get_sku() : '',
                'ordered' => $qty,
                'open'    => max( 0, $qty - ( $used[ $item_id ] ?? 0 ) ),
                'unit'    => round( (float) $item->get_total() / $qty, 2 ),
            ];
        }
        return $out;
    }

    /* ===================== Create ===================== */

    /**
     * @param array $qty [ item_id => qty ]; lines at 0 are left out.
     * @return int|WP_Error The new request's id.
     */
    public static function create( WC_Order $order, int $user_id, string $reason, string $note, array $qty ) {
        if ( ! self::user_can_request( $order, $user_id ) ) {
            return new WP_Error( 'forbidden', __( 'You can only request returns for your own store’s orders.', 'wcss' ), [ 'status' => 403 ] );
        }
        if ( $why = self::order_blocker( $order ) ) {
            return new WP_Error( 'not_returnable', $why, [ 'status' => 409 ] );
        }
        $reason = sanitize_key( $reason );
        if ( ! isset( self::REASONS[ $reason ] ) ) {
            return new WP_Error( 'bad_reason', __( 'Choose why you are returning these items.', 'wcss' ), [ 'status' => 400 ] );
        }
        $note = trim( sanitize_textarea_field( $note ) );
        if ( self::REASONS[ $reason ][1] && $note === '' ) {
            return new WP_Error( 'need_note', __( 'Tell us what happened so Supply Ops can arrange the return.', 'wcss' ), [ 'status' => 400 ] );
        }
        if ( mb_strlen( $note ) > self::NOTE_MAX ) {
            return new WP_Error( 'long_note', sprintf( __( 'Keep the note under %d characters.', 'wcss' ), self::NOTE_MAX ), [ 'status' => 400 ] );
        }

        $open  = self::returnable( $order );
        $lines = [];
        foreach ( $qty as $item_id => $n ) {
            $item_id = (int) $item_id;
            $n       = is_numeric( $n ) ? (int) $n : -1;
            if ( $n === 0 ) continue;
            if ( ! isset( $open[ $item_id ] ) ) {
                return new WP_Error( 'bad_item', __( 'One of the items is not on this order.', 'wcss' ), [ 'status' => 400 ] );
            }
            if ( $n < 0 || $n > $open[ $item_id ]['open'] ) {
                return new WP_Error( 'bad_qty', sprintf(
                    __( 'You can return up to %1$d of “%2$s”.', 'wcss' ), $open[ $item_id ]['open'], $open[ $item_id ]['name']
                ), [ 'status' => 400 ] );
            }
            $lines[] = [
                'item_id' => $item_id,
                'name'    => $open[ $item_id ]['name'],
                'sku'     => $open[ $item_id ]['sku'],
                'qty'     => $n,
                'unit'    => $open[ $item_id ]['unit'],
                'vendor'  => self::line_vendor( $order, $item_id ),
            ];
        }
        if ( ! $lines ) {
            return new WP_Error( 'no_items', __( 'Choose at least one item and how many to return.', 'wcss' ), [ 'status' => 400 ] );
        }

        $id = wp_insert_post( [
            'post_type'   => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => sprintf( 'Return for order #%s', $order->get_order_number() ),
            'post_author' => $user_id,
        ], true );
        if ( is_wp_error( $id ) ) return $id;

        $data = [
            'reason'     => $reason,
            'note'       => $note,
            'lines'      => $lines,
            'user_id'    => $user_id,
            'created_at' => current_time( 'mysql' ),
            'history'    => [ [ 'status' => 'submitted', 'at' => current_time( 'mysql' ), 'by' => $user_id, 'note' => '' ] ],
        ];
        update_post_meta( $id, self::META_ORDER, $order->get_id() );
        update_post_meta( $id, self::META_STORE, (int) $order->get_meta( '_wcss_store_id' ) );
        update_post_meta( $id, self::META_STATUS, 'submitted' );
        update_post_meta( $id, self::META_DATA, $data );

        if ( class_exists( 'WCSS_Order_Events' ) ) {
            WCSS_Order_Events::log( $order->get_id(), 'return_requested', [
                'return' => self::number( $id ), 'reason' => self::reason_label( $reason ), 'items' => array_sum( array_column( $lines, 'qty' ) ),
            ] );
        }
        $order->add_order_note( sprintf( 'Return %s requested: %s.', self::number( $id ), self::reason_label( $reason ) ) );

        $ret = self::get( $id );
        do_action( 'wcss_return_changed', $ret, '' );
        return $id;
    }

    /* ===================== Supply Ops decision ===================== */

    /**
     * @param string $to approved | rejected | closed
     * @return array|WP_Error The updated request.
     */
    public static function decide( int $id, string $to, string $note, int $by ) {
        $ret = self::get( $id );
        if ( ! $ret ) {
            return new WP_Error( 'not_found', 'Return request not found.', [ 'status' => 404 ] );
        }
        $to = sanitize_key( $to );
        if ( ! isset( self::STATUSES[ $to ] ) ) {
            return new WP_Error( 'bad_status', 'Unknown action.', [ 'status' => 400 ] );
        }
        if ( ! in_array( $to, self::MOVES[ $ret['status'] ] ?? [], true ) ) {
            return new WP_Error( 'bad_move', sprintf( 'A request that is “%s” can’t be %s.', self::status_label( $ret['status'] ), $to === 'closed' ? 'closed' : $to ), [ 'status' => 409 ] );
        }
        $note = trim( sanitize_textarea_field( $note ) );
        if ( $to === 'rejected' && $note === '' ) {
            return new WP_Error( 'need_note', 'Tell the store why the return is rejected.', [ 'status' => 400 ] );
        }
        if ( mb_strlen( $note ) > self::NOTE_MAX ) {
            return new WP_Error( 'long_note', sprintf( 'Keep the note under %d characters.', self::NOTE_MAX ), [ 'status' => 400 ] );
        }

        $data = (array) get_post_meta( $id, self::META_DATA, true );
        $data['history'][] = [ 'status' => $to, 'at' => current_time( 'mysql' ), 'by' => $by, 'note' => $note ];
        update_post_meta( $id, self::META_DATA, $data );
        update_post_meta( $id, self::META_STATUS, $to );

        $order = wc_get_order( $ret['order_id'] );
        if ( class_exists( 'WCSS_Order_Events' ) ) {
            WCSS_Order_Events::log( $ret['order_id'], 'return_' . $to, [ 'return' => $ret['number'], 'note' => $note, 'by' => $by ] );
        }
        if ( $order ) {
            $order->add_order_note( sprintf( 'Return %s %s%s', $ret['number'], $to, $note !== '' ? ': ' . $note : '.' ) );
        }

        $old = $ret['status'];
        $ret = self::get( $id );
        do_action( 'wcss_return_changed', $ret, $old );
        return $ret;
    }

    /* ===================== Read ===================== */

    public static function get( int $id ): ?array {
        $p = get_post( $id );
        if ( ! $p || $p->post_type !== self::POST_TYPE ) return null;
        $data     = (array) get_post_meta( $id, self::META_DATA, true );
        $order_id = (int) get_post_meta( $id, self::META_ORDER, true );
        $store_id = (int) get_post_meta( $id, self::META_STORE, true );
        $status   = (string) get_post_meta( $id, self::META_STATUS, true ) ?: 'submitted';
        $order    = $order_id ? wc_get_order( $order_id ) : null;
        $user     = get_userdata( (int) ( $data['user_id'] ?? 0 ) );
        $lines    = array_values( (array) ( $data['lines'] ?? [] ) );
        $history  = [];
        foreach ( (array) ( $data['history'] ?? [] ) as $h ) {
            $u = get_userdata( (int) ( $h['by'] ?? 0 ) );
            $history[] = [
                'status' => (string) ( $h['status'] ?? '' ),
                'label'  => ( $h['status'] ?? '' ) === 'submitted' ? 'Requested' : self::status_label( (string) ( $h['status'] ?? '' ) ),
                'at'     => (string) ( $h['at'] ?? '' ),
                'by'     => $u ? $u->display_name : '',
                'note'   => (string) ( $h['note'] ?? '' ),
            ];
        }
        $last_note = '';
        foreach ( array_reverse( $history ) as $h ) {
            if ( $h['status'] !== 'submitted' && $h['note'] !== '' ) { $last_note = $h['note']; break; }
        }
        $value = 0.0;
        foreach ( $lines as $l ) $value += (float) $l['unit'] * (int) $l['qty'];
        return [
            'id'           => $id,
            'number'       => self::number( $id ),
            'order_id'     => $order_id,
            'order_number' => $order ? (string) $order->get_order_number() : (string) $order_id,
            'store'        => [
                'id'   => $store_id,
                'name' => $store_id ? html_entity_decode( get_the_title( $store_id ), ENT_QUOTES ) : '',
                'code' => $store_id && class_exists( 'WCSS_Store_CPT' ) ? (string) WCSS_Store_CPT::get_field( $store_id, 'code' ) : '',
            ],
            'requested_by' => $user ? [ 'name' => $user->display_name, 'email' => $user->user_email ] : [ 'name' => '', 'email' => '' ],
            'created_at'   => (string) ( $data['created_at'] ?? $p->post_date ),
            'status'       => $status,
            'status_label' => self::status_label( $status ),
            'tone'         => self::STATUSES[ $status ][1] ?? 'neutral',
            'reason'       => (string) ( $data['reason'] ?? '' ),
            'reason_label' => self::reason_label( (string) ( $data['reason'] ?? '' ) ),
            'note'         => (string) ( $data['note'] ?? '' ),
            'lines'        => $lines,
            'items'        => array_sum( array_map( 'intval', array_column( $lines, 'qty' ) ) ),
            'value'        => round( $value, 2 ),
            'vendors'      => array_values( array_unique( array_filter( array_column( $lines, 'vendor' ) ) ) ),
            'vendor_ref'   => $order ? self::vendor_ref( $order ) : '',
            'reply'        => $last_note,
            'history'      => $history,
            'next'         => self::MOVES[ $status ] ?? [],
        ];
    }

    /** All requests for one order, newest first. */
    public static function for_order( int $order_id ): array {
        $ids = get_posts( [
            'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids',
            'meta_key' => self::META_ORDER, 'meta_value' => $order_id, 'orderby' => 'ID', 'order' => 'DESC',
            'suppress_filters' => true,
        ] );
        return array_values( array_filter( array_map( [ __CLASS__, 'get' ], array_map( 'intval', $ids ) ) ) );
    }

    /**
     * @param array $args status: open | all | one status; store: id; limit
     */
    public static function query( array $args = [] ): array {
        $q = [
            'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'fields' => 'ids',
            'numberposts' => min( 1000, max( 1, (int) ( $args['limit'] ?? 500 ) ) ), 'orderby' => 'ID', 'order' => 'DESC',
            'suppress_filters' => true, 'meta_query' => [],
        ];
        $status = (string) ( $args['status'] ?? 'all' );
        if ( $status === 'open' ) {
            $q['meta_query'][] = [ 'key' => self::META_STATUS, 'value' => [ 'submitted', 'approved' ], 'compare' => 'IN' ];
        } elseif ( isset( self::STATUSES[ $status ] ) ) {
            $q['meta_query'][] = [ 'key' => self::META_STATUS, 'value' => $status ];
        }
        if ( ! empty( $args['store'] ) ) {
            $q['meta_query'][] = [ 'key' => self::META_STORE, 'value' => (int) $args['store'] ];
        }
        return array_values( array_filter( array_map( [ __CLASS__, 'get' ], array_map( 'intval', get_posts( $q ) ) ) ) );
    }

    /** Requests waiting for Supply Ops (for the menu badge). */
    public static function waiting_count(): int {
        return count( get_posts( [
            'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids',
            'meta_key' => self::META_STATUS, 'meta_value' => 'submitted', 'suppress_filters' => true,
        ] ) );
    }

    public static function delete_for_order( $order_id ) {
        foreach ( self::for_order( (int) $order_id ) as $r ) {
            wp_delete_post( $r['id'], true );
        }
    }

    /* ===================== Helpers ===================== */

    /** Who supplied the line, so Supply Ops knows where to arrange the return. */
    private static function line_vendor( WC_Order $order, int $item_id ): string {
        $item = $order->get_item( $item_id );
        if ( ! $item ) return '';
        $key = sanitize_key( (string) $item->get_meta( '_cc_vendor', true ) );
        if ( $key === 'amazon' ) return 'Amazon';
        if ( $key === 'gt' ) return 'Grand & Toy';
        $pid   = $item->get_product_id();
        $terms = $pid ? wp_get_post_terms( $pid, 'wcss_vendor' ) : [];
        if ( ! is_wp_error( $terms ) && $terms ) return html_entity_decode( $terms[0]->name, ENT_QUOTES );
        return '';
    }

    /** The vendor's own order numbers (Amazon order id, Grand & Toy PO), so Supply Ops can find the order there. */
    private static function vendor_ref( WC_Order $order ): string {
        $refs = [];
        $amz  = $order->get_meta( '_wcss_amazon_order_data' );
        if ( is_array( $amz ) ) {
            $ref  = (string) ( $amz['amazon_order_id'] ?? '' );
            $body = $amz['amazon_response']['raw_body'] ?? null;
            $body = is_string( $body ) ? json_decode( $body, true ) : null;
            $body = is_array( $body ) ? $body : (array) ( $amz['amazon_response']['data'] ?? [] );
            foreach ( (array) ( $body['lineItems'] ?? [] ) as $li ) {
                foreach ( (array) ( $li['acceptedItems'] ?? [] ) as $acc ) {
                    foreach ( (array) ( $acc['artifacts'] ?? [] ) as $art ) {
                        if ( $ref === '' && ( $art['acceptanceArtifactType'] ?? '' ) === 'OrderIdentifier' && ! empty( $art['identifier'] ) ) {
                            $ref = (string) $art['identifier'];
                        }
                    }
                }
            }
            if ( $ref !== '' ) $refs[] = 'Amazon ' . $ref;
        }
        $gt = $order->get_meta( '_wcss_gt_order_data' );
        if ( is_array( $gt ) && ! empty( $gt['po_id'] ) ) $refs[] = 'Grand & Toy PO ' . $gt['po_id'];
        return implode( ' · ', $refs );
    }
}

