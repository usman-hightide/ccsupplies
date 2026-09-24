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
            'args' => [
                'name'    => [ 'required'=>true, 'sanitize_callback'=>'sanitize_text_field' ],
                'phone'   => [ 'required'=>false, 'sanitize_callback'=>'sanitize_text_field' ],
                'email'   => [ 'required'=>false, 'sanitize_callback'=>'sanitize_email' ],
                'address' => [ 'required'=>false, 'sanitize_callback'=>'sanitize_textarea_field' ],
            ],
        ]);

        register_rest_route( self::NS, '/vendors/(?P<id>\d+)', [
            'methods' => 'PUT',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'update' ],
        ]);

        register_rest_route( self::NS, '/vendors/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback' => [ $this, 'delete' ],
        ]);
    }



    private function dto( WP_Term $t ): array {
        return [
            'id'      => (int) $t->term_id,
            'name'    => $t->name,
            'slug'    => $t->slug,
            'phone'   => (string) get_term_meta( $t->term_id, 'wcss_vendor_phone', true ),
            'email'   => (string) get_term_meta( $t->term_id, 'wcss_vendor_email', true ),
            'address' => (string) get_term_meta( $t->term_id, 'wcss_vendor_address', true ),
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

    public function create( WP_REST_Request $req ) {
        // print_r($req->get_params());
        // die("creating vendor...");
        $name = trim( (string) $req->get_param('name' ) );
        $email = trim( (string) $req->get_param('email') );
        $phone = trim( (string) $req->get_param('phone') );
        $address = trim( (string) $req->get_param('address') );

        $allparam = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $address
        ];

        if ( $name === '' ) return new WP_Error('bad_name','Name required',[ 'status'=>400 ]);

        $exists = term_exists( $name, 'wcss_vendor' );
        if ( $exists && ! is_wp_error($exists) ) {
            $t = get_term( (int) $exists['term_id'] );
            if ( $t && ! is_wp_error($t) ) {
                $this->save_meta_from_request( $t->term_id, $allparam );
                return rest_ensure_response( $this->dto( $t ) );
            }
        }
        $res = wp_insert_term( $name, 'wcss_vendor' );
        if ( is_wp_error( $res ) ) return $res;

        $term_id = (int) $res['term_id'];
        $this->save_meta_from_request( $term_id, $allparam );
        $t = get_term( $term_id );
        return rest_ensure_response( $this->dto( $t ) );
    }

    public function update( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $t = get_term( $id, 'wcss_vendor' );
        if ( ! $t || is_wp_error($t) ) return new WP_Error('not_found','Vendor not found',[ 'status'=>404 ]);

        $data = $req->get_json_params() ?: [];
        if ( isset($data['name']) && trim($data['name']) !== '' ) {
            $ren = wp_update_term( $id, 'wcss_vendor', [ 'name' => sanitize_text_field($data['name']) ] );
            if ( is_wp_error($ren) ) return $ren;
        }
        $this->save_meta_array( $id, $data );
        $t = get_term( $id, 'wcss_vendor' );
        return rest_ensure_response( $this->dto( $t ) );
    }

    public function delete( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $del = wp_delete_term( $id, 'wcss_vendor' );
        if ( is_wp_error( $del ) ) return $del;
        return rest_ensure_response([ 'ok' => true ]);
    }

    private function save_meta_from_request( int $term_id, array $req ): void {
        $phone = (string) $req['phone'];
        $email = (string) $req['email'];
        $addr  = (string) $req['address'];

        if ( $phone !== '' ) update_term_meta( $term_id, 'wcss_vendor_phone', sanitize_text_field( $phone ) );
        if ( $email !== '' ) update_term_meta( $term_id, 'wcss_vendor_email', sanitize_email( $email ) );
        if ( $addr  !== '' ) update_term_meta( $term_id, 'wcss_vendor_address', sanitize_textarea_field( $addr ) );
    }

    private function save_meta_array( int $term_id, array $data ): void {
        if ( array_key_exists('phone', $data) )   update_term_meta( $term_id, 'wcss_vendor_phone',   sanitize_text_field((string)$data['phone']) );
        if ( array_key_exists('email', $data) )   update_term_meta( $term_id, 'wcss_vendor_email',   sanitize_email((string)$data['email']) );
        if ( array_key_exists('address', $data) ) update_term_meta( $term_id, 'wcss_vendor_address', sanitize_textarea_field((string)$data['address']) );
    }
}