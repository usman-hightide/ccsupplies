<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_REST_Vendors {
    const NS = 'wcss/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    public function can_manage( $req = null ): bool {
        return current_user_can('wcss_manage_portal') || current_user_can('manage_options');
    }

    /** Raw price-feed / API inspector — WordPress administrator only. */
    public function can_admin( $req = null ): bool {
        return current_user_can( 'manage_options' );
    }

    public function routes() {
        register_rest_route( self::NS, '/vendors', [
            'methods' => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'list' ],
        ]);

        register_rest_route( self::NS, '/vendors/(?P<id>\d+)', [
            'methods' => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'read' ],
        ]);

        register_rest_route( self::NS, 'vendors/create', [
            'methods' => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'create' ],
            // Fields are validated + sanitized in create()/save_meta_array(); a route-level
            // sanitize_email here would silently blank an invalid email instead of rejecting it.
            'args' => [
                'name' => [ 'required'=>true, 'sanitize_callback'=>'sanitize_text_field' ],
            ],
        ]);

        register_rest_route( self::NS, '/vendors/(?P<id>\d+)', [
            'methods' => 'PUT',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'update' ],
        ]);

        // Per-product results of the last price fetches (cc_vendor_prices) + last sync run log.
        register_rest_route( self::NS, '/vendors/(?P<id>\d+)/price-log', [
            'methods' => 'GET',
            'permission_callback' => [ $this, 'can_admin' ],
            'callback' => [ $this, 'price_log' ],
        ]);

        // Monthly spend budget: { month: "YYYY-MM", amount: number|null, as_default: bool }
        register_rest_route( self::NS, '/vendors/(?P<id>\d+)/budget', [
            'methods' => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'set_budget' ],
        ]);

        register_rest_route( self::NS, '/vendors/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'delete' ],
        ]);
    }



    /** @return array<string, float> month (YYYY-MM) => CAD amount */
    private function budgets( int $term_id ): array {
        $b = get_term_meta( $term_id, 'wcss_vendor_budgets', true );
        return is_array( $b ) ? array_map( 'floatval', $b ) : [];
    }

    private function budget_default( int $term_id ) {
        $v = get_term_meta( $term_id, 'wcss_vendor_budget_default', true );
        return $v === '' ? null : (float) $v;
    }

    public function set_budget( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $t  = get_term( $id, 'wcss_vendor' );
        if ( ! $t || is_wp_error( $t ) ) return new WP_Error( 'not_found', 'Vendor not found', [ 'status' => 404 ] );

        $body   = $req->get_json_params() ?: [];
        $month  = (string) ( $body['month'] ?? '' );
        $amount = $body['amount'] ?? null;
        if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
            return new WP_Error( 'wcss_bad_month', 'Month must be YYYY-MM.', [ 'status' => 400 ] );
        }
        if ( $amount !== null && $amount !== '' && ( ! is_numeric( $amount ) || (float) $amount < 0 ) ) {
            return new WP_Error( 'wcss_bad_amount', 'Budget must be a positive number.', [ 'status' => 400 ] );
        }

        $budgets = $this->budgets( $id );
        if ( $amount === null || $amount === '' ) {
            unset( $budgets[ $month ] );
        } else {
            $budgets[ $month ] = round( (float) $amount, 2 );
        }
        ksort( $budgets );
        update_term_meta( $id, 'wcss_vendor_budgets', $budgets );
        do_action( 'wcss_vendor_changed', 'budget', $id, $t->name, [ 'month' => $month, 'monthly_budget' => ( $amount === null || $amount === '' ) ? 'Removed' : WCSS_Email_Template::money( $amount ) ] );

        if ( ! empty( $body['as_default'] ) ) {
            if ( $amount === null || $amount === '' ) {
                delete_term_meta( $id, 'wcss_vendor_budget_default' );
            } else {
                update_term_meta( $id, 'wcss_vendor_budget_default', round( (float) $amount, 2 ) );
            }
        }

        return rest_ensure_response( $this->dto( get_term( $id, 'wcss_vendor' ) ) );
    }

    public function price_log( WP_REST_Request $req ) {
        $t = get_term( (int) $req['id'], 'wcss_vendor' );
        if ( ! $t || is_wp_error( $t ) ) return new WP_Error( 'not_found', 'Vendor not found', [ 'status' => 404 ] );

        $key = function_exists( 'cc_vendor_prices_normalize_vendor' ) && function_exists( 'cc_vendor_prices_table' )
            ? cc_vendor_prices_normalize_vendor( $t->slug ) : '';
        if ( $key === '' ) {
            return rest_ensure_response([ 'vendor' => $this->clean_name( $t->name ), 'has_feed' => false ]);
        }

        global $wpdb;
        $table = cc_vendor_prices_table();
        $rows  = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT product_id, vendor_sku, price, currency, status, error_message, fetched_at FROM {$table}
             WHERE vendor = %s ORDER BY (status = 'ok'), fetched_at DESC LIMIT 300",
            $key
        ), ARRAY_A );

        $items  = [];
        $errors = [];
        foreach ( $rows as $r ) {
            $msg = (string) $r['error_message'];
            if ( $r['status'] === 'error' ) {
                // Group identical failures: per-request incident/request IDs differ but the cause is the same.
                $k = $msg !== '' ? trim( preg_replace( '/\b(Incident|Request)\s*ID:\s*[\w-]+\.?/i', '', $msg ) ) : '(no error message returned)';
                if ( ! isset( $errors[ $k ] ) ) {
                    preg_match( '/\b(?:Incident|Request)\s*ID:\s*([\w-]+)/i', $msg, $m );
                    $errors[ $k ] = [ 'count' => 0, 'example_id' => $m[1] ?? '' ];
                }
                $errors[ $k ]['count']++;
            }
            $items[] = [
                'product_id' => (int) $r['product_id'],
                'product'    => html_entity_decode( get_the_title( (int) $r['product_id'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                'sku'        => (string) get_post_meta( (int) $r['product_id'], '_sku', true ),
                'vendor_sku' => (string) $r['vendor_sku'],
                'status'     => (string) $r['status'],
                'price'      => $r['price'] === null ? null : (float) $r['price'],
                'currency'   => (string) $r['currency'],
                'error'      => $msg,
                'fetched_at' => $r['fetched_at'] ? wp_date( 'Y-m-d H:i', strtotime( $r['fetched_at'] . ' UTC' ) ) : '',
            ];
        }
        uasort( $errors, function ( $a, $b ) { return $b['count'] <=> $a['count']; } );

        return rest_ensure_response([
            'vendor'   => $this->clean_name( $t->name ),
            'has_feed' => true,
            'feed'     => $this->price_feed( $t ),
            'errors'   => array_map( function ( $m, $e ) { return [ 'message' => $m, 'count' => $e['count'], 'example_id' => $e['example_id'] ]; }, array_keys( $errors ), $errors ),
            'items'    => $items,
            'sync_log' => defined( 'CC_VENDOR_PRICES_SYNC_LOG' ) ? get_option( CC_VENDOR_PRICES_SYNC_LOG, null ) : null,
        ]);
    }

    /**
     * Freshness of the vendor price cache (child theme cc_vendor_prices table), for
     * vendors that have a live price feed (Amazon, Grand & Toy).
     */
    private function price_feed( WP_Term $t ): ?array {
        if ( ! function_exists( 'cc_vendor_prices_table' ) || ! function_exists( 'cc_vendor_prices_normalize_vendor' ) ) return null;
        $key = cc_vendor_prices_normalize_vendor( $t->slug );
        if ( $key === '' ) return null;

        static $rows = null;
        if ( $rows === null ) {
            global $wpdb;
            $table = cc_vendor_prices_table();
            $rows  = [];
            foreach ( (array) $wpdb->get_results( "SELECT vendor, status, COUNT(*) n, MAX(fetched_at) last FROM {$table} GROUP BY vendor, status", ARRAY_A ) as $r ) {
                $rows[ $r['vendor'] ][ $r['status'] ] = [ 'n' => (int) $r['n'], 'last' => $r['last'] ];
            }
        }
        $mine = $rows[ $key ] ?? [];
        $last = '';
        foreach ( $mine as $r ) { if ( $r['last'] && $r['last'] > $last ) $last = $r['last']; }

        $error = '';
        if ( ! empty( $mine['error'] ) ) {
            global $wpdb;
            $error = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT error_message FROM " . cc_vendor_prices_table() . " WHERE vendor=%s AND status='error' AND error_message<>'' ORDER BY fetched_at DESC LIMIT 1",
                $key
            ) );
        }

        return [
            'key'        => $key, // fetched_at is stored in UTC (current_time( 'mysql', true ))
            'last_fetch' => $last ? wp_date( 'Y-m-d H:i', strtotime( $last . ' UTC' ) ) : '',
            'ok'         => $mine['ok']['n'] ?? 0,
            'errors'     => $mine['error']['n'] ?? 0,
            'missing_id' => $mine['missing_id']['n'] ?? 0,
            'last_error' => wp_html_excerpt( $error, 140, '…' ),
        ];
    }

    private function clean_name( string $name ): string {
        return html_entity_decode( trim( $name ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    private function dto( WP_Term $t ): array {
        return [
            'id'      => (int) $t->term_id,
            'name'    => $this->clean_name( (string) $t->name ),
            'slug'    => $t->slug,
            'phone'   => (string) get_term_meta( $t->term_id, 'wcss_vendor_phone', true ),
            'email'   => (string) get_term_meta( $t->term_id, 'wcss_vendor_email', true ),
            'address' => (string) get_term_meta( $t->term_id, 'wcss_vendor_address', true ),
            'contact'    => (string) get_term_meta( $t->term_id, 'wcss_vendor_contact', true ),
            'website'    => (string) get_term_meta( $t->term_id, 'wcss_vendor_website', true ),
            'account_no' => (string) get_term_meta( $t->term_id, 'wcss_vendor_account_no', true ),
            'notes'      => (string) get_term_meta( $t->term_id, 'wcss_vendor_notes', true ),
            'budgets'        => $this->budgets( $t->term_id ),
            'budget_default' => $this->budget_default( $t->term_id ),
            'feed'           => $this->price_feed( $t ),
        ];
    }

    public function list( WP_REST_Request $req ) {
        $terms = get_terms([
            'taxonomy'   => 'wcss_vendor',
            'hide_empty' => false,
            'number'     => 200,
        ]);
        if ( is_wp_error( $terms ) ) return $terms;
        return rest_ensure_response([ 'items' => array_map( [ $this, 'dto' ], $terms ) ]);
    }

    public function read( WP_REST_Request $req ) {
        $t = get_term( (int) $req['id'], 'wcss_vendor' );
        if ( ! $t || is_wp_error( $t ) ) return new WP_Error('not_found','Vendor not found',[ 'status'=>404 ]);
        return rest_ensure_response( $this->dto( $t ) );
    }

    /** Editable vendor fields => term meta key. */
    const FIELDS = [
        'email'      => 'wcss_vendor_email',
        'phone'      => 'wcss_vendor_phone',
        'address'    => 'wcss_vendor_address',
        'contact'    => 'wcss_vendor_contact',
        'website'    => 'wcss_vendor_website',
        'account_no' => 'wcss_vendor_account_no',
        'notes'      => 'wcss_vendor_notes',
    ];

    /**
     * Fields that differ from what is saved, for the "Vendor details changed" email.
     * Without a vendor id (create) every filled field counts.
     */
    private function change_summary( array $data, int $id = 0, string $name = '' ): array {
        $out = [];
        if ( $id && isset( $data['name'] ) && $this->clean_name( (string) $data['name'] ) !== $this->clean_name( $name ) ) {
            $out['name'] = $this->clean_name( $name ) . ' → ' . $this->clean_name( (string) $data['name'] );
        }
        foreach ( self::FIELDS as $k => $meta ) {
            if ( ! array_key_exists( $k, $data ) ) continue;
            $new = trim( (string) $data[ $k ] );
            $old = $id ? trim( (string) get_term_meta( $id, $meta, true ) ) : '';
            if ( $new !== $old ) {
                $out[ $k ] = $new === '' ? 'Cleared' : $new;
            }
        }
        return $out;
    }

    /** @return WP_Error|null */
    private function validate_fields( array $data ) {
        if ( ! empty( $data['email'] ) && ! is_email( trim( (string) $data['email'] ) ) ) {
            return new WP_Error( 'wcss_bad_email', 'Order email is not a valid email address.', [ 'status' => 400 ] );
        }
        if ( ! empty( $data['website'] ) && ! filter_var( $this->normalize_url( (string) $data['website'] ), FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'wcss_bad_website', 'Website is not a valid URL.', [ 'status' => 400 ] );
        }
        if ( isset( $data['budget_default'] ) && $data['budget_default'] !== '' && $data['budget_default'] !== null
            && ( ! is_numeric( $data['budget_default'] ) || (float) $data['budget_default'] < 0 ) ) {
            return new WP_Error( 'wcss_bad_amount', 'Default monthly budget must be a positive number.', [ 'status' => 400 ] );
        }
        return null;
    }

    private function normalize_url( string $url ): string {
        $url = trim( $url );
        return ( $url !== '' && ! preg_match( '#^https?://#i', $url ) ) ? 'https://' . $url : $url;
    }

    public function create( WP_REST_Request $req ) {
        $data = $req->get_json_params() ?: $req->get_params();
        $name = $this->clean_name( (string) ( $data['name'] ?? '' ) );
        if ( $name === '' ) return new WP_Error( 'bad_name', 'Vendor name is required.', [ 'status' => 400 ] );
        if ( $err = $this->validate_fields( $data ) ) return $err;

        $exists = term_exists( $name, 'wcss_vendor' );
        if ( $exists && ! is_wp_error( $exists ) ) {
            // The Vendors screen asks for strict mode; the product form's "+ New vendor" reuses the existing term.
            if ( ! empty( $data['strict'] ) ) {
                return new WP_Error( 'wcss_vendor_exists', "A vendor named {$name} already exists.", [ 'status' => 409 ] );
            }
            $t = get_term( (int) $exists['term_id'] );
            if ( $t && ! is_wp_error( $t ) ) {
                $this->save_meta_array( $t->term_id, array_filter( $data, function ( $v ) { return $v !== '' && $v !== null; } ) );
                return rest_ensure_response( $this->dto( $t ) );
            }
        }
        $res = wp_insert_term( $name, 'wcss_vendor' );
        if ( is_wp_error( $res ) ) return $res;

        $term_id = (int) $res['term_id'];
        $this->save_meta_array( $term_id, $data );
        do_action( 'wcss_vendor_changed', 'created', $term_id, $name, $this->change_summary( $data ) );
        return rest_ensure_response( $this->dto( get_term( $term_id ) ) );
    }

    public function update( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $t = get_term( $id, 'wcss_vendor' );
        if ( ! $t || is_wp_error($t) ) return new WP_Error('not_found','Vendor not found',[ 'status'=>404 ]);

        $data = $req->get_json_params() ?: [];
        if ( array_key_exists( 'name', $data ) && trim( (string) $data['name'] ) === '' ) {
            return new WP_Error( 'bad_name', 'Vendor name is required.', [ 'status' => 400 ] );
        }
        if ( $err = $this->validate_fields( $data ) ) return $err;
        $changes = $this->change_summary( $data, $id, $t->name );
        if ( isset($data['name']) && trim($data['name']) !== '' ) {
            $ren = wp_update_term( $id, 'wcss_vendor', [
                'name' => sanitize_text_field( $this->clean_name( (string) $data['name'] ) ),
            ] );
            if ( is_wp_error($ren) ) return $ren;
        }
        $this->save_meta_array( $id, $data );
        $t = get_term( $id, 'wcss_vendor' );
        if ( $changes ) {
            do_action( 'wcss_vendor_changed', 'updated', $id, $t->name, $changes );
        }
        return rest_ensure_response( $this->dto( $t ) );
    }

    public function delete( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $old = get_term( $id, 'wcss_vendor' );
        $del = wp_delete_term( $id, 'wcss_vendor' );
        if ( is_wp_error( $del ) ) return $del;
        if ( $del && $old && ! is_wp_error( $old ) ) {
            do_action( 'wcss_vendor_changed', 'deleted', $id, $old->name, [] );
        }
        return rest_ensure_response([ 'ok' => true ]);
    }

    /** Saves only the keys present in $data; an empty value clears the field. */
    private function save_meta_array( int $term_id, array $data ): void {
        foreach ( self::FIELDS as $field => $meta_key ) {
            if ( ! array_key_exists( $field, $data ) ) continue;
            $raw = trim( (string) $data[ $field ] );
            if ( $raw === '' ) { delete_term_meta( $term_id, $meta_key ); continue; }
            switch ( $field ) {
                case 'email':   $val = sanitize_email( $raw ); break;
                case 'website': $val = esc_url_raw( $this->normalize_url( $raw ) ); break;
                case 'address':
                case 'notes':   $val = sanitize_textarea_field( $raw ); break;
                default:        $val = sanitize_text_field( $raw );
            }
            update_term_meta( $term_id, $meta_key, $val );
        }
        if ( array_key_exists( 'budget_default', $data ) ) {
            $b = $data['budget_default'];
            if ( $b === '' || $b === null ) delete_term_meta( $term_id, 'wcss_vendor_budget_default' );
            else update_term_meta( $term_id, 'wcss_vendor_budget_default', round( (float) $b, 2 ) );
        }
    }
}