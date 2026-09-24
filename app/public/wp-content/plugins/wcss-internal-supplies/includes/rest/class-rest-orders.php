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

        register_rest_route( self::NS, '/orders/(?P<id>\d+)/status', [
            'methods'=>'POST', 'permission_callback'=>[ $this,'can_manage' ],
            'callback'=>[ $this,'orders_update_status' ],
            'args'=>['status'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field'],
                     'note'=>['required'=>false,'sanitize_callback'=>'sanitize_textarea_field']],
        ]);

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
            $orders = wc_get_orders( $args );
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
                'total'       => (int) $args['total'] ?? count($items), // fallback
                'total_pages' => ceil( ((int)$args['total'] ?? count($items)) / $per ),
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
    
        // If moving to "approved", enforce limits unless override=1
        if ( $status === 'approved' ) {
            $store_id = (int) $o->get_meta('_wcss_store_id');
            if ( $store_id ) {
                $ym  = gmdate('Y-m');
                $row = class_exists('WCSS_Ledger') ? WCSS_Ledger::get( $store_id, $ym ) : ['orders_count'=>0,'spend_total'=>0];
    
                $quota_used  = (int) ($row['orders_count'] ?? 0);
                $budget_used = (float) ($row['spend_total'] ?? 0);
    
                $quota   = (int) get_post_meta( $store_id, '_store_quota',  true );
                $budget  = (float) get_post_meta( $store_id, '_store_budget', true );
                $order_total = (float) $o->get_total();
    
                $will_orders = $quota_used + 1;
                $will_spend  = $budget_used + $order_total;
    
                $exceeds_count  = ($quota > 0)  && ($will_orders > $quota);
                $exceeds_budget = ($budget > 0) && ($will_spend  > $budget);
    
                if ( ($exceeds_count || $exceeds_budget) && ! $override ) {
                    // 409 with payload to show in UI
                    return new WP_Error(
                        'wcss_limit_block',
                        __( 'Approving this order exceeds the store’s monthly limit.', 'wcss' ),
                        [
                            'status' => 409,
                            'data'   => [
                                'store_id'      => $store_id,
                                'month'         => $ym,
                                'quota'         => $quota,
                                'quota_used'    => $quota_used,
                                'will_orders'   => $will_orders,
                                'budget'        => $budget,
                                'budget_used'   => $budget_used,
                                'order_total'   => $order_total,
                                'will_spend'    => $will_spend,
                                'currency'      => get_woocommerce_currency(),
                            ],
                        ]
                    );
                }
            }
        }
        
            // proceed
        $o->update_status( $status, $note ? $note : __( 'Status updated (Manager)', 'wcss' ), true );
        WCSS_Order_Events::log( $o->get_id(), 'status_change', [ 'new'=>$status, 'note'=>$note, 'override'=>$override ] );
    
        return rest_ensure_response([ 'ok'=>true ]);
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

    private function dto_order( WC_Order $o ): array {

            // $store_id = (int) $o->get_meta( '_wcss_store_id' );
            // $store_id = $store_id ?: 0;
        
            $sid   = (int) $o->get_meta('_wcss_store_id');
            $store = [
                'id'    => $sid ?: null,
                'name'  => $sid ? ( get_the_title($sid) ?: '' ) : '',
                'code'  => $sid ? (string) get_post_meta($sid, '_store_code',  true)  : '',
                'city'  => $sid ? (string) get_post_meta($sid, '_store_city',  true)  : '',
                'state' => $sid ? (string) get_post_meta($sid, '_store_state', true)  : '',
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
            $shipment = [
                'tracking' => (string) $o->get_meta( '_wcss_tracking' ),
                'carrier'  => (string) $o->get_meta( '_wcss_carrier' ),
                'edd'      => (string) $o->get_meta( '_wcss_edd' ), // optional: YYYY-MM-DD
            ];

        return [
            'id'         => $o->get_id(),
            'number'     => $o->get_order_number(),
            'date'       => $o->get_date_created() ? $o->get_date_created()->date_i18n('Y-m-d H:i') : '',
            'status'     => wc_get_order_status_name( $o->get_status() ),
            'status_slug'=> $o->get_status(),
            'total'      => $o->get_total(),                  // raw numeric; JS can format
            'total_html' => $o->get_formatted_order_total(),  // pretty
            'customer'   => $o->get_billing_company() ?: $o->get_billing_email(),
            'edd'        => $o->get_meta('_wcss_expected_delivery_date'),
            'ref'        => $o->get_meta('_wcss_internal_ref'),
            'store'      => $store,
            'shipment' => $shipment,
            'has_amazon_order' => (bool) $o->get_meta( '_wcss_amazon_order_data' ),
        ];

    }


    public function orders_read( WP_REST_Request $req ) {
        $o = wc_get_order( (int) $req['id'] );
        if ( ! $o ) return new WP_Error('not_found','Order not found',[ 'status'=>404 ]);

        $dto = $this->dto_order( $o );

        // Addresses
        $dto['billing']  = $this->dto_addr( $o->get_address('billing') );
        $dto['shipping'] = $this->dto_addr( $o->get_address('shipping') );

        // Items (include SKU, product/variation IDs)
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

            $dto['items'][] = [
                'item_id'     => $item_id,
                'product_id'  => $pid ?: $item->get_product_id(),
                'variation_id'=> $vid ?: $item->get_variation_id(),
                'name'        => $name,
                'sku'         => $sku,
                'qty'         => $qty,
                'subtotal'    => wc_price( (float) $item->get_subtotal() ),
                'total'       => wc_price( $line_total ),
                'attributes'  => $attrs,
            ];
        }

        // Timeline/events if you’re using it
        if ( class_exists( 'WCSS_Order_Events' ) ) {
            $dto['timeline'] = WCSS_Order_Events::for_order( $o->get_id(), 50 );
        }

        // Attach ledger only if store set
        if ( ! empty( $dto['store']['id'] ) && class_exists( 'WCSS_Ledger' ) ) {
            $ym  = gmdate('Y-m');
            $row = WCSS_Ledger::get( (int) $dto['store']['id'], $ym ) ?: [ 'orders_count'=>0, 'spend_total'=>0 ];
            $quota  = (int) get_post_meta( (int) $dto['store']['id'], '_store_quota',  true );
            $budget = (float) get_post_meta( (int) $dto['store']['id'], '_store_budget', true );

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

        return rest_ensure_response( $dto );
    }


    public function orders_update_shipment( WP_REST_Request $req ) {
        $o = wc_get_order( (int) $req['id'] );
        if ( ! $o ) return new WP_Error('not_found','Order not found',[ 'status'=>404 ]);
    
        $tracking = trim( (string) $req->get_param('tracking') );
        $carrier  = trim( (string) $req->get_param('carrier') );
        $edd      = trim( (string) $req->get_param('edd') );
    
        if ( $tracking !== '' ) $o->update_meta_data( '_wcss_tracking', $tracking );
        if ( $carrier  !== '' ) $o->update_meta_data( '_wcss_carrier', $carrier );
        if ( $edd      !== '' ) $o->update_meta_data( '_wcss_edd', $edd );
    
        $o->save();
        if ( $tracking || $carrier || $edd ) {
            WCSS_Order_Events::log( $o->get_id(), 'shipment_update', [
                'tracking' => $tracking, 'carrier' => $carrier, 'edd' => $edd
            ] );
        }
        return rest_ensure_response([ 'ok' => true ]);
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