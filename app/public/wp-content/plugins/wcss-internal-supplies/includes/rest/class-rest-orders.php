<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_REST_Orders {
    const NS = 'wcss/v1';
    public function __construct() { add_action( 'rest_api_init', [ $this, 'routes' ] ); }
    public function can_manage( $request = null ): bool {
        return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
    }

    public function routes() {


        register_rest_route( self::NS, '/orders', [
            'methods'             => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'orders_list' ],
            'args'                => [
                'status' => [
                    // optional: sanitize to a comma list of sanitized keys
                    'sanitize_callback' => function( $param, $request, $key ) {
                        $parts = array_filter( array_map( 'trim', explode( ',', (string) $param ) ) );
                        $parts = array_map( 'sanitize_key', $parts );
                        return implode( ',', $parts );
                    },
                ],
                'date_from' => [
                    'validate_callback' => function( $param, $request, $key ) {
                        return empty( $param ) || (bool) strtotime( (string) $param );
                    },
                ],
                'date_to' => [
                    'validate_callback' => function( $param, $request, $key ) {
                        return empty( $param ) || (bool) strtotime( (string) $param );
                    },
                ],
                'page' => [
                    'validate_callback' => function( $param, $request, $key ) {
                        return is_numeric( $param ) && (int) $param >= 1;
                    },
                ],
                'per_page' => [
                    'validate_callback' => function( $param, $request, $key ) {
                        return is_numeric( $param ) && (int) $param >= 1 && (int) $param <= 100;
                    },
                ],
            ],
        ] );


        register_rest_route( self::NS, '/orders/(?P<id>\d+)/shipment', [
            'methods'             => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'orders_update_shipment' ],
            'args'                => [
                'tracking' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
                'carrier'  => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ],
                'edd'      => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ], // YYYY-MM-DD
            ],
        ]);

        register_rest_route( self::NS, '/orders/(?P<id>\d+)/receiving', [
            'methods'             => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'orders_update_receiving' ],
            'args'                => [
                'received'       => [ 'required' => true ],
                'received_date'  => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ], // YYYY-MM-DD
                'notes'          => [ 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ],
                'issues'         => [ 'required' => false ], // array: [{sku, type: "damage|shortage", qty, note}]
            ],
        ]);



        register_rest_route( self::NS, '/orders/(?P<id>\d+)', [
            'methods'=>'GET', 'permission_callback'=>[ $this,'can_manage' ],
            'callback'=>[ $this,'orders_read' ],
        ]);

        register_rest_route( self::NS, '/orders/(?P<id>\d+)/amazon', [
            'methods'             => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'orders_read_amazon' ],
        ]);

        register_rest_route( self::NS, '/orders/(?P<id>\d+)/gt', [
            'methods'             => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'orders_read_gt' ],
        ]);

        register_rest_route( self::NS, '/orders/(?P<id>\d+)/status', [
            'methods'=>'POST', 'permission_callback'=>[ $this,'can_manage' ],
            'callback'=>[ $this,'orders_update_status' ],
            'args'=>['status'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
                     'note'=>['required'=>false,'sanitize_callback'=>'sanitize_textarea_field']],
        ]);

        // Retry Amazon / G&T auto-place after a failed approve submit.
        register_rest_route( self::NS, '/orders/(?P<id>\d+)/retry-vendor-submit', [
            'methods'             => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'orders_retry_vendor_submit' ],
        ] );

        register_rest_route( self::NS, '/orders/(?P<id>\d+)/note', [
            'methods'=>'POST', 'permission_callback'=>[ $this,'can_manage' ],
            'callback'=>[ $this,'orders_add_note' ],
            'args'=>['note'=>['required'=>true,'sanitize_callback'=>'sanitize_textarea_field']],
        ]);

        // Safe edit: limited fields only (no line items here)
        register_rest_route( self::NS, '/orders/(?P<id>\d+)', [
            'methods'=>'PATCH', 'permission_callback'=>[ $this,'can_manage' ],
            'callback'=>[ $this,'orders_patch' ],
        ]);

        // Permanent delete (cleanup). Body must repeat the order ID: { "confirm": <id> }.
        register_rest_route( self::NS, '/orders/(?P<id>\d+)', [
            'methods'=>'DELETE', 'permission_callback'=>[ $this,'can_manage' ],
            'callback'=>[ $this,'orders_delete' ],
        ]);

        // registring route for debugging... 
        
        register_rest_route( self::NS, '/whoami', [
            'methods'  => 'GET',
            'permission_callback' => '__return_true', // public for debugging only
            'callback' => function () {
                $u = wp_get_current_user();
                return [
                    'logged_in' => is_user_logged_in(),
                    'user_id'   => $u ? $u->ID : 0,
                    'caps'      => array_keys( $u ? (array) $u->allcaps : [] ),
                    'has_wcss'  => current_user_can( 'wcss_manage_portal' ),
                ];
            },
          ]);


    }

    /* ------------ Handlers ------------ */

    public function orders_list( WP_REST_Request $req ) {
        $page = max(1, (int) $req->get_param('page') ?: 1);
        $per  = min(100, max(1, (int) $req->get_param('per_page') ?: 25));


        $args = [
            // wc_get_orders ignores post_type/post_status; keep args minimal
            'limit'    => $per,
            'page'     => $page,
            'return'   => 'objects',
            'orderby'  => 'date',
            'order'    => 'DESC',
            'paginate' => true, // real total / total_pages for the pager
        ];


            // ---- Status filter: accept "approved" OR "wc-approved" ----
        if ( $s = $req->get_param('status') ) {
            $requested = array_filter( array_map( 'trim', explode( ',', (string) $s ) ) );

            if ( $requested ) {
                // Valid slugs WITHOUT the "wc-" prefix
                $valid_map = array_keys( wc_get_order_statuses() );             // ['wc-pending','wc-processing',...]
                $valid     = array_map( function( $k ) { return substr( $k, 3 ); }, $valid_map ); // ['pending','processing',...]

                // Normalize incoming → strip optional "wc-" prefix
                $want = [];
                foreach ( $requested as $x ) {
                    $x = strtolower( trim( (string) $x ) );
                    if ( strpos( $x, 'wc-' ) === 0 ) { $x = substr( $x, 3 ); }  // turn 'wc-approved' → 'approved'
                    if ( in_array( $x, $valid, true ) ) { $want[] = $x; }
                }

                if ( ! empty( $want ) ) {
                    $args['status'] = array_values( array_unique( $want ) );    // e.g. ['approved','rejected']
                }
                // If nothing valid is requested, don't force a fallback; show all instead.
            }
        }


        // (date filter unchanged)
        $from = $req->get_param('date_from'); $to = $req->get_param('date_to');
        if ( $from || $to ) {
            $from = $from ? $from.' 00:00:00' : '1970-01-01 00:00:00';
            $to   = $to   ? $to  .' 23:59:59' : current_time('mysql');
            $args['date_created'] = $from . '...' . $to;
        }

        $log  = wc_get_logger();

        try {
            $res    = wc_get_orders( $args );
            $orders = is_object( $res ) ? $res->orders : (array) $res;
            $total  = is_object( $res ) ? (int) $res->total : count( $orders );
        } catch ( Throwable $e ) {
            $log->critical( 'WCSS orders_list query failed', [
                'source' => 'wcss',
                'error'  => $e->getMessage(),
                'args'   => $args,
                'trace'  => $e->getTraceAsString(),
            ]);
            return new WP_Error( 'wcss_orders_query_failed', 'Orders query failed.', [ 'status' => 500 ] );
        }

        try {
            $items = array_map( [ $this, 'dto_order' ], $orders );
            // return rest_ensure_response( [ 'items' => $items ] );

            return rest_ensure_response([
                'items'       => $items,
                'page'        => $page,
                'per_page'    => $per,
                'total'       => $total,
                'total_pages' => (int) ceil( $total / $per ),
            ]);


        } catch ( Throwable $e ) {
            $log->critical( 'WCSS orders_list map failed', [
                'source' => 'wcss',
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);
            return new WP_Error( 'wcss_orders_map_failed', 'Orders mapping failed.', [ 'status' => 500 ] );
        }

    }


    public function orders_update_status( WP_REST_Request $req ) {
        $o = wc_get_order( (int) $req['id'] );
        if ( ! $o ) return new WP_Error('not_found','Order not found',[ 'status'=>404 ]);
    
        $status  = sanitize_text_field( $req['status'] );
        $note    = sanitize_textarea_field( (string) $req->get_param('note') );
        $override= (bool) $req->get_param('override');
    
        $allowed = [ 'pending','awaiting-approval','approved','rejected','processing','completed','cancelled','on-hold' ];
        if ( ! in_array( $status, $allowed, true ) ) {
            return new WP_Error('bad_status','Status not allowed',[ 'status'=>400 ]);
        }
    
        // If moving to "approved", check the store policy (window / quota / budget) unless override=1.
        if ( $status === 'approved' && class_exists( 'WCSS_Order_Policy' ) ) {
            $store_id = (int) $o->get_meta('_wcss_store_id');
            $policy   = WCSS_Order_Policy::evaluate_order( $o );
            if ( $store_id && ! $policy['in_policy'] && ! $override ) {
                $c = $policy['checks'];
                // 409 with flat payload (WP REST puts these under response.data)
                return new WP_Error(
                    'wcss_limit_block',
                    in_array( 'ink_review', $policy['reasons'], true )
                        ? __( 'Ink orders always need Supply Ops approval. Approving sends this one to the supplier.', 'wcss' )
                        : __( 'Approving this order is outside the store’s ordering policy: ', 'wcss' ) . implode( ' ', $policy['messages'] ),
                    [
                        'status'        => 409,
                        'store_id'      => $store_id,
                        'month'         => wp_date( 'Y-m' ),
                        'reasons'       => $policy['reasons'],
                        'messages'      => $policy['messages'],
                        'quota'         => $c['quota'],
                        'quota_used'    => $c['used_orders'],
                        'will_orders'   => $c['used_orders'] + 1,
                        'budget'        => $c['budget'],
                        'budget_used'   => $c['used_amount'],
                        'order_total'   => $c['order_amount'],
                        'will_spend'    => $c['used_amount'] + $c['order_amount'],
                        'currency'      => get_woocommerce_currency(),
                    ]
                );
            }
            // A manager approval is the exception decision: lets auto-submit send it to the vendor.
            $o->update_meta_data( '_wcss_policy_override', [ 'by' => get_current_user_id(), 'at' => current_time( 'mysql' ), 'reasons' => $policy['reasons'] ] );
            $o->save();
        }

            // proceed
        $o->update_status( $status, $note ? $note : __( 'Status updated (Supply Manager)', 'wcss' ), true );
        WCSS_Order_Events::log( $o->get_id(), 'status_change', [ 'new'=>$status, 'note'=>$note, 'override'=>$override ] );
    
        return rest_ensure_response([ 'ok'=>true ]);
    }

    /**
     * Permanently delete an order and all linked records (Supply Manager cleanup).
     */
    public function orders_delete( WP_REST_Request $req ) {
        $id   = (int) $req['id'];
        $body = $req->get_json_params() ?: [];
        if ( (int) ( $body['confirm'] ?? 0 ) !== $id ) {
            return new WP_Error( 'wcss_confirm', 'Confirm the order number to delete it.', [ 'status' => 400 ] );
        }
        if ( ! class_exists( 'WCSS_Order_Delete' ) ) {
            return new WP_Error( 'unavailable', 'Order delete is not available.', [ 'status' => 503 ] );
        }
        return rest_ensure_response( WCSS_Order_Delete::purge( $id ) );
    }

    /**
     * Force re-run of auto vendor submit for an already-approved order.
     */
    public function orders_retry_vendor_submit( WP_REST_Request $req ) {
        $o = wc_get_order( (int) $req['id'] );
        if ( ! $o ) {
            return new WP_Error( 'not_found', 'Order not found', [ 'status' => 404 ] );
        }
        if ( ! in_array( $o->get_status(), [ 'approved', 'processing', 'completed' ], true ) ) {
            return new WP_Error( 'bad_status', 'Approve the order before retrying vendor submit.', [ 'status' => 400 ] );
        }
        if ( ! function_exists( 'cc_auto_vendor_submit_retry' ) ) {
            return new WP_Error( 'unavailable', 'Auto vendor submit is not available.', [ 'status' => 503 ] );
        }
        $summary = cc_auto_vendor_submit_retry( (int) $o->get_id() );
        WCSS_Order_Events::log( $o->get_id(), 'auto_vendor_submit', [
            'status'  => is_array( $summary ) ? ( $summary['status'] ?? 'queued' ) : 'queued',
            'message' => is_array( $summary ) ? (string) ( $summary['message'] ?? '' ) : 'Queued',
            'retry'   => true,
        ] );
        return rest_ensure_response( [
            'ok'      => true,
            'summary' => is_array( $summary ) ? $summary : [ 'status' => 'queued', 'message' => 'Vendor submit queued.' ],
        ] );
    }


    public function orders_add_note( WP_REST_Request $req ) {
        $o = wc_get_order( (int) $req['id'] );
        if ( ! $o ) return new WP_Error('not_found','Order not found',[ 'status'=>404 ]);
        $note = sanitize_textarea_field( $req['note'] );
        $o->add_order_note( $note, false, true );
        WCSS_Order_Events::log( $o->get_id(), 'note', [ 'note'=>$note ] );
        return rest_ensure_response([ 'ok'=>true ]);
    }

    // limited PATCH; allow only safe fields (addresses + meta we define)
    public function orders_patch( WP_REST_Request $req ) {
        $o = wc_get_order( (int) $req['id'] );
        if ( ! $o ) return new WP_Error('not_found','Order not found',[ 'status'=>404 ]);

        $data = $req->get_json_params() ?: [];
        $changed = false;

        if ( ! empty( $data['billing'] ) && is_array( $data['billing'] ) ) {
            $addr = $this->sanitize_addr( $data['billing'] );
            $o->set_address( $addr, 'billing' );
            $changed = true;
        }
        if ( ! empty( $data['shipping'] ) && is_array( $data['shipping'] ) ) {
            $addr = $this->sanitize_addr( $data['shipping'] );
            $o->set_address( $addr, 'shipping' );
            $changed = true;
        }
        if ( isset( $data['expected_delivery_date'] ) ) {
            $edd = sanitize_text_field( $data['expected_delivery_date'] );
            $o->update_meta_data( '_wcss_expected_delivery_date', $edd );
            $changed = true;
        }
        if ( isset( $data['internal_ref'] ) ) {
            $ref = sanitize_text_field( $data['internal_ref'] );
            $o->update_meta_data( '_wcss_internal_ref', $ref );
            $changed = true;
        }

        if ( $changed ) {
            $o->save();
            WCSS_Order_Events::log( $o->get_id(), 'edit', [ 'fields'=> array_keys( $data ) ] );
        }
        return rest_ensure_response([ 'ok'=>true ]);
    }

    /* ------------ DTO & helpers ------------ */

    /** Who placed an order from the Supply Manager portal; '' for a store's own order. */
    private function placed_by( WC_Order $o ): string {
        $meta = $o->get_meta( '_wcss_manager_order' );
        $user = is_array( $meta ) && ! empty( $meta['by'] ) ? get_userdata( (int) $meta['by'] ) : null;
        return $user ? (string) $user->display_name : ( $meta ? 'Supply Ops' : '' );
    }

    private function dto_order( WC_Order $o ): array {

            // $store_id = (int) $o->get_meta( '_wcss_store_id' );
            // $store_id = $store_id ?: 0;
        
            $sid   = (int) $o->get_meta('_wcss_store_id');
            $store = [
                'id'    => $sid ?: null,
                'name'  => $sid ? ( get_the_title($sid) ?: '' ) : '',
                'code'  => (string) WCSS_Store_CPT::get_field( $sid, 'code' ),
                'city'  => (string) WCSS_Store_CPT::get_field( $sid, 'city' ),
                'state' => (string) WCSS_Store_CPT::get_field( $sid, 'state' ),
            ];


            // Robust store name resolution
            // $store_name = '';
            // if ( $store_id ) {
            //     $p = get_post( $store_id );
            //     if ( $p && ! is_wp_error( $p ) ) {
            //         $store_name = $p->post_title ?: '';
            //     }
            //     if ( $store_name === '' ) {
            //         // fall back to a useful code/meta so UI isn’t blank
            //         $code = get_post_meta( $store_id, '_store_code', true );
            //         if ( $code ) {
            //             $store_name = $code;
            //         }
            //     }
            // }

                        // NEW: shipment meta
            $shipment = WCSS_Shipment::get( $o ) + [ 'editable' => WCSS_Shipment::editable( $o ) ];

        $vendor_summary = $this->dto_order_vendor_summary( $o );

        return [
            'id'         => $o->get_id(),
            'number'     => $o->get_order_number(),
            'date'       => $o->get_date_created() ? $o->get_date_created()->date_i18n('Y-m-d H:i') : '',
            'status'     => wc_get_order_status_name( $o->get_status() ),
            'status_slug'=> $o->get_status(),
            'total'      => $o->get_total(),                  // raw numeric; JS can format
            'total_html' => $o->get_formatted_order_total(),  // pretty
            'customer'   => $o->get_billing_company() ?: $o->get_billing_email(),
            'vendor'     => $vendor_summary['vendor'],
            'vendor_label' => $vendor_summary['vendor_label'],
            'vendors'    => $vendor_summary['vendors'],
            'edd'        => $shipment['edd_label'],
            'ref'        => $o->get_meta('_wcss_internal_ref'),
            'store'      => $store,
            'shipment' => $shipment,
            'placed_by'  => $this->placed_by( $o ),
            'has_amazon_order' => (bool) $o->get_meta( '_wcss_amazon_order_data' ),
            'has_gt_order'     => (bool) $o->get_meta( '_wcss_gt_order_data' ),
            'auto_submit'      => $this->dto_auto_submit_summary( $o ),
            'policy'           => $this->dto_policy( $o ),
            'order_type'       => ( class_exists( 'WCSS_Ink_Order' ) && WCSS_Ink_Order::order_is_ink( $o ) ) ? 'ink' : 'stationery',
        ];

    }

    /**
     * Compact auto-submit status for list + detail.
     *
     * @param WC_Order $o Order.
     * @return array<string,mixed>|null
     */
    /** Ordering-policy verdict recorded at checkout (WCSS_Order_Policy). */
    private function dto_policy( $o ) {
        $p = $o->get_meta( '_wcss_policy', true );
        if ( ! is_array( $p ) || ! isset( $p['in_policy'] ) ) {
            return null;
        }
        return [
            'in_policy'  => (bool) $p['in_policy'],
            'reasons'    => array_values( (array) ( $p['reasons'] ?? [] ) ),
            'messages'   => array_values( (array) ( $p['messages'] ?? [] ) ),
            'overridden' => (bool) $o->get_meta( '_wcss_policy_override', true ),
        ];
    }

    private function dto_auto_submit_summary( $o ) {
        $raw = $o->get_meta( '_wcss_auto_vendor_submit', true );
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return null;
        }
        return [
            'status'         => (string) ( $raw['status'] ?? '' ),
            'winning_vendor' => (string) ( $raw['winning_vendor'] ?? '' ),
            'vendors'        => array_values( (array) ( $raw['vendors'] ?? [] ) ),
            'message'        => (string) ( $raw['message'] ?? '' ),
            'attempted'      => ! empty( $raw['attempted'] ),
            'completed_at'   => (string) ( $raw['completed_at'] ?? $raw['updated_at'] ?? '' ),
        ];
    }

    /**
     * Summarize cart vendor selections stored on order line items (_cc_vendor).
     *
     * @param WC_Order $o Order.
     * @return array{vendor:string,vendor_label:string,vendors:array<int,string>}
     */
    private function dto_order_vendor_summary( $o ) {
        $keys   = array();
        $manual = array();

        foreach ( $o->get_items() as $item ) {
            $vendor = sanitize_key( (string) $item->get_meta( '_cc_vendor', true ) );
            if ( $vendor === '' ) {
                $name = $this->manual_vendor_of_line( $o, $item );
                if ( $name !== '' ) {
                    $manual[ $name ] = true;
                }
                continue;
            }
            $keys[ $vendor ] = true;
        }
        $manual = array_keys( $manual );

        $vendors = array_keys( $keys );
        sort( $vendors );

        if ( ! $vendors && ! $manual ) {
            return array(
                'vendor'       => '',
                'vendor_label' => '—',
                'vendors'      => array(),
            );
        }

        $labels = array();
        foreach ( $vendors as $vendor ) {
            if ( $vendor === 'amazon' ) {
                $labels[] = 'Amazon';
            } elseif ( $vendor === 'gt' ) {
                $labels[] = 'Grand & Toy';
            } else {
                $labels[] = $vendor;
            }
        }

        $labels = array_merge( $labels, $manual );
        if ( $manual ) {
            $vendor = $vendors ? 'mixed' : 'manual';
        } else {
            $vendor = count( $vendors ) === 1 ? $vendors[0] : 'mixed';
        }

        return array(
            'vendor'       => $vendor,
            'vendor_label' => implode( ' + ', $labels ),
            'vendors'      => $vendors,
        );
    }

    /**
     * Name of the manual vendor a line is placed with by hand, or ''.
     * Manual lines carry no _cc_vendor tag; only orders marked for hand placing count,
     * so an order that was never sent doesn't look as if it has a vendor.
     */
    private function manual_vendor_of_line( $o, $item ): string {
        $auto = $o->get_meta( '_wcss_auto_vendor_submit' );
        if ( ! is_array( $auto ) || ( ( $auto['status'] ?? '' ) !== 'manual' && empty( $auto['manual_note'] ) ) ) {
            return '';
        }
        if ( ! function_exists( 'cc_vendor_prices_manual_vendors' ) ) {
            return '';
        }
        $pid = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
        return implode( ' + ', cc_vendor_prices_manual_vendors( $pid ) );
    }


    public function orders_read( WP_REST_Request $req ) {
        $o = wc_get_order( (int) $req['id'] );
        if ( ! $o ) return new WP_Error('not_found','Order not found',[ 'status'=>404 ]);

        $dto = $this->dto_order( $o );

        // Addresses + store-owner note (already on the order; surface for manager UI)
        $dto['billing']       = $this->dto_addr( $o->get_address( 'billing' ) );
        $dto['shipping']      = $this->dto_addr( $o->get_address( 'shipping' ) );
        $dto['customer_note'] = (string) $o->get_customer_note();
        $dto['billing_email'] = (string) $o->get_billing_email();
        $dto['billing_phone'] = (string) $o->get_billing_phone();

        // Who placed the order (store employee) — for Supply Manager order detail.
        $dto['requester'] = $this->dto_requester( $o );
        $dto['carriers']  = array_map( static function ( $c ) { return $c[0]; }, WCSS_Shipment::CARRIERS );
        $dto['returns']   = class_exists( 'WCSS_Returns' ) ? WCSS_Returns::for_order( $o->get_id() ) : [];

        // Items (include SKU, product/variation IDs, cart vendor selection)
        $dto['items'] = [];
        foreach ( $o->get_items() as $item_id => $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) continue;

            $product   = $item->get_product();              // may be null if deleted
            $name      = $item->get_name();
            $qty       = (int) $item->get_quantity();
            $line_total= (float) $item->get_total();
            $sku       = $product instanceof WC_Product ? $product->get_sku() : '';
            $pid       = $product instanceof WC_Product ? $product->get_id() : 0;
            $vid       = $product instanceof WC_Product_Variation ? $product->get_id() : 0;

            // Variation attributes (optional, handy in UI)
            $attrs = [];
            if ( $product instanceof WC_Product_Variation ) {
                $attrs = $product->get_attributes();
            } elseif ( method_exists( $item, 'get_variation_attributes' ) ) {
                $attrs = $item->get_variation_attributes();
            }

            $vendor_key   = (string) $item->get_meta( '_cc_vendor', true );
            $vendor_id    = (string) $item->get_meta( '_cc_vendor_id', true );
            $vendor_price = $item->get_meta( '_cc_vendor_price', true );
            $vendor_label = '';
            if ( $vendor_key === 'amazon' ) {
                $vendor_label = 'Amazon';
            } elseif ( $vendor_key === 'gt' ) {
                $vendor_label = 'Grand & Toy';
            } elseif ( $vendor_key !== '' ) {
                $vendor_label = $vendor_key;
            } else {
                $vendor_label = $this->manual_vendor_of_line( $o, $item );
            }

            $dto['items'][] = [
                'item_id'      => $item_id,
                'product_id'   => $pid ?: $item->get_product_id(),
                'variation_id' => $vid ?: $item->get_variation_id(),
                'name'         => $name,
                'sku'          => $sku,
                'qty'          => $qty,
                'subtotal'     => wc_price( (float) $item->get_subtotal() ),
                'total'        => wc_price( $line_total ),
                'attributes'   => $attrs,
                'vendor'       => $vendor_key,
                'vendor_label' => $vendor_label,
                'vendor_id'    => $vendor_id,
                'vendor_unit_price' => ( $vendor_price !== '' && $vendor_price !== null )
                    ? (float) $vendor_price
                    : null,
                'vendor_unit_price_html' => ( $vendor_price !== '' && $vendor_price !== null )
                    ? wc_price( (float) $vendor_price )
                    : '',
                // Thumbnail for the order page (Woo placeholder when the product has no image or was deleted).
                'image'        => ( $product instanceof WC_Product && $product->get_image_id() )
                    ? (string) wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' )
                    : ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'woocommerce_thumbnail' ) : '' ),
                'unit_price'   => $qty ? round( $line_total / $qty, 2 ) : 0,
                'line_total'   => round( $line_total, 2 ),
            ];
        }

        // Timeline/events if you’re using it
        if ( class_exists( 'WCSS_Order_Events' ) ) {
            $dto['timeline'] = WCSS_Order_Events::for_order( $o->get_id(), 50 );
        }

        // Attach ledger only if store set
        if ( ! empty( $dto['store']['id'] ) && class_exists( 'WCSS_Ledger' ) ) {
            $ym  = wp_date('Y-m');
            $row = WCSS_Ledger::get( (int) $dto['store']['id'], $ym ) ?: [ 'orders_count'=>0, 'spend_total'=>0 ];
            // Count the month the same way the ordering rules do (approved + waiting for approval,
            // ink excluded), so this card matches what the store sees in its cart.
            if ( class_exists( 'WCSS_Order_Policy' ) ) {
                [ $used_n, $used_amt ] = WCSS_Order_Policy::month_usage( (int) $dto['store']['id'] );
                $row = [ 'orders_count' => (int) $used_n, 'spend_total' => round( (float) $used_amt, 2 ) ];
            }
            $quota  = (int) WCSS_Store_CPT::get_field( (int) $dto['store']['id'], 'quota' );
            $budget = (float) WCSS_Store_CPT::get_field( (int) $dto['store']['id'], 'budget' );

            $dto['ledger'] = [
                'month'            => $ym,
                'used_orders'      => (int) ($row['orders_count'] ?? 0),
                'used_amount'      => (float) ($row['spend_total'] ?? 0),
                'quota'            => $quota,
                'budget'           => $budget,
                'remaining_orders' => max(0, $quota - (int) ($row['orders_count'] ?? 0)),
                'remaining_amount' => max(0.0, $budget - (float) ($row['spend_total'] ?? 0)),
                'currency'         => get_woocommerce_currency(),
            ];
        }

        // Totals breakdown (subtotal, discounts, shipping, fees, tax, grand total).
        $currency = $o->get_currency() ?: get_woocommerce_currency();
        $dto['totals'] = [
            'currency'           => $currency,
            'subtotal'           => (float) $o->get_subtotal(),
            'subtotal_html'      => wc_price( (float) $o->get_subtotal(), [ 'currency' => $currency ] ),
            'discount_total'     => (float) $o->get_discount_total(),
            'discount_total_html'=> wc_price( (float) $o->get_discount_total(), [ 'currency' => $currency ] ),
            'shipping_total'     => (float) $o->get_shipping_total(),
            'shipping_total_html'=> wc_price( (float) $o->get_shipping_total(), [ 'currency' => $currency ] ),
            'shipping_tax'       => (float) $o->get_shipping_tax(),
            'shipping_tax_html'  => wc_price( (float) $o->get_shipping_tax(), [ 'currency' => $currency ] ),
            'cart_tax'           => (float) $o->get_cart_tax(),
            'cart_tax_html'      => wc_price( (float) $o->get_cart_tax(), [ 'currency' => $currency ] ),
            'total_tax'          => (float) $o->get_total_tax(),
            'total_tax_html'     => wc_price( (float) $o->get_total_tax(), [ 'currency' => $currency ] ),
            'fee_total'          => (float) $o->get_total_fees(),
            'fee_total_html'     => wc_price( (float) $o->get_total_fees(), [ 'currency' => $currency ] ),
            'grand_total'        => (float) $o->get_total(),
            'grand_total_html'   => $o->get_formatted_order_total(),
            'item_count'         => (int) $o->get_item_count(),
            'payment_method'     => (string) $o->get_payment_method_title(),
            'tax_lines'          => [],
            'fee_lines'          => [],
            'shipping_lines'     => [],
        ];

        foreach ( $o->get_items( 'tax' ) as $tax_item ) {
            if ( ! $tax_item instanceof WC_Order_Item_Tax ) {
                continue;
            }
            $dto['totals']['tax_lines'][] = [
                'label'            => (string) $tax_item->get_label(),
                'rate_percent'     => (string) $tax_item->get_rate_percent(),
                'tax_total'        => (float) $tax_item->get_tax_total(),
                'shipping_tax_total' => (float) $tax_item->get_shipping_tax_total(),
                'tax_total_html'   => wc_price( (float) $tax_item->get_tax_total(), [ 'currency' => $currency ] ),
            ];
        }
        foreach ( $o->get_items( 'fee' ) as $fee_item ) {
            if ( ! $fee_item instanceof WC_Order_Item_Fee ) {
                continue;
            }
            $dto['totals']['fee_lines'][] = [
                'name'       => (string) $fee_item->get_name(),
                'total'      => (float) $fee_item->get_total(),
                'total_tax'  => (float) $fee_item->get_total_tax(),
                'total_html' => wc_price( (float) $fee_item->get_total(), [ 'currency' => $currency ] ),
            ];
        }
        foreach ( $o->get_items( 'shipping' ) as $ship_item ) {
            if ( ! $ship_item instanceof WC_Order_Item_Shipping ) {
                continue;
            }
            $dto['totals']['shipping_lines'][] = [
                'method'     => (string) $ship_item->get_name(),
                'total'      => (float) $ship_item->get_total(),
                'total_tax'  => (float) $ship_item->get_total_tax(),
                'total_html' => wc_price( (float) $ship_item->get_total(), [ 'currency' => $currency ] ),
            ];
        }

        // Full auto-submit + vendor order snapshots for Manager View.
        $auto_full = $o->get_meta( '_wcss_auto_vendor_submit', true );
        $dto['auto_submit_detail'] = is_array( $auto_full ) ? $auto_full : null;

        $amazon_data = $o->get_meta( '_wcss_amazon_order_data', true );
        $dto['amazon_order'] = is_array( $amazon_data ) && ! empty( $amazon_data )
            ? [
                'found'             => true,
                'status'            => (string) ( $amazon_data['status'] ?? '' ),
                'source'            => (string) ( $amazon_data['source'] ?? '' ),
                'auto'              => ! empty( $amazon_data['auto'] ),
                'trial_mode'        => ! empty( $amazon_data['trial_mode'] ),
                'placed_at'         => (string) ( $amazon_data['placed_at'] ?? '' ),
                'order_total'       => $amazon_data['order_total'] ?? null,
                'currency'          => (string) ( $amazon_data['currency'] ?? 'CAD' ),
                'cart_items'        => array_values( (array) ( $amazon_data['cart_items'] ?? [] ) ),
                'intended_lines'    => array_values( (array) ( $amazon_data['intended_lines'] ?? [] ) ),
                'shipping'          => is_array( $amazon_data['shipping'] ?? null ) ? $amazon_data['shipping'] : [],
                'amazon_order_id'   => (string) ( $amazon_data['amazon_order_id'] ?? '' ),
                'amazon_request_id' => (string) ( $amazon_data['amazon_request_id'] ?? '' ),
                'message'           => (string) ( $amazon_data['amazon_response']['message'] ?? ( $amazon_data['message'] ?? '' ) ),
                'amazon_response'   => is_array( $amazon_data['amazon_response'] ?? null ) ? $amazon_data['amazon_response'] : null,
                'raw'               => $amazon_data,
            ]
            : [ 'found' => false ];

        $gt_data = $o->get_meta( '_wcss_gt_order_data', true );
        $dto['gt_order'] = is_array( $gt_data ) && ! empty( $gt_data )
            ? [
                'found'        => true,
                'status'       => (string) ( $gt_data['status'] ?? '' ),
                'source'       => (string) ( $gt_data['source'] ?? '' ),
                'auto'         => ! empty( $gt_data['auto'] ),
                'po_id'        => (string) ( $gt_data['po_id'] ?? '' ),
                'sender'       => (string) ( $gt_data['sender'] ?? '' ),
                'submitted_at' => (string) ( $gt_data['submitted_at'] ?? '' ),
                'stage_only'   => ! empty( $gt_data['stage_only'] ),
                'cart_items'   => array_values( (array) ( $gt_data['cart_items'] ?? [] ) ),
                'ship_to'      => is_array( $gt_data['ship_to'] ?? null ) ? $gt_data['ship_to'] : [],
                'gt_result'    => is_array( $gt_data['gt_result'] ?? null ) ? $gt_data['gt_result'] : [],
                'raw'          => $gt_data,
            ]
            : [ 'found' => false ];

        $dto['cart_vendor_summary'] = $o->get_meta( '_cc_cart_vendor_summary', true );

        // SM-facing vendor receipt (no raw API blobs).
        $dto['vendor_receipt'] = $this->dto_vendor_receipt( $o, $dto );

        // Raw API / trigger inspector — administrator only (Supply Manager gets ops fields only).
        if ( current_user_can( 'manage_options' ) ) {
            $dto['trigger_payload'] = [
                'order_id'             => (int) $o->get_id(),
                'order_number'         => (string) $o->get_order_number(),
                'status'               => (string) $o->get_status(),
                'selected_vendors'     => $dto['vendors'] ?? [],
                'auto_submit'          => $dto['auto_submit_detail'],
                'amazon_order'         => ! empty( $dto['amazon_order']['found'] ) ? $dto['amazon_order'] : null,
                'gt_order'             => ! empty( $dto['gt_order']['found'] ) ? $dto['gt_order'] : null,
                'cart_vendor_summary'  => $dto['cart_vendor_summary'],
                'totals'               => $dto['totals'],
                'vendor_receipt'       => $dto['vendor_receipt'],
            ];
        } else {
            if ( ! empty( $dto['amazon_order']['found'] ) ) {
                unset( $dto['amazon_order']['raw'], $dto['amazon_order']['amazon_response'] );
            }
            if ( ! empty( $dto['gt_order']['found'] ) ) {
                unset( $dto['gt_order']['raw'], $dto['gt_order']['gt_result'] );
            }
        }

        return rest_ensure_response( $dto );
    }


    public function orders_update_shipment( WP_REST_Request $req ) {
        $o = wc_get_order( (int) $req['id'] );
        if ( ! $o ) return new WP_Error('not_found','Order not found',[ 'status'=>404 ]);
        $res = WCSS_Shipment::save( $o, (string) $req->get_param('carrier'), (string) $req->get_param('tracking'), (string) $req->get_param('edd') );
        if ( is_wp_error( $res ) ) return $res;
        return rest_ensure_response( [ 'ok' => true, 'shipment' => $res ] );
    }

    public function orders_update_receiving( WP_REST_Request $req ) {
        $o = wc_get_order( (int) $req['id'] );
        if ( ! $o ) return new WP_Error('not_found','Order not found',[ 'status'=>404 ]);
    
        $received = (bool) $req->get_param('received');
        $date     = (string) $req->get_param('received_date');
        $notes    = (string) $req->get_param('notes');
    
        $issues   = $req->get_param('issues');
        if ( ! is_array( $issues ) ) $issues = [];
    
        $o->update_meta_data( '_wcss_received', $received ? 'yes' : 'no' );
        if ( $date )  $o->update_meta_data( '_wcss_received_date', $date );
        if ( $notes ) $o->update_meta_data( '_wcss_received_notes', $notes );
    
        // Save a compact JSON for issues (damage/shortage)
        $normalized = [];
        foreach ( $issues as $row ) {
            if ( ! is_array($row) ) continue;
            $normalized[] = [
                'sku'  => sanitize_text_field( $row['sku']  ?? '' ),
                'type' => in_array( ($row['type'] ?? ''), ['damage','shortage'], true ) ? $row['type'] : 'damage',
                'qty'  => (int) ($row['qty'] ?? 0),
                'note' => sanitize_textarea_field( (string) ($row['note'] ?? '') ),
            ];
        }
        $o->update_meta_data( '_wcss_receiving_issues', wp_json_encode( $normalized ) );
    
        $o->save();
        WCSS_Order_Events::log( $o->get_id(), 'receiving_update', [
            'received' => $received, 'received_date' => $date, 'issues' => $normalized
        ] );
    
        return rest_ensure_response([ 'ok' => true ]);
    }

    /**
     * Amazon cart/checkout snapshot saved against a WooCommerce order.
     */
    public function orders_read_amazon( WP_REST_Request $req ) {
        $order_id = (int) $req['id'];
        $o        = wc_get_order( $order_id );
        if ( ! $o ) {
            return new WP_Error( 'not_found', 'Order not found', [ 'status' => 404 ] );
        }

        $data = $o->get_meta( '_wcss_amazon_order_data', true );
        if ( ! is_array( $data ) || empty( $data ) ) {
            return rest_ensure_response([
                'ok'      => true,
                'found'   => false,
                'order'   => [
                    'id'     => $o->get_id(),
                    'number' => $o->get_order_number(),
                ],
                'amazon'  => null,
                'message' => 'No Amazon order details saved yet. Use Amazon Test Order → Cart/Checkout first.',
            ]);
        }

        if ( function_exists( 'wcss_amazon_enrich_snapshot' ) ) {
            $data = wcss_amazon_enrich_snapshot( $data );
        }

        return rest_ensure_response([
            'ok'     => true,
            'found'  => true,
            'order'  => [
                'id'          => $o->get_id(),
                'number'      => $o->get_order_number(),
                'status'      => wc_get_order_status_name( $o->get_status() ),
                'total'       => (float) $o->get_total(),
                'total_formatted' => html_entity_decode( wp_strip_all_tags( $o->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
                'currency'    => $o->get_currency(),
            ],
            'amazon' => $data,
        ]);
    }

    /**
     * Grand & Toy stage PO snapshot saved against a WooCommerce order.
     */
    public function orders_read_gt( WP_REST_Request $req ) {
        $order_id = (int) $req['id'];
        $o        = wc_get_order( $order_id );
        if ( ! $o ) {
            return new WP_Error( 'not_found', 'Order not found', [ 'status' => 404 ] );
        }

        $data = $o->get_meta( '_wcss_gt_order_data', true );
        if ( ! is_array( $data ) || empty( $data ) ) {
            return rest_ensure_response([
                'ok'      => true,
                'found'   => false,
                'order'   => [
                    'id'     => $o->get_id(),
                    'number' => $o->get_order_number(),
                ],
                'gt'      => null,
                'message' => 'No Grand & Toy details saved yet. Use G&T Test Order → Catalog/Cart/Checkout first.',
            ]);
        }

        return rest_ensure_response([
            'ok'    => true,
            'found' => true,
            'order' => [
                'id'              => $o->get_id(),
                'number'          => $o->get_order_number(),
                'status'          => wc_get_order_status_name( $o->get_status() ),
                'total'           => (float) $o->get_total(),
                'total_formatted' => html_entity_decode( wp_strip_all_tags( $o->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
                'currency'        => $o->get_currency(),
            ],
            'gt'    => $data,
        ]);
    }

    /**
     * Store employee who placed the order.
     *
     * @param WC_Order $o Order.
     * @return array<string,mixed>
     */
    private function dto_requester( WC_Order $o ): array {
        $uid = (int) $o->get_customer_id();
        $u   = $uid ? get_userdata( $uid ) : null;
        $name = trim( $o->get_formatted_billing_full_name() );
        if ( $name === '' && $u ) {
            $name = trim( $u->display_name );
        }
        return [
            'id'           => $uid ?: null,
            'name'         => $name !== '' ? $name : (string) $o->get_billing_email(),
            'email'        => (string) ( $u ? $u->user_email : $o->get_billing_email() ),
            'phone'        => (string) $o->get_billing_phone(),
            'company'      => (string) $o->get_billing_company(),
            'username'     => $u ? (string) $u->user_login : '',
            'customer_note'=> (string) $o->get_customer_note(),
        ];
    }

    /**
     * Plain-English vendor receipt for Supply Managers (Amazon / G&T / manual vendor).
     *
     * @param WC_Order           $o   Order.
     * @param array<string,mixed> $dto Detail DTO in progress.
     * @return array<string,mixed>
     */
    private function dto_vendor_receipt( WC_Order $o, array $dto ): array {
        $auto   = is_array( $dto['auto_submit_detail'] ?? null ) ? $dto['auto_submit_detail'] : [];
        $status = (string) ( $auto['status'] ?? '' );
        $amz    = is_array( $dto['amazon_order'] ?? null ) ? $dto['amazon_order'] : [];
        $gt     = is_array( $dto['gt_order'] ?? null ) ? $dto['gt_order'] : [];

        $vendors = [];
        if ( ! empty( $amz['found'] ) ) {
            $ship = is_array( $amz['shipping'] ?? null ) ? $amz['shipping'] : [];
            $lines = [];
            foreach ( (array) ( $amz['intended_lines'] ?? [] ) as $line ) {
                if ( ! is_array( $line ) ) {
                    continue;
                }
                $lines[] = [
                    'name'  => (string) ( $line['name'] ?? $line['asin'] ?? '' ),
                    'sku'   => (string) ( $line['asin'] ?? $line['vendor_id'] ?? '' ),
                    'qty'   => (int) ( $line['qty'] ?? 1 ),
                    'price' => isset( $line['unit_price'] ) ? (float) $line['unit_price'] : null,
                ];
            }
            $ref = (string) ( $amz['amazon_order_id'] ?? $amz['amazon_request_id'] ?? '' );
            if ( $ref === '' && ! empty( $amz['amazon_response']['data']['orderIdentifier'] ) ) {
                $ref = (string) $amz['amazon_response']['data']['orderIdentifier'];
            }
            // Ordering API returns OrderIdentifier inside accepted line artifacts.
            if ( $ref === '' && ! empty( $amz['amazon_response']['data']['lineItems'] ) && is_array( $amz['amazon_response']['data']['lineItems'] ) ) {
                foreach ( $amz['amazon_response']['data']['lineItems'] as $li ) {
                    foreach ( (array) ( $li['acceptedItems'] ?? [] ) as $acc ) {
                        foreach ( (array) ( $acc['artifacts'] ?? [] ) as $art ) {
                            if ( ( $art['acceptanceArtifactType'] ?? '' ) === 'OrderIdentifier' && ! empty( $art['identifier'] ) ) {
                                $ref = (string) $art['identifier'];
                                break 3;
                            }
                        }
                    }
                }
            }
            if ( $ref === '' && ! empty( $amz['amazon_response']['request_id'] ) ) {
                $ref = (string) $amz['amazon_response']['request_id'];
            }
            $vendors[] = [
                'key'          => 'amazon',
                'label'        => 'Amazon',
                'placed'       => in_array( (string) ( $amz['status'] ?? '' ), [ 'placed', 'submitted', 'accepted' ], true ),
                'status'       => (string) ( $amz['status'] ?? '' ),
                'ref'          => $ref,
                'placed_at'    => (string) ( $amz['placed_at'] ?? '' ),
                'trial_mode'   => ! empty( $amz['trial_mode'] ),
                'order_total'  => isset( $amz['order_total'] ) ? (float) $amz['order_total'] : null,
                'currency'     => (string) ( $amz['currency'] ?? 'CAD' ),
                'message'      => (string) ( $amz['message'] ?? '' ),
                'ship_to'      => [
                    'name'    => (string) ( $ship['full_name'] ?? '' ),
                    'company' => (string) ( $ship['company_name'] ?? '' ),
                    'line1'   => (string) ( $ship['address_line1'] ?? '' ),
                    'line2'   => (string) ( $ship['address_line2'] ?? '' ),
                    'city'    => (string) ( $ship['city'] ?? '' ),
                    'state'   => (string) ( $ship['state'] ?? '' ),
                    'postcode'=> (string) ( $ship['postal_code'] ?? '' ),
                    'phone'   => (string) ( $ship['phone'] ?? '' ),
                    'email'   => (string) ( $ship['email'] ?? '' ),
                ],
                'lines'        => $lines,
                'po_number'    => (string) ( $ship['purchase_order_number'] ?? $o->get_order_number() ),
            ];
        }
        if ( ! empty( $gt['found'] ) ) {
            $ship = is_array( $gt['ship_to'] ?? null ) ? $gt['ship_to'] : [];
            $lines = [];
            foreach ( (array) ( $gt['cart_items'] ?? [] ) as $line ) {
                if ( ! is_array( $line ) ) {
                    continue;
                }
                $lines[] = [
                    'name'  => (string) ( $line['name'] ?? $line['part_id'] ?? '' ),
                    'sku'   => (string) ( $line['part_id'] ?? $line['sku'] ?? '' ),
                    'qty'   => (int) ( $line['qty'] ?? $line['quantity'] ?? 1 ),
                    'price' => isset( $line['unit_price'] ) ? (float) $line['unit_price'] : ( isset( $line['price'] ) ? (float) $line['price'] : null ),
                ];
            }
            $vendors[] = [
                'key'         => 'gt',
                'label'       => 'Grand & Toy',
                // The PO number is made before sending, so a failed send has one too: trust the status only.
                'placed'      => in_array( (string) ( $gt['status'] ?? '' ), [ 'submitted', 'placed', 'accepted' ], true ),
                'status'      => (string) ( $gt['status'] ?? '' ),
                'ref'         => (string) ( $gt['po_id'] ?? '' ),
                'placed_at'   => (string) ( $gt['submitted_at'] ?? '' ),
                'trial_mode'  => ! empty( $gt['stage_only'] ),
                'order_total' => null,
                'currency'    => 'CAD',
                'message'     => '',
                'ship_to'     => [
                    'name'     => (string) ( $ship['name'] ?? $ship['deliver_to'] ?? '' ),
                    'company'  => (string) ( $ship['company'] ?? $ship['deliver_to'] ?? '' ),
                    'line1'    => isset( $ship['streets'][0] ) ? (string) $ship['streets'][0] : (string) ( $ship['address_1'] ?? '' ),
                    'line2'    => isset( $ship['streets'][1] ) ? (string) $ship['streets'][1] : (string) ( $ship['address_2'] ?? '' ),
                    'city'     => (string) ( $ship['city'] ?? '' ),
                    'state'    => (string) ( $ship['state'] ?? '' ),
                    'postcode' => (string) ( $ship['postal_code'] ?? $ship['postcode'] ?? '' ),
                    'phone'    => (string) ( $ship['phone'] ?? '' ),
                    'email'    => (string) ( $ship['email'] ?? '' ),
                ],
                'lines'       => $lines,
                'po_number'   => (string) ( $gt['po_id'] ?? $o->get_order_number() ),
            ];
        }

        $mode    = 'pending';
        $message = (string) ( $auto['message'] ?? '' );
        if ( in_array( $status, [ 'woo_only', 'skipped' ], true ) ) {
            // Orders from before every order had to reach a vendor: nothing was ever sent.
            $mode    = 'failed';
            $message = 'No vendor was assigned, so this order was never sent. Assign a vendor to the product, then retry.';
        } elseif ( $status === 'manual' ) {
            $mode = 'manual';
        } elseif ( $status === 'submitted' || ( $vendors && array_reduce( $vendors, static function ( $ok, $v ) { return $ok || ! empty( $v['placed'] ); }, false ) ) ) {
            $mode = 'sent';
        } elseif ( in_array( $status, [ 'failed', 'partial' ], true ) ) {
            $mode = 'failed';
        } elseif ( $status === 'processing' ) {
            $mode = 'processing';
        } elseif ( $status === 'needs_approval' ) {
            $mode = 'needs_approval';
        }

        return [
            'mode'           => $mode,
            'auto_status'    => $status !== '' ? $status : ( $mode === 'pending' ? '' : $mode ),
            'message'        => $message,
            'manual_note'    => (string) ( $auto['manual_note'] ?? '' ),
            'winning_vendor' => (string) ( $auto['winning_vendor'] ?? ( $dto['vendor'] ?? '' ) ),
            'completed_at'   => (string) ( $auto['completed_at'] ?? '' ),
            'vendors'        => $vendors,
            'can_retry'      => in_array( $status, [ 'failed', 'partial', 'processing' ], true )
                || ( $o->get_status() === 'approved' && $mode === 'failed' ),
        ];
    }

    private function dto_addr( array $a ): array {
        return [
            'first_name'=>$a['first_name'] ?? '', 'last_name'=>$a['last_name'] ?? '',
            'company'=>$a['company'] ?? '', 'address_1'=>$a['address_1'] ?? '',
            'address_2'=>$a['address_2'] ?? '', 'city'=>$a['city'] ?? '',
            'state'=>$a['state'] ?? '', 'postcode'=>$a['postcode'] ?? '',
            'country'=>$a['country'] ?? '', 'email'=>$a['email'] ?? '', 'phone'=>$a['phone'] ?? '',
        ];
    }
    private function sanitize_addr( array $a ): array {
        $f = [];
        foreach ( ['first_name','last_name','company','address_1','address_2','city','state','postcode','country','email','phone'] as $k ) {
            if ( isset( $a[$k] ) ) { $f[$k] = is_email( $a[$k] ) && $k==='email' ? sanitize_email($a[$k]) : sanitize_text_field($a[$k]); }
        }
        return $f;
    }
}