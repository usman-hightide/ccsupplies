<?php
/**
 * New order from the Supply Manager portal: place an order for any store with any active vendor.
 *
 * Amazon and Grand & Toy orders finish on those vendors' own checkout pages, where tax and shipping
 * are worked out. Manual vendor orders are finished here, because they have no online checkout.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_REST_New_Order {
    const NS = 'wcss/v1';

    /** Marks an order placed by Supply Ops. Its vendor checkout is finished by hand. */
    const META = '_wcss_manager_order';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    public function can_manage( $req = null ): bool {
        return current_user_can( 'wcss_manage_portal' ) || current_user_can( 'manage_options' );
    }

    public function routes() {
        register_rest_route( self::NS, '/new-order/meta', [
            'methods' => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'meta' ],
        ]);
        register_rest_route( self::NS, '/new-order/products', [
            'methods' => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'products' ],
        ]);
        register_rest_route( self::NS, '/new-order/policy', [
            'methods' => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'policy' ],
        ]);
        register_rest_route( self::NS, '/new-order/checkout', [
            'methods' => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'checkout' ],
        ]);
    }

    private static function name( string $s ): string {
        return html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    private function active_vendors(): array {
        $out   = [];
        $terms = get_terms( [ 'taxonomy' => 'wcss_vendor', 'hide_empty' => false ] );
        if ( is_wp_error( $terms ) ) return $out;
        foreach ( WCSS_Vendor_Status::only_active( $terms ) as $t ) {
            $out[] = [ 'id' => (int) $t->term_id, 'name' => self::name( $t->name ), 'key' => WCSS_Vendor_Status::vendor_key( $t->slug ) ];
        }
        usort( $out, static function ( $a, $b ) {
            return [ $a['key'] === '', $a['name'] ] <=> [ $b['key'] === '', $b['name'] ];
        } );
        return $out;
    }

    /** GET /new-order/meta — stores and active vendors for the two dropdowns. */
    public function meta( WP_REST_Request $req ) {
        $stores = [];
        $posts  = get_posts( [ 'post_type' => WCSS_Store_CPT::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
        foreach ( $posts as $s ) {
            $stores[] = [
                'id'    => (int) $s->ID,
                'name'  => self::name( $s->post_title ),
                'code'  => (string) WCSS_Store_CPT::get_field( $s->ID, 'code' ),
                'ready' => WCSS_Checkout_Store_Lock::store_address_complete( (int) $s->ID ),
            ];
        }
        return rest_ensure_response( [ 'stores' => $stores, 'vendors' => $this->active_vendors() ] );
    }

    /** Price this vendor charges for a product, or null when it has none. */
    private function vendor_price( WC_Product $p, string $key ): ?float {
        if ( $key === '' ) {
            $stored = function_exists( 'cc_vendor_prices_woo_unit_price' ) ? cc_vendor_prices_woo_unit_price( $p ) : null;
            return $stored !== null && $stored > 0 ? (float) $stored : null;
        }
        foreach ( cc_vendor_prices_get_for_product( $p->get_id() ) as $row ) {
            if ( cc_vendor_prices_normalize_vendor( (string) $row->vendor ) === $key
                && (string) $row->status === 'ok' && is_numeric( $row->price ) && (float) $row->price > 0 ) {
                return (float) $row->price;
            }
        }
        return null;
    }

    /** ASIN or G&T part ID; '' for manual vendors or when none is set. */
    private function vendor_ref( WC_Product $p, string $key ): string {
        if ( $key === 'amazon' ) return strtoupper( trim( (string) get_post_meta( $p->get_id(), 'amazon_asin', true ) ) );
        if ( $key === 'gt' && function_exists( 'cc_gt_part_for_product' ) ) return (string) cc_gt_part_for_product( $p->get_id() );
        return '';
    }

    private function vendor_or_error( int $id ) {
        foreach ( $this->active_vendors() as $v ) {
            if ( $v['id'] === $id ) return $v;
        }
        return new WP_Error( 'bad_vendor', 'Choose an active vendor.', [ 'status' => 400 ] );
    }

    /** GET /new-order/products?vendor=ID&search= — approved products this vendor supplies. */
    public function products( WP_REST_Request $req ) {
        $vendor = $this->vendor_or_error( (int) $req->get_param( 'vendor' ) );
        if ( is_wp_error( $vendor ) ) return $vendor;
        $term = trim( (string) $req->get_param( 'search' ) );

        $q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        $items = [];
        $total = 0;
        foreach ( $q->posts as $pid ) {
            if ( ! in_array( $vendor['id'], WCSS_Vendor_Status::assigned_ids( $pid ), true ) ) continue;
            $p = wc_get_product( $pid );
            if ( ! $p ) continue;
            $name = $p->get_name();
            $sku  = (string) $p->get_sku();
            if ( $term !== '' && stripos( $name, $term ) === false && stripos( $sku, $term ) === false ) continue;
            $total++;
            if ( count( $items ) >= 60 ) continue;
            $price = $this->vendor_price( $p, $vendor['key'] );
            $ref   = $this->vendor_ref( $p, $vendor['key'] );
            $items[] = [
                'id'        => (int) $pid,
                'name'      => self::name( $name ),
                'sku'       => $sku,
                'image'     => $p->get_image_id() ? (string) wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : '',
                'price'     => $price,
                'ref'       => $ref,
                'ref_label' => $vendor['key'] === 'amazon' ? 'ASIN' : ( $vendor['key'] === 'gt' ? 'Part ID' : '' ),
                'available' => $price !== null && ( $vendor['key'] === '' || $ref !== '' ),
            ];
        }
        return rest_ensure_response( [ 'items' => $items, 'total' => $total ] );
    }

    /**
     * GET /new-order/policy?store=ID&amount=&ids=1,2 — the store's budget, order limit and window
     * with this cart included. Ink stays outside the budget, as it does for stores.
     */
    public function policy( WP_REST_Request $req ) {
        $store_id = (int) $req->get_param( 'store' );
        if ( get_post_type( $store_id ) !== WCSS_Store_CPT::POST_TYPE ) {
            return new WP_Error( 'bad_store', 'Choose a store.', [ 'status' => 400 ] );
        }
        $ids = array_filter( array_map( 'intval', explode( ',', (string) $req->get_param( 'ids' ) ) ) );
        $ink = (bool) $ids;
        foreach ( $ids as $id ) {
            $ink = $ink && WCSS_Ink_Order::product_is_ink( $id );
        }
        $amount = $ink ? 0.0 : max( 0.0, (float) $req->get_param( 'amount' ) );
        $res    = WCSS_Order_Policy::evaluate( $store_id, $amount );
        $c      = $res['checks'];
        return rest_ensure_response( [
            'in_policy'    => $res['in_policy'],
            'messages'     => $res['messages'],
            'reasons'      => $res['reasons'],
            'ink'          => $ink,
            'budget'       => (float) $c['budget'],
            'used_amount'  => (float) $c['used_amount'],
            'order_amount' => (float) $c['order_amount'],
            'quota'        => (int) $c['quota'],
            'used_orders'  => (int) $c['used_orders'],
            'window_label' => (string) $c['window_label'],
        ] );
    }

    private function store_user( int $store_id ): int {
        $ids = get_users( [ 'meta_key' => '_wcss_store_id', 'meta_value' => (string) $store_id, 'role' => 'store_employee', 'number' => 1, 'fields' => 'ID' ] );
        return $ids ? (int) $ids[0] : 0;
    }

    /** POST /new-order/checkout { store_id, vendor_id, items:[{product_id, qty}] } */
    public function checkout( WP_REST_Request $req ) {
        $data     = $req->get_json_params() ?: [];
        $store_id = (int) ( $data['store_id'] ?? 0 );
        $vendor   = $this->vendor_or_error( (int) ( $data['vendor_id'] ?? 0 ) );
        if ( is_wp_error( $vendor ) ) return $vendor;

        if ( get_post_type( $store_id ) !== WCSS_Store_CPT::POST_TYPE ) {
            return new WP_Error( 'bad_store', 'Choose a store.', [ 'status' => 400 ] );
        }
        if ( ! WCSS_Checkout_Store_Lock::store_address_complete( $store_id ) ) {
            return new WP_Error( 'store_address', 'This store has an incomplete address. Complete it in Stores first.', [ 'status' => 400 ] );
        }

        $lines = [];
        foreach ( (array) ( $data['items'] ?? [] ) as $row ) {
            $pid = (int) ( $row['product_id'] ?? 0 );
            $qty = max( 1, min( 999, (int) ( $row['qty'] ?? 1 ) ) );
            $p   = wc_get_product( $pid );
            if ( ! $p || $p->get_status() !== 'publish' || ! in_array( $vendor['id'], WCSS_Vendor_Status::assigned_ids( $pid ), true ) ) {
                return new WP_Error( 'bad_item', 'One of the products is no longer supplied by ' . $vendor['name'] . '.', [ 'status' => 400 ] );
            }
            $price = $this->vendor_price( $p, $vendor['key'] );
            $ref   = $this->vendor_ref( $p, $vendor['key'] );
            if ( $price === null || ( $vendor['key'] !== '' && $ref === '' ) ) {
                return new WP_Error( 'bad_item', $p->get_name() . ' has no price from ' . $vendor['name'] . '.', [ 'status' => 400 ] );
            }
            $lines[] = [ 'product' => $p, 'qty' => $qty, 'price' => $price, 'ref' => $ref ];
        }
        if ( ! $lines ) {
            return new WP_Error( 'empty', 'Add at least one product.', [ 'status' => 400 ] );
        }

        // Fill the vendor cart first, so a failure leaves no order behind.
        $prepared = $this->prepare_vendor_cart( $vendor, $lines );
        if ( is_wp_error( $prepared ) ) return $prepared;

        $order = $this->create_order( $store_id, $vendor, $lines );
        if ( is_wp_error( $order ) ) return $order;

        return rest_ensure_response( [
            'id'       => $order->get_id(),
            'redirect' => $this->next_url( $vendor['key'], $order->get_id() ),
        ] );
    }

    private function prepare_vendor_cart( array $vendor, array $lines ) {
        if ( $vendor['key'] === 'amazon' ) {
            if ( ! function_exists( 'cc_auto_vendor_submit_amazon_fill_cart' ) ) {
                return new WP_Error( 'vendor_unavailable', 'Amazon ordering is not available.', [ 'status' => 500 ] );
            }
            $send = array_map( static function ( $l ) {
                return [ 'vendor_id' => $l['ref'], 'name' => $l['product']->get_name(), 'qty' => $l['qty'], 'unit_price' => $l['price'] ];
            }, $lines );
            cc_auto_vendor_submit_amazon_lock();
            try {
                $res = cc_auto_vendor_submit_amazon_fill_cart( $send );
            } finally {
                cc_auto_vendor_submit_amazon_unlock();
            }
            if ( ! empty( $res['errors'] ) || empty( $res['added'] ) ) {
                $why = ! empty( $res['errors'] ) ? implode( ' ', (array) $res['errors'] ) : (string) ( $res['message'] ?? '' );
                return new WP_Error( 'vendor_cart', 'Couldn’t add to the Amazon cart. ' . $why, [ 'status' => 502 ] );
            }
        } elseif ( $vendor['key'] === 'gt' ) {
            if ( ! function_exists( 'cc_gt_cart_add_line' ) ) {
                return new WP_Error( 'vendor_unavailable', 'Grand & Toy ordering is not available.', [ 'status' => 500 ] );
            }
            cc_gt_clear_cart();
            foreach ( $lines as $l ) {
                $added = cc_gt_cart_add_line( [
                    'product_id' => $l['product']->get_id(),
                    'part_id'    => $l['ref'],
                    'name'       => $l['product']->get_name(),
                    'sku'        => $l['product']->get_sku(),
                    'qty'        => $l['qty'],
                    'unit_price' => $l['price'],
                ] );
                if ( is_wp_error( $added ) ) return $added;
            }
        }
        return true;
    }

    private function create_order( int $store_id, array $vendor, array $lines ) {
        $user  = $this->store_user( $store_id );
        $order = wc_create_order( [ 'customer_id' => $user ] );
        if ( is_wp_error( $order ) ) return $order;

        $name  = self::name( get_the_title( $store_id ) );
        $phone = (string) get_post_meta( $store_id, WCSS_Store_CPT::META_PHONE, true );
        $addr  = [
            'first_name' => $name,
            'last_name'  => '',
            'company'    => $name,
            'address_1'  => (string) get_post_meta( $store_id, WCSS_Store_CPT::META_ADDR, true ),
            'address_2'  => '',
            'city'       => (string) get_post_meta( $store_id, WCSS_Store_CPT::META_CITY, true ),
            'state'      => WCSS_Checkout_Store_Lock::normalize_province( (string) get_post_meta( $store_id, WCSS_Store_CPT::META_STATE, true ) ),
            'postcode'   => (string) get_post_meta( $store_id, WCSS_Store_CPT::META_POSTCODE, true ),
            'country'    => 'CA',
        ];
        $owner = $user ? get_userdata( $user ) : null;
        $order->set_address( $addr + [ 'phone' => $phone, 'email' => $owner ? $owner->user_email : wp_get_current_user()->user_email ], 'billing' );
        $order->set_address( $addr + [ 'phone' => $phone ], 'shipping' );
        $order->set_payment_method( 'wcss_internal_requisition' );
        $order->set_payment_method_title( __( 'Internal Requisition', 'wcss' ) );

        $all_ink = true;
        foreach ( $lines as $l ) {
            $total   = wc_format_decimal( $l['price'] * $l['qty'], 2 );
            $item_id = $order->add_product( $l['product'], $l['qty'], [ 'subtotal' => $total, 'total' => $total ] );
            $all_ink = $all_ink && WCSS_Ink_Order::product_is_ink( $l['product']->get_id() );
            if ( $vendor['key'] !== '' && $item_id ) {
                $item = $order->get_item( $item_id );
                $item->update_meta_data( '_cc_vendor', $vendor['key'] );
                $item->update_meta_data( '_cc_vendor_id', $l['ref'] );
                $item->update_meta_data( '_cc_vendor_price', $l['price'] );
                $item->save();
            }
        }
        $order->calculate_totals( false );

        $me = get_current_user_id();
        $order->update_meta_data( self::META, [ 'by' => $me, 'at' => current_time( 'mysql' ), 'vendor' => $vendor['name'] ] );
        $order->update_meta_data( '_wcss_store_id', $store_id );
        $order->update_meta_data( WCSS_Ink_Order::META, $all_ink ? WCSS_Ink_Order::TYPE_INK : WCSS_Ink_Order::TYPE_STATION );
        // Kept on the order so an out-of-policy order shows up in the Exceptions report.
        $order->update_meta_data( WCSS_Order_Policy::META, WCSS_Order_Policy::evaluate_order( $order ) );
        // Placing it here is the approval, so the store's ordering policy does not hold it.
        $order->update_meta_data( '_wcss_policy_override', [ 'by' => $me, 'at' => current_time( 'mysql' ), 'source' => 'manager_order' ] );

        // Supply Ops finishes this by hand, so the automatic vendor send must leave it alone.
        $manual  = $vendor['key'] === '';
        $names   = array_map( static function ( $l ) { return $l['product']->get_name(); }, $lines );
        $note    = 'Place with ' . $vendor['name'] . ': ' . implode( ', ', $names ) . '.';
        $order->update_meta_data( defined( 'WCSS_AUTO_VENDOR_SUBMIT_META' ) ? WCSS_AUTO_VENDOR_SUBMIT_META : '_wcss_auto_vendor_submit', [
            'attempted'      => true,
            'status'         => $manual ? 'manual' : 'checkout',
            'winning_vendor' => $vendor['key'],
            'vendors'        => [],
            'message'        => $manual ? $note : 'Waiting for the ' . $vendor['name'] . ' checkout to be finished.',
            'manual_note'    => $manual ? $note : '',
            'results'        => [],
        ] );
        $order->save();

        $by = wp_get_current_user()->display_name;
        $order->update_status( 'approved', sprintf( 'Placed by %1$s for %2$s (%3$s).', $by, $name, $vendor['name'] ), true );
        if ( class_exists( 'WCSS_Order_Events' ) ) {
            WCSS_Order_Events::log( $order->get_id(), 'manager_order', [ 'vendor' => $vendor['name'], 'store' => $name ] );
        }
        return wc_get_order( $order->get_id() );
    }

    private function next_url( string $key, int $order_id ): string {
        if ( $key === 'amazon' ) return add_query_arg( 'order-id', $order_id, home_url( '/amazon-checkout/' ) );
        if ( $key === 'gt' ) return add_query_arg( 'order-id', $order_id, home_url( '/gt-checkout/' ) );
        return home_url( '/manager/orders/view/' . $order_id );
    }
}
