<?php
/**
 * Edit an order while it waits for approval (SOW 1.16).
 *
 * Supply Ops can change quantities, remove items and add approved products. The order total, the
 * ordering policy (budget, order limit, window) and each line's vendor are rechecked after the edit,
 * so approving sends the edited order. Only orders awaiting approval can be edited.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_REST_Order_Edit {
    const NS      = 'wcss/v1';
    const MAX_QTY = 999;

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    public function can_manage( $req = null ): bool {
        return current_user_can( 'wcss_manage_portal' ) || current_user_can( 'manage_options' );
    }

    public function routes() {
        register_rest_route( self::NS, '/orders/(?P<id>\d+)/items', [
            'methods'             => 'PUT',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'save' ],
        ] );
        register_rest_route( self::NS, '/orders/(?P<id>\d+)/edit-products', [
            'methods'             => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'products' ],
        ] );
    }

    /** Only orders waiting for Supply Ops can change; anything else may already be with a vendor. */
    private static function editable( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return new WP_Error( 'not_found', 'Order not found.', [ 'status' => 404 ] );
        }
        if ( $order->get_status() !== 'awaiting-approval' ) {
            return new WP_Error( 'not_editable', 'Only orders awaiting approval can be edited.', [ 'status' => 409 ] );
        }
        return true;
    }

    /** The order's type decides which products can be added: ink and stationery never mix. */
    private static function order_is_ink( WC_Order $order ): bool {
        return class_exists( 'WCSS_Ink_Order' ) && WCSS_Ink_Order::order_is_ink( $order );
    }

    private static function product_is_ink( int $product_id ): bool {
        return class_exists( 'WCSS_Ink_Order' ) && WCSS_Ink_Order::product_is_ink( $product_id );
    }

    /** Today's winning vendor and price for a product, or null when it can't be ordered. */
    private static function offer( WC_Product $p ) {
        if ( function_exists( 'cc_vendor_prices_product_is_orderable' ) && ! cc_vendor_prices_product_is_orderable( $p ) ) {
            return null;
        }
        $r = function_exists( 'cc_vendor_prices_resolve_cheapest' ) ? cc_vendor_prices_resolve_cheapest( $p ) : [ 'vendor' => '', 'price' => null ];
        $price = $r['price'] !== null && $r['price'] !== '' ? (float) $r['price'] : (float) $p->get_price();
        if ( $price <= 0 ) return null;
        $vendor = (string) ( $r['vendor'] ?? '' );
        $label  = [ 'amazon' => 'Amazon', 'gt' => 'Grand & Toy' ][ $vendor ] ?? '';
        if ( $vendor === 'manual' && function_exists( 'cc_vendor_prices_manual_vendors' ) ) {
            $label = implode( ', ', cc_vendor_prices_manual_vendors( $p->get_id() ) );
        }
        return [ 'vendor' => $vendor, 'label' => $label, 'price' => round( $price, 2 ) ];
    }

    /** GET products that can be added to this order (same type, orderable, not already on it). */
    public function products( WP_REST_Request $req ) {
        $order = wc_get_order( (int) $req['id'] );
        $ok    = self::editable( $order );
        if ( is_wp_error( $ok ) ) return $ok;

        $ink    = self::order_is_ink( $order );
        $search = sanitize_text_field( (string) $req->get_param( 'search' ) );
        $have   = [];
        foreach ( $order->get_items() as $item ) {
            if ( $item instanceof WC_Order_Item_Product ) $have[ (int) $item->get_product_id() ] = true;
        }
        $args = [ 'status' => 'publish', 'limit' => 60, 'orderby' => 'title', 'order' => 'ASC', 'return' => 'objects' ];
        if ( $search !== '' ) $args['s'] = $search;

        $out = [];
        foreach ( wc_get_products( $args ) as $p ) {
            if ( isset( $have[ $p->get_id() ] ) || self::product_is_ink( $p->get_id() ) !== $ink ) continue;
            $offer = self::offer( $p );
            if ( ! $offer ) continue;
            $img   = $p->get_image_id() ? (string) wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );
            $out[] = [ 'id' => $p->get_id(), 'name' => $p->get_name(), 'sku' => $p->get_sku(), 'price' => $offer['price'], 'vendor_label' => $offer['label'], 'image' => $img ];
            if ( count( $out ) >= 20 ) break;
        }
        return rest_ensure_response( [ 'items' => $out, 'ink' => $ink ] );
    }

    /**
     * PUT the order's new lines: { lines: [ { item_id, qty } | { product_id, qty } ] }.
     * A current line left out, or given qty 0, is removed. Existing lines keep their unit price;
     * added products take today's best vendor price.
     */
    public function save( WP_REST_Request $req ) {
        $order = wc_get_order( (int) $req['id'] );
        $ok    = self::editable( $order );
        if ( is_wp_error( $ok ) ) return $ok;

        $body  = $req->get_json_params() ?: [];
        $lines = is_array( $body['lines'] ?? null ) ? $body['lines'] : null;
        if ( $lines === null ) {
            return new WP_Error( 'bad_lines', 'Send the order lines to save.', [ 'status' => 400 ] );
        }

        $current = [];
        foreach ( $order->get_items() as $item_id => $item ) {
            if ( $item instanceof WC_Order_Item_Product ) $current[ (int) $item_id ] = $item;
        }
        $ink = self::order_is_ink( $order );

        // Validate everything before changing anything.
        $keep = []; $add = [];
        foreach ( $lines as $l ) {
            $qty = isset( $l['qty'] ) ? (int) $l['qty'] : 0;
            if ( $qty < 0 || $qty > self::MAX_QTY ) {
                return new WP_Error( 'bad_qty', sprintf( 'Quantities must be between 1 and %d.', self::MAX_QTY ), [ 'status' => 400 ] );
            }
            if ( ! empty( $l['item_id'] ) ) {
                $iid = (int) $l['item_id'];
                if ( ! isset( $current[ $iid ] ) ) {
                    return new WP_Error( 'bad_item', 'An item is no longer on this order. Reload and try again.', [ 'status' => 409 ] );
                }
                if ( $qty > 0 ) $keep[ $iid ] = $qty;
                continue;
            }
            $pid = (int) ( $l['product_id'] ?? 0 );
            if ( ! $pid || $qty === 0 ) continue;
            $p = wc_get_product( $pid );
            if ( ! $p || $p->get_status() !== 'publish' ) {
                return new WP_Error( 'bad_product', 'That product is not in the approved list.', [ 'status' => 400 ] );
            }
            if ( self::product_is_ink( $pid ) !== $ink ) {
                return new WP_Error( 'mixed_type', $ink ? 'Only ink products can be added to an ink order.' : 'Ink products cannot be added to a stationery order.', [ 'status' => 400 ] );
            }
            $offer = self::offer( $p );
            if ( ! $offer ) {
                return new WP_Error( 'not_orderable', sprintf( '%s has no vendor price, so it cannot be ordered.', $p->get_name() ), [ 'status' => 400 ] );
            }
            foreach ( $current as $iid => $item ) {
                if ( (int) $item->get_product_id() === $pid ) { $keep[ $iid ] = ( $keep[ $iid ] ?? 0 ) + $qty; continue 2; }
            }
            $add[ $pid ] = [ 'product' => $p, 'qty' => ( $add[ $pid ]['qty'] ?? 0 ) + $qty, 'offer' => $offer ];
        }
        if ( ! $keep && ! $add ) {
            return new WP_Error( 'empty', 'An order needs at least one item. To cancel it, reject the order instead.', [ 'status' => 400 ] );
        }
        foreach ( $keep as $iid => $q ) {
            if ( $q > self::MAX_QTY ) return new WP_Error( 'bad_qty', sprintf( 'Quantities must be between 1 and %d.', self::MAX_QTY ), [ 'status' => 400 ] );
        }

        $before  = (float) $order->get_total();
        $changes = [];

        foreach ( $current as $iid => $item ) {
            $name = $item->get_name();
            $old  = (int) $item->get_quantity();
            if ( ! isset( $keep[ $iid ] ) ) {
                $order->remove_item( $iid );
                $changes[] = [ 'name' => $name, 'from' => $old, 'to' => 0 ];
                continue;
            }
            $new = $keep[ $iid ];
            if ( $new === $old ) continue;
            $unit = $old > 0 ? (float) $item->get_subtotal() / $old : 0.0;
            $line = wc_format_decimal( $unit * $new, 2 );
            $item->set_quantity( $new );
            $item->set_subtotal( $line );
            $item->set_total( $line );
            $item->save();
            $changes[] = [ 'name' => $name, 'from' => $old, 'to' => $new ];
        }
        foreach ( $add as $pid => $a ) {
            $line    = wc_format_decimal( $a['offer']['price'] * $a['qty'], 2 );
            $item_id = $order->add_product( $a['product'], $a['qty'], [ 'subtotal' => $line, 'total' => $line ] );
            if ( $item_id && in_array( $a['offer']['vendor'], [ 'amazon', 'gt' ], true ) ) {
                $item = $order->get_item( $item_id );
                $item->update_meta_data( '_cc_vendor', $a['offer']['vendor'] );
                $item->update_meta_data( '_cc_vendor_price', $a['offer']['price'] );
                $item->save();
            }
            $changes[] = [ 'name' => $a['product']->get_name(), 'from' => 0, 'to' => $a['qty'] ];
        }

        if ( ! $changes ) {
            return rest_ensure_response( [ 'ok' => true, 'changed' => false, 'policy' => $order->get_meta( WCSS_Order_Policy::META ) ] );
        }

        $order->calculate_totals();
        $after = (float) $order->get_total();

        // Recheck the store's rules on the edited order, and which vendor each line now goes to.
        $policy = WCSS_Order_Policy::evaluate_order( $order );
        $order->update_meta_data( WCSS_Order_Policy::META, $policy );
        $vendors = function_exists( 'cc_auto_vendor_submit_collect_lines' ) ? cc_auto_vendor_submit_collect_lines( $order ) : [];

        $me = wp_get_current_user();
        $order->add_order_note( sprintf(
            'Items edited by %s: %s. Total %s → %s.',
            $me->display_name ?: $me->user_login,
            implode( '; ', array_map( static function ( $c ) {
                return $c['to'] === 0 ? 'removed ' . $c['name'] : ( $c['from'] === 0 ? 'added ' . $c['to'] . ' × ' . $c['name'] : $c['name'] . ' ' . $c['from'] . ' → ' . $c['to'] );
            }, $changes ) ),
            wp_strip_all_tags( wc_price( $before ) ),
            wp_strip_all_tags( wc_price( $after ) )
        ) );
        $order->save();

        if ( class_exists( 'WCSS_Order_Events' ) ) {
            WCSS_Order_Events::log( $order->get_id(), 'items_edit', [ 'by' => $me->ID, 'changes' => $changes, 'before' => $before, 'after' => $after, 'in_policy' => $policy['in_policy'], 'reasons' => $policy['reasons'] ] );
        }

        return rest_ensure_response( [
            'ok'         => true,
            'changed'    => true,
            'before'     => $before,
            'after'      => $after,
            'policy'     => $policy,
            'unassigned' => array_values( (array) ( $vendors['unassigned'] ?? [] ) ),
            'vendors'    => array_values( (array) ( $vendors['vendors'] ?? [] ) ),
        ] );
    }
}
