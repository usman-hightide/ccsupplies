<?php
/**
 * Supply Manager → Returns (SOW 1.24): the queue of return requests and the decision on each.
 * Stores submit requests from My Account (WCSS_Returns_Account), not through this API.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_REST_Returns {
    const NS = 'wcss/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
    }

    public function can_manage(): bool {
        return current_user_can( 'wcss_manage_portal' ) || current_user_can( 'manage_options' );
    }

    public function routes() {
        register_rest_route( self::NS, '/returns', [
            'methods'             => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'list' ],
        ] );
        register_rest_route( self::NS, '/returns/(?P<id>\d+)', [
            'methods'             => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'one' ],
        ] );
        register_rest_route( self::NS, '/returns/(?P<id>\d+)/decision', [
            'methods'             => 'POST',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'decide' ],
        ] );
    }

    public function list( WP_REST_Request $req ) {
        $status = sanitize_key( (string) ( $req->get_param( 'status' ) ?: 'all' ) );
        $items  = WCSS_Returns::query( [ 'status' => $status, 'store' => (int) $req->get_param( 'store' ) ] );
        $counts = [ 'open' => 0 ];
        foreach ( array_keys( WCSS_Returns::STATUSES ) as $s ) $counts[ $s ] = 0;
        foreach ( WCSS_Returns::query( [ 'status' => 'all' ] ) as $r ) {
            $counts[ $r['status'] ]++;
            if ( in_array( $r['status'], [ 'submitted', 'approved' ], true ) ) $counts['open']++;
        }
        return rest_ensure_response( [
            'items'    => $items,
            'counts'   => $counts,
            'statuses' => array_map( function ( $s ) { return $s[0]; }, WCSS_Returns::STATUSES ),
            'reasons'  => array_map( function ( $r ) { return $r[0]; }, WCSS_Returns::REASONS ),
        ] );
    }

    public function one( WP_REST_Request $req ) {
        $r = WCSS_Returns::get( (int) $req['id'] );
        return $r ? rest_ensure_response( $r ) : new WP_Error( 'not_found', 'Return request not found.', [ 'status' => 404 ] );
    }

    public function decide( WP_REST_Request $req ) {
        $res = WCSS_Returns::decide(
            (int) $req['id'],
            (string) $req->get_param( 'status' ),
            (string) $req->get_param( 'note' ),
            get_current_user_id()
        );
        return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
    }
}
