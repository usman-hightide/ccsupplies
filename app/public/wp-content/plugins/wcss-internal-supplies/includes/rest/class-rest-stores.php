<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_REST_Stores {
    const NS = 'wcss/v1';
    public function __construct(){ add_action( 'rest_api_init', [ $this, 'routes' ] ); }
    public function can_manage( $r=null ): bool {
        return current_user_can( 'wcss_manage_portal' ) || current_user_can( 'manage_options' );
    }

    public function routes() {

        register_rest_route( self::NS, '/users', [
            'methods'  => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'list_users' ],
        ]);

        register_rest_route( self::NS, '/users', [
            'methods'  => 'POST',
            'permission_callback' => [ $this, 'can_manage' ], // same cap as your other manager endpoints
            'callback' => [ $this, 'create_user' ],
            'args'     => [
                'first_name' => ['required'=>true,  'sanitize_callback'=>'sanitize_text_field'],
                'last_name'  => ['required'=>false, 'sanitize_callback'=>'sanitize_text_field'],
                'email'      => ['required'=>true,  'sanitize_callback'=>'sanitize_email'],
                // optional: if omitted, we’ll auto-generate a secure password
                'password'   => ['required'=>false, 'sanitize_callback'=>'sanitize_text_field'],
            ],
        ]);
        register_rest_route( self::NS, '/stores', [
            'methods'  => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'list' ],
            'args'     => [
                'page' => [
                    'required' => false,
                    'validate_callback' => function( $value ) { return is_numeric( $value ) && (int)$value >= 1; },
                ],
                'per_page' => [
                    'required' => false,
                    'validate_callback' => function( $value ) { return is_numeric( $value ) && (int)$value >= 1 && (int)$value <= 100; },
                ],
                'search' => [
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route( self::NS, '/stores/(?P<id>\d+)', [
            'methods'=>'GET','permission_callback'=>[ $this,'can_manage' ],'callback'=>[ $this,'read' ],
        ]);

        //  Create
        register_rest_route( self::NS, '/stores', [
            'methods'=>'POST','permission_callback'=>[ $this,'can_manage' ],'callback'=>[ $this,'create' ],
            'args'=>[
                'name'  => ['required'=>true,  'sanitize_callback'=>'sanitize_text_field'],
                'code'  => ['required'=>true, 'sanitize_callback'=>'sanitize_text_field'],
                'city'  => ['required'=>false, 'sanitize_callback'=>'sanitize_text_field'],
                'state' => ['required'=>false, 'sanitize_callback'=>'sanitize_text_field'],
                'quota' => [
                    'required'=>true,
                    'validate_callback'=> function( $value ) { return $value === '' || is_numeric( $value ); },
                ],
                'budget'=> ['required'=>true, 'sanitize_callback'=>'wc_format_decimal'],
                'user_id' => [
                    'required' => true,
                    'validate_callback' => [ $this, 'validate_user_id_required' ],
                ],
            
            ],

        ]);

        // Update
        register_rest_route( self::NS, '/stores/(?P<id>\d+)', [
            'methods'=>'PUT','permission_callback'=>[ $this,'can_manage' ],'callback'=>[ $this,'update' ],
        ]);

        //  Delete
        register_rest_route( self::NS, '/stores/(?P<id>\d+)', [
            'methods'=>'DELETE','permission_callback'=>[ $this,'can_manage' ],'callback'=>[ $this,'delete' ],
        ]);

        // (Ledger route you added can stay here OR be in class-rest-ledger.php)
        register_rest_route( self::NS, '/stores/(?P<id>\d+)/ledger', [
            'methods'=>'GET','permission_callback'=>[ $this,'can_manage' ],'callback'=>[ $this,'store_ledger' ],
            'args'=>['month'=>['sanitize_callback'=>'sanitize_text_field']],
        ]);
    }

    public function list( WP_REST_Request $req ) {
        $page   = max( 1, (int) ( $req->get_param('page') ?: 1 ) );
        $per    = min( 100, max( 1, (int) ( $req->get_param('per_page') ?: 20 ) ) );
        $search = sanitize_text_field( (string) $req->get_param('search') );
    
        $args = [
            'post_type'      => 'store_location',
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'posts_per_page' => $per,
            'paged'          => $page,
            's'              => $search,
            'no_found_rows'  => false, // we want totals
            'fields'         => 'all',
        ];
    
        $q = new WP_Query( $args );
    
        if ( is_wp_error( $q ) ) {
            return new WP_Error( 'wcss_stores_query_failed', $q->get_error_message(), [ 'status' => 500 ] );
        }
    
        $items = array_map( [ $this, 'dto' ], (array) $q->posts );
    
        return rest_ensure_response([
            'items'       => $items,
            'total'       => (int) $q->found_posts,
            'total_pages' => (int) $q->max_num_pages,
            'page'        => $page,
            'per_page'    => $per,
        ]);
    }

    public function read( WP_REST_Request $req ) {
        $p = get_post( (int) $req['id'] );
        if ( ! $p || $p->post_type !== 'store_location' ) return new WP_Error('not_found','Store not found',[ 'status'=>404 ]);
        return rest_ensure_response( $this->dto( $p ) );
    }
    public function create_old( WP_REST_Request $req ) {
        // ——— Sanitize + trim
        $name  = trim( (string) $req->get_param('name') );
        $code  = trim( (string) $req->get_param('code') );
        $city  = trim( (string) $req->get_param('city') );
        $state = trim( (string) $req->get_param('state') );
    
        // ——— Required fields
        if ( $name === '' || $code === '' ) {
            return new WP_Error(
                'wcss_store_bad_input',
                'Name and Code are required.',
                [ 'status' => 400 ]
            );
        }
    
        // ——— Validate numeric inputs when provided
        $quota  = $req->get_param('quota');
        $budget = $req->get_param('budget');
    
        if ( $quota !== null && $quota !== '' && ( ! is_numeric( $quota ) || (int) $quota < 0 ) ) {
            return new WP_Error( 'wcss_store_bad_quota', 'Quota must be a non-negative integer.', [ 'status' => 400 ] );
        }
        if ( $budget !== null && $budget !== '' && ( ! is_numeric( $budget ) || (float) $budget < 0 ) ) {
            return new WP_Error( 'wcss_store_bad_budget', 'Budget must be a non-negative number.', [ 'status' => 400 ] );
        }
    
        // ——— Ensure store code is unique (check BOTH new + legacy keys)
        $exists = get_posts( [
            'post_type'        => 'store_location',
            'post_status'      => 'any',
            'fields'           => 'ids',
            'posts_per_page'   => 1,
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'meta_query'       => [
                'relation' => 'OR',
                [
                    'key'     => WCSS_Store_CPT::META_CODE,   // _wcss_store_code
                    'value'   => $code,
                    'compare' => '=',
                ],
                [
                    'key'     => '_store_code',                // legacy
                    'value'   => $code,
                    'compare' => '=',
                ],
            ],
        ] );
        if ( ! empty( $exists ) ) {
            return new WP_Error(
                'wcss_store_code_exists',
                'A store with this code already exists.',
                [ 'status' => 409 ]
            );
        }
    
        // ——— Create the post
        $post_id = wp_insert_post( [
            'post_type'   => 'store_location',
            'post_title'  => sanitize_text_field( $name ),
            'post_status' => 'publish',
        ], true );
        if ( is_wp_error( $post_id ) ) return $post_id;
    
        // ——— Write NEW canonical meta keys
        update_post_meta( $post_id, WCSS_Store_CPT::META_CODE,   sanitize_text_field( $code ) );
        update_post_meta( $post_id, WCSS_Store_CPT::META_CITY,   sanitize_text_field( $city ) );
        update_post_meta( $post_id, WCSS_Store_CPT::META_STATE,  sanitize_text_field( $state ) );
    
        if ( $quota !== null && $quota !== '' ) {
            update_post_meta( $post_id, WCSS_Store_CPT::META_QUOTA,  (int) $quota );
        }
        if ( $budget !== null && $budget !== '' ) {
            update_post_meta( $post_id, WCSS_Store_CPT::META_BUDGET, wc_format_decimal( $budget ) );
        }
    
        // ——— Also write LEGACY keys for backward compatibility (until DB/backfill is complete)
        update_post_meta( $post_id, '_store_code',  sanitize_text_field( $code ) );
        update_post_meta( $post_id, '_store_city',  sanitize_text_field( $city ) );
        update_post_meta( $post_id, '_store_state', sanitize_text_field( $state ) );
        if ( $quota !== null && $quota !== '' ) {
            update_post_meta( $post_id, '_store_quota', (int) $quota );
        }
        if ( $budget !== null && $budget !== '' ) {
            update_post_meta( $post_id, '_store_budget', wc_format_decimal( $budget ) );
        }
    
        // ——— Required: assign a Store Employee (unique per user)
        $uid = (int) $req->get_param( 'user_id' );
        if ( $uid <= 0 ) {
            return new WP_Error(
                'wcss_store_user_required',
                'A Store Employee must be assigned.',
                [ 'status' => 400 ]
            );
        }
        $existing_store = (int) get_user_meta( $uid, '_wcss_store_id', true );
        if ( $existing_store && get_post_status( $existing_store ) ) {
            $store_title = get_the_title( $existing_store ) ?: "#{$existing_store}";
            return new WP_Error(
                'wcss_user_already_assigned',
                "This user is already assigned to store {$store_title}. Each user can belong to only one store.",
                [ 'status' => 400 ]
            );
        }
        update_user_meta( $uid, '_wcss_store_id', $post_id );
    
        return rest_ensure_response( $this->dto( get_post( $post_id ) ) );
    }

    public function create( WP_REST_Request $req ) {
        // ---- required basics (unchanged) ----
        $name  = trim( (string) $req->get_param('name') );
        $code  = trim( (string) $req->get_param('code') );
        $city  = trim( (string) $req->get_param('city') );
        $state = trim( (string) $req->get_param('state') );
    
        if ( $name === '' || $code === '' ) {
            return new WP_Error('wcss_store_bad_input','Name and Code are required.', [ 'status'=>400 ]);
        }
    
        $quota  = $req->get_param('quota');
        $budget = $req->get_param('budget');
    
        if ($quota !== null && $quota !== '' && (!is_numeric($quota) || (int)$quota < 0)) {
            return new WP_Error('wcss_store_bad_quota','Quota must be a non-negative integer.', [ 'status'=>400 ]);
        }
        if ($budget !== null && $budget !== '' && (!is_numeric($budget) || (float)$budget < 0)) {
            return new WP_Error('wcss_store_bad_budget','Budget must be a non-negative number.', [ 'status'=>400 ]);
        }
    
        // ---- NEW: delivery/contact fields ----
        $address = (string) $req->get_param('address');
        $phone   = (string) $req->get_param('phone');
        $hours   = (string) $req->get_param('open_hours');
    
        // ---- unique code check (unchanged) ----
        $exists = get_posts([
            'post_type'      => 'store_location',
            'post_status'    => 'any',
            'meta_key'       => '_store_code',
            'meta_value'     => $code,
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ]);
        if ( ! empty($exists) ) {
            return new WP_Error('wcss_store_code_exists','A store with this code already exists.', [ 'status'=>409 ]);
        }
    
        // ---- create post ----
        $post_id = wp_insert_post([
            'post_type'   => 'store_location',
            'post_title'  => sanitize_text_field($name),
            'post_status' => 'publish',
        ], true );
        if ( is_wp_error($post_id) ) return $post_id;
    
        // Resolve meta keys from CPT (with safe fallbacks)
        $META_CODE   = defined('WCSS_Store_CPT::META_CODE')   ? WCSS_Store_CPT::META_CODE   : '_store_code';
        $META_CITY   = defined('WCSS_Store_CPT::META_CITY')   ? WCSS_Store_CPT::META_CITY   : '_store_city';
        $META_STATE  = defined('WCSS_Store_CPT::META_STATE')  ? WCSS_Store_CPT::META_STATE  : '_store_state';
        $META_QUOTA  = defined('WCSS_Store_CPT::META_QUOTA')  ? WCSS_Store_CPT::META_QUOTA  : '_store_quota';
        $META_BUDGET = defined('WCSS_Store_CPT::META_BUDGET') ? WCSS_Store_CPT::META_BUDGET : '_store_budget';
        $META_ADDR   = defined('WCSS_Store_CPT::META_ADDR')   ? WCSS_Store_CPT::META_ADDR   : '_wcss_store_address';
        $META_PHONE  = defined('WCSS_Store_CPT::META_PHONE')  ? WCSS_Store_CPT::META_PHONE  : '_wcss_store_phone';
        $META_HOURS  = defined('WCSS_Store_CPT::META_HOURS')  ? WCSS_Store_CPT::META_HOURS  : '_wcss_open_hours';
    
        // base meta
        update_post_meta( $post_id, $META_CODE,  sanitize_text_field($code) );
        update_post_meta( $post_id, $META_CITY,  sanitize_text_field($city) );
        update_post_meta( $post_id, $META_STATE, sanitize_text_field($state) );
        if ($quota  !== null && $quota  !== '') update_post_meta( $post_id, $META_QUOTA,  (int)$quota );
        if ($budget !== null && $budget !== '') update_post_meta( $post_id, $META_BUDGET, wc_format_decimal($budget) );
    
        // NEW fields
        if ($address !== '') update_post_meta( $post_id, $META_ADDR,  wp_kses_post($address) );
        if ($phone   !== '') update_post_meta( $post_id, $META_PHONE, sanitize_text_field($phone) );
        if ($hours   !== '') update_post_meta( $post_id, $META_HOURS, sanitize_textarea_field($hours) );
    
        // ---- user assignment (unchanged) ----
        $uid = (int) $req->get_param('user_id');
        if ( $uid <= 0 ) {
            return new WP_Error('wcss_store_user_required','A Store Employee must be assigned.', [ 'status'=>400 ]);
        }
        $existing_store = (int) get_user_meta($uid, '_wcss_store_id', true);
        if ( $existing_store && get_post_status($existing_store) ) {
            $store_title = get_the_title($existing_store) ?: "#{$existing_store}";
            return new WP_Error('wcss_user_already_assigned',"This user is already assigned to store {$store_title}. Each user can belong to only one store.",[ 'status'=>400 ]);
        }
        update_user_meta( $uid, '_wcss_store_id', $post_id );
    
        return rest_ensure_response( $this->dto( get_post($post_id) ) );
    }

    public function update_old( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $p  = get_post( $id );
        if ( ! $p || $p->post_type !== 'store_location' ) {
            return new WP_Error( 'not_found', 'Store not found', [ 'status' => 404 ] );
        }
    
        $body = $req->get_json_params() ?: [];
    
        // ---------- Required fields (same spirit as create) ----------
        $name    = isset( $body['name'] )    ? sanitize_text_field( $body['name'] ) : '';
        $user_id = isset( $body['user_id'] ) ? (int) $body['user_id'] : 0;
    
        if ( $name === '' ) {
            return new WP_Error( 'wcss_store_name_required', 'Store name is required.', [ 'status' => 400 ] );
        }
        if ( $user_id <= 0 ) {
            return new WP_Error( 'wcss_store_user_required', 'A Store Employee must be assigned.', [ 'status' => 400 ] );
        }
    
        // Optional incoming fields
        $code   = array_key_exists( 'code',  $body ) ? sanitize_text_field( (string) $body['code']  ) : null;
        $city   = array_key_exists( 'city',  $body ) ? sanitize_text_field( (string) $body['city']  ) : null;
        $state  = array_key_exists( 'state', $body ) ? sanitize_text_field( (string) $body['state'] ) : null;
        $quota  = array_key_exists( 'quota', $body ) ? $body['quota']   : null;
        $budget = array_key_exists( 'budget',$body ) ? $body['budget']  : null;
    
        // ---------- Validate numbers if provided ----------
        if ( $quota !== null && $quota !== '' && ( ! is_numeric( $quota ) || (int) $quota < 0 ) ) {
            return new WP_Error( 'wcss_store_bad_quota', 'Quota must be a non-negative integer.', [ 'status' => 400 ] );
        }
        if ( $budget !== null && $budget !== '' && ( ! is_numeric( $budget ) || (float) $budget < 0 ) ) {
            return new WP_Error( 'wcss_store_bad_budget', 'Budget must be a non-negative number.', [ 'status' => 400 ] );
        }
    
        // ---------- If code is changing, ensure uniqueness across BOTH key sets ----------
        if ( $code !== null && $code !== '' ) {
            $dupe = get_posts( [
                'post_type'        => 'store_location',
                'post_status'      => 'any',
                'fields'           => 'ids',
                'posts_per_page'   => 1,
                'no_found_rows'    => true,
                'suppress_filters' => true,
                'exclude'          => [ $id ],
                'meta_query'       => [
                    'relation' => 'OR',
                    [
                        'key'     => WCSS_Store_CPT::META_CODE,   // _wcss_store_code
                        'value'   => $code,
                        'compare' => '=',
                    ],
                    [
                        'key'     => '_store_code',                // legacy
                        'value'   => $code,
                        'compare' => '=',
                    ],
                ],
            ] );
            if ( ! empty( $dupe ) ) {
                return new WP_Error(
                    'wcss_store_code_exists',
                    'A store with this code already exists.',
                    [ 'status' => 409 ]
                );
            }
        }
    
        // ---------- Update core fields ----------
        wp_update_post( [ 'ID' => $id, 'post_title' => $name ] );
    
        // NEW canonical keys (CPT) + legacy keys (dual-write) only when provided
        if ( $code !== null ) {
            update_post_meta( $id, WCSS_Store_CPT::META_CODE, $code );
            update_post_meta( $id, '_store_code', $code );
        }
        if ( $city !== null ) {
            update_post_meta( $id, WCSS_Store_CPT::META_CITY, $city );
            update_post_meta( $id, '_store_city', $city );
        }
        if ( $state !== null ) {
            update_post_meta( $id, WCSS_Store_CPT::META_STATE, $state );
            update_post_meta( $id, '_store_state', $state );
        }
        if ( $quota !== null ) {
            update_post_meta( $id, WCSS_Store_CPT::META_QUOTA, (int) $quota );
            update_post_meta( $id, '_store_quota', (int) $quota );
        }
        if ( $budget !== null ) {
            $b = wc_format_decimal( $budget );
            update_post_meta( $id, WCSS_Store_CPT::META_BUDGET, $b );
            update_post_meta( $id, '_store_budget', $b );
        }
    
        // ---------- Enforce one-store-per-user ----------
        // If this user already has a DIFFERENT store, block.
        $existing_store = (int) get_user_meta( $user_id, '_wcss_store_id', true );
        if ( $existing_store && $existing_store !== $id && get_post_status( $existing_store ) ) {
            $store_title = get_the_title( $existing_store ) ?: "#{$existing_store}";
            return new WP_Error(
                'wcss_user_already_assigned',
                "This user is already assigned to store {$store_title}. Each user can belong to only one store.",
                [ 'status' => 400 ]
            );
        }
    
        // If some *other* user is currently assigned to this store, unassign them.
        $prev_users = get_users( [
            'meta_key'   => '_wcss_store_id',
            'meta_value' => $id,
            'fields'     => [ 'ID' ],
            'number'     => 2, // defensive
        ] );
        foreach ( $prev_users as $u ) {
            if ( (int) $u->ID !== $user_id ) {
                delete_user_meta( $u->ID, '_wcss_store_id' );
            }
        }
    
        // Assign the selected user to this store
        update_user_meta( $user_id, '_wcss_store_id', $id );
    
        return rest_ensure_response( $this->dto( get_post( $id ) ) );
    }

    public function update( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $p  = get_post( $id );
        if ( ! $p || $p->post_type !== 'store_location' ) {
            return new WP_Error('not_found','Store not found',[ 'status'=>404 ]);
        }
    
        $body = $req->get_json_params() ?: [];
    
        $name    = isset($body['name'])    ? sanitize_text_field($body['name'])   : '';
        $user_id = isset($body['user_id']) ? (int)$body['user_id']                : 0;
    
        if ( $name === '' )   return new WP_Error('wcss_store_name_required','Store name is required.',[ 'status'=>400 ]);
        if ( $user_id <= 0 )  return new WP_Error('wcss_store_user_required','A Store Employee must be assigned.',[ 'status'=>400 ]);
    
        wp_update_post([ 'ID'=>$id, 'post_title'=>$name ]);
    
        // Resolve meta keys (same shims as in create)
        $META_CODE   = defined('WCSS_Store_CPT::META_CODE')   ? WCSS_Store_CPT::META_CODE   : '_store_code';
        $META_CITY   = defined('WCSS_Store_CPT::META_CITY')   ? WCSS_Store_CPT::META_CITY   : '_store_city';
        $META_STATE  = defined('WCSS_Store_CPT::META_STATE')  ? WCSS_Store_CPT::META_STATE  : '_store_state';
        $META_QUOTA  = defined('WCSS_Store_CPT::META_QUOTA')  ? WCSS_Store_CPT::META_QUOTA  : '_store_quota';
        $META_BUDGET = defined('WCSS_Store_CPT::META_BUDGET') ? WCSS_Store_CPT::META_BUDGET : '_store_budget';
        $META_ADDR   = defined('WCSS_Store_CPT::META_ADDR')   ? WCSS_Store_CPT::META_ADDR   : '_wcss_store_address';
        $META_PHONE  = defined('WCSS_Store_CPT::META_PHONE')  ? WCSS_Store_CPT::META_PHONE  : '_wcss_store_phone';
        $META_HOURS  = defined('WCSS_Store_CPT::META_HOURS')  ? WCSS_Store_CPT::META_HOURS  : '_wcss_open_hours';
    
        if ( array_key_exists('code',   $body) ) update_post_meta( $id, $META_CODE,   sanitize_text_field((string)$body['code']) );
        if ( array_key_exists('city',   $body) ) update_post_meta( $id, $META_CITY,   sanitize_text_field((string)$body['city']) );
        if ( array_key_exists('state',  $body) ) update_post_meta( $id, $META_STATE,  sanitize_text_field((string)$body['state']) );
        if ( array_key_exists('quota',  $body) ) update_post_meta( $id, $META_QUOTA,  (int)$body['quota'] );
        if ( array_key_exists('budget', $body) ) update_post_meta( $id, $META_BUDGET, wc_format_decimal($body['budget']) );
    
        // NEW fields
        if ( array_key_exists('address',    $body) ) update_post_meta( $id, $META_ADDR,  wp_kses_post((string)$body['address']) );
        if ( array_key_exists('phone',      $body) ) update_post_meta( $id, $META_PHONE, sanitize_text_field((string)$body['phone']) );
        if ( array_key_exists('open_hours', $body) ) update_post_meta( $id, $META_HOURS, sanitize_textarea_field((string)$body['open_hours']) );
    
        // one-store-per-user guard (unchanged)
        $existing_store = (int) get_user_meta( $user_id, '_wcss_store_id', true );
        if ( $existing_store && $existing_store !== $id && get_post_status($existing_store) ) {
            $store_title = get_the_title($existing_store) ?: "#{$existing_store}";
            return new WP_Error('wcss_user_already_assigned',"This user is already assigned to store {$store_title}. Each user can belong to only one store.",[ 'status'=>400 ]);
        }
    
        // unassign other users from this store
        $prev_users = get_users([
            'meta_key'   => '_wcss_store_id',
            'meta_value' => $id,
            'fields'     => [ 'ID' ],
            'number'     => 2,
        ]);
        foreach ( $prev_users as $u ) {
            if ( (int)$u->ID !== $user_id ) delete_user_meta( $u->ID, '_wcss_store_id' );
        }
    
        // assign selected user
        update_user_meta( $user_id, '_wcss_store_id', $id );
    
        return rest_ensure_response( $this->dto( get_post($id) ) );
    }

    public function delete( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $p  = get_post( $id );
        if ( ! $p || $p->post_type !== 'store_location' ) return new WP_Error('not_found','Store not found',[ 'status'=>404 ]);
        wp_delete_post( $id, true );
        return rest_ensure_response([ 'ok'=>true ]);
    }

    public function store_ledger( WP_REST_Request $req ) {
        $store_id = (int) $req['id'];
        $ym = $req->get_param('month') ?: gmdate('Y-m');

        $quota  = (int) get_post_meta( $store_id, '_store_quota',  true );
        $budget = (float) get_post_meta( $store_id, '_store_budget', true );

        $row = class_exists('WCSS_Ledger') ? WCSS_Ledger::get( $store_id, $ym ) : ['orders_count'=>0,'spend_total'=>0];
        $used_count = (int) ($row['orders_count'] ?? 0);
        $used_amt   = (float) ($row['spend_total'] ?? 0);

        return rest_ensure_response([
            'store_id' => $store_id,
            'month'    => $ym,
            'quota'    => $quota,
            'used_orders' => $used_count,
            'remaining_orders' => max(0, $quota - $used_count),
            'budget'   => $budget,
            'used_amount' => $used_amt,
            'remaining_amount' => max(0.0, $budget - $used_amt),
            'currency' => get_woocommerce_currency(),
        ]);
    }

    private function dto_old( WP_Post $p ): array {
        // Prefer CPT canonical keys, fall back to legacy to avoid breaking older code
        $code   = get_post_meta( $p->ID, WCSS_Store_CPT::META_CODE,   true );
        if ( $code === '' )   { $code   = get_post_meta( $p->ID, '_store_code',   true ); }
    
        $city   = get_post_meta( $p->ID, WCSS_Store_CPT::META_CITY,   true );
        if ( $city === '' )   { $city   = get_post_meta( $p->ID, '_store_city',   true ); }
    
        $state  = get_post_meta( $p->ID, WCSS_Store_CPT::META_STATE,  true );
        if ( $state === '' )  { $state  = get_post_meta( $p->ID, '_store_state',  true ); }
    
        $quota  = get_post_meta( $p->ID, WCSS_Store_CPT::META_QUOTA,  true );
        if ( $quota === '' )  { $quota  = get_post_meta( $p->ID, '_store_quota',  true ); }
    
        $budget = get_post_meta( $p->ID, WCSS_Store_CPT::META_BUDGET, true );
        if ( $budget === '' ) { $budget = get_post_meta( $p->ID, '_store_budget', true ); }
    
        // Find the currently assigned user (one per store)
        $assigned_ids = get_users( [
            'meta_key'   => '_wcss_store_id',
            'meta_value' => $p->ID,
            'fields'     => 'ID',
            'number'     => 1,
        ] );
        $user_id = $assigned_ids ? (int) $assigned_ids[0] : 0;
        $user    = $user_id ? get_userdata( $user_id ) : null;
    
        return [
            'id'        => (int) $p->ID,
            'name'      => $p->post_title,
            'code'      => (string) $code,
            'city'      => (string) $city,
            'state'     => (string) $state,
            'quota'     => (int) $quota,
            'budget'    => (float) $budget,
    
            // ✅ Fixed: numeric ID, plus friendly fields used by the UI
            'user_id'    => $user_id,
            'user_name'  => $user ? ( $user->display_name ?: $user->user_login ) : '',
            'user_email' => $user ? $user->user_email : '',
        ];
    }

    private function dto( WP_Post $p ): array {
        // resolve keys
        $META_CODE   = defined('WCSS_Store_CPT::META_CODE')   ? WCSS_Store_CPT::META_CODE   : '_store_code';
        $META_CITY   = defined('WCSS_Store_CPT::META_CITY')   ? WCSS_Store_CPT::META_CITY   : '_store_city';
        $META_STATE  = defined('WCSS_Store_CPT::META_STATE')  ? WCSS_Store_CPT::META_STATE  : '_store_state';
        $META_QUOTA  = defined('WCSS_Store_CPT::META_QUOTA')  ? WCSS_Store_CPT::META_QUOTA  : '_store_quota';
        $META_BUDGET = defined('WCSS_Store_CPT::META_BUDGET') ? WCSS_Store_CPT::META_BUDGET : '_store_budget';
        $META_ADDR   = defined('WCSS_Store_CPT::META_ADDR')   ? WCSS_Store_CPT::META_ADDR   : '_wcss_store_address';
        $META_PHONE  = defined('WCSS_Store_CPT::META_PHONE')  ? WCSS_Store_CPT::META_PHONE  : '_wcss_store_phone';
        $META_HOURS  = defined('WCSS_Store_CPT::META_HOURS')  ? WCSS_Store_CPT::META_HOURS  : '_wcss_open_hours';
    
        $code   = get_post_meta( $p->ID, $META_CODE,   true );
        $city   = get_post_meta( $p->ID, $META_CITY,   true );
        $state  = get_post_meta( $p->ID, $META_STATE,  true );
        $quota  = get_post_meta( $p->ID, $META_QUOTA,  true );
        $budget = get_post_meta( $p->ID, $META_BUDGET, true );
        $addr   = get_post_meta( $p->ID, $META_ADDR,   true );
        $phone  = get_post_meta( $p->ID, $META_PHONE,  true );
        $hours  = get_post_meta( $p->ID, $META_HOURS,  true );
    
        $assigned = get_users([
            'meta_key'   => '_wcss_store_id',
            'meta_value' => $p->ID,
            'fields'     => 'ID',
            'number'     => 1,
        ]);
        $user_id = $assigned ? (int) $assigned[0] : 0;
        $user    = $user_id ? get_userdata( $user_id ) : null;

    
        return [
            'id'        => $p->ID,
            'name'      => $p->post_title,
            'code'      => (string) $code,
            'city'      => (string) $city,
            'state'     => (string) $state,
            'quota'     => (int) $quota,
            'budget'    => (float) $budget,
            // NEW
            'address'   => (string) $addr,
            'phone'     => (string) $phone,
            'open_hours'=> (string) $hours,
            // assignment
            'user_id'   => $user ? (int) $user->ID : 0,
            'user_name'      => $user ? ($user->display_name ?: $user->user_login) : '',
            'user_email'=> $user ? $user->user_email : '',
        ];
    }

    public function validate_user_id_required( $value ): bool {
        if ( ! is_numeric( $value ) ) return false;
        $uid = (int) $value;
        if ( $uid <= 0 ) return false;
    
        $u = get_user_by( 'id', $uid );
        if ( ! $u ) return false;
    
        // must be store_employee
        return in_array( 'store_employee', (array) $u->roles, true );
    }

    public function list_users( WP_REST_Request $req ) {

        $all   = (string) $req->get_param('all') === '1';            // if true => include assigned too
        $search = trim( (string) $req->get_param('search') );
    
        // Only store_employee role
        $args = [
            'role__in' => [ 'store_employee' ],
            'number'   => 500,
            'fields'   => [ 'ID', 'user_email', 'display_name', 'user_login' ],
        ];
        if ( $search !== '' ) {
            // basic search across login/email/display name
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = [ 'user_login', 'user_nicename', 'user_email', 'display_name' ];
        }
    
        $users = get_users( $args );
    
        $out = [];
        foreach ( $users as $u ) {
            $store_id = (int) get_user_meta( $u->ID, '_wcss_store_id', true );
    
            // Old handler skipped assigned users completely — keep that behavior unless all=1
            if ( ! $all && $store_id && get_post_status( $store_id ) ) {
                continue;
            }
    
            // Enrich with store data (if any)
            $store_name = '';
            $store_code = '';
            $store_city = '';
    
            if ( $store_id && get_post_status( $store_id ) ) {
                $store_post = get_post( $store_id );
                if ( $store_post ) {
                    $store_name = $store_post->post_title ?: '';
                    // Use CPT meta keys (don’t break other features)
                    $store_code = get_post_meta( $store_id, WCSS_Store_CPT::META_CODE, true );
                    $store_city = get_post_meta( $store_id, WCSS_Store_CPT::META_CITY, true );
                }
            }
    
            $out[] = [
                'id'         => (int) $u->ID,
                'name'       => $u->display_name ?: $u->user_login,
                'email'      => $u->user_email,
                'store_id'   => $store_id ?: 0,
                'store_name' => $store_name,
                'store_code' => $store_code,
                'store_city' => $store_city,
            ];
        }
    
        return rest_ensure_response( $out );


        // $current_store_id = (int) $req->get_param('store_id'); // optional: include that store's current user
    
        // $users = get_users([
        //     'role__in' => [ 'store_employee' ],
        //     'number'   => 500,
        //     'fields'   => [ 'ID', 'user_email', 'display_name' ],
        // ]);
    
        // $out = [];
        // foreach ( $users as $u ) {
        //     $store_id   = (int) get_user_meta( $u->ID, '_wcss_store_id', true );
        //     $store_name = $store_id ? get_the_title( $store_id ) : '';
    
        //     // Skip users assigned to a DIFFERENT store,
        //     // but allow the one assigned to the store we're editing
        //     if ( $store_id && $store_id !== $current_store_id && get_post_status( $store_id ) ) {
        //         continue;
        //     }
    
        //     $out[] = [
        //         'id'        => (int) $u->ID,
        //         'name'      => $u->display_name,
        //         'email'     => $u->user_email,
        //         'store_id'  => $store_id ?: null,
        //         'store_name'=> $store_name ?: '',
        //         'current'   => ($current_store_id && $store_id === $current_store_id), // helpful flag for UI
        //     ];
        // }
    
        // // (Optional) Put current user first for nicer UX
        // usort( $out, function($a,$b){
        //     return ($b['current'] ?? false) <=> ($a['current'] ?? false);
        // });
    
        // return rest_ensure_response( $out );
    }
    
    public function create_user( WP_REST_Request $req ) {

        $first = trim( (string) $req->get_param('first_name') );
        $last  = trim( (string) $req->get_param('last_name') );
        $email = sanitize_email( (string) $req->get_param('email') );
        $pass  = (string) $req->get_param('password');

        if ( $first === '' || ! $email ) {
            return new WP_Error('wcss_bad_input','First name and valid email are required.', ['status'=>400]);
        }
        if ( email_exists( $email ) ) {
            return new WP_Error('wcss_email_exists','That email is already registered.', ['status'=>409]);
        }

        // username from email local-part; ensure uniqueness
        $base = sanitize_user( current( explode('@', $email) ), true );
        if ( $base === '' ) $base = 'store-emp';
        $login = $base;
        $n = 1;
        while ( username_exists( $login ) ) {
            $login = $base . $n++;
        }

        if ( $pass === '' ) {
            $pass = wp_generate_password( 20, true, true );
        }

        $user_id = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $pass,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim($first.' '.$last),
            'role'         => 'store_employee', // important: only this role
        ]);

        if ( is_wp_error( $user_id ) ) {
            return new WP_Error('wcss_user_create_failed', $user_id->get_error_message(), ['status'=>500]);
        }

        // return in SAME shape as list_users() so the frontend can just append + select
        return rest_ensure_response([
            'id'    => (int) $user_id,
            'name'  => get_the_author_meta('display_name', $user_id),
            'email' => $email,
        ]);
    }

    
}