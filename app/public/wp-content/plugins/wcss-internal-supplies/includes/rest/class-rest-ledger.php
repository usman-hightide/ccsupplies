<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_REST_Ledger {
    const NS = 'wcss/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'routes' ] );
        add_action( 'rest_api_init', function () {
            if ( function_exists('wc_get_logger') ) {
                wc_get_logger()->info('Registering WCSS_REST_Ledger routes', ['source'=>'wcss-ledger']);
            } else {
                error_log('[WCSS] Registering WCSS_REST_Ledger routes');
            }
        }, 9 );
    }


    // public function __construct(){ add_action( 'rest_api_init', [ $this, 'routes' ] ); }
    public function can_manage( $r=null ): bool {
        return current_user_can('wcss_manage_portal') || current_user_can('manage_options');
    }
    public function routes() {
        register_rest_route( self::NS, '/stores/(?P<id>\d+)/ledger', [
            'methods'             => 'GET',
            'permission_callback' => [ $this, 'can_manage' ],
            'callback'            => [ $this, 'store_ledger' ],
            'args'                => [
                'month' => [ 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ]);
    }

    public function store_ledger( WP_REST_Request $req ) {
        $store_id = (int) $req['id'];
        $ym = $req->get_param('month') ?: gmdate('Y-m');

        // Get store limits from CPT meta
        $quota  = (int) get_post_meta( $store_id, '_store_quota',  true );
        $budget = (float) get_post_meta( $store_id, '_store_budget', true );

        $row = WCSS_Ledger::get( $store_id, $ym );
        $used_count = (int) $row['orders_count'];
        $used_amt   = (float) $row['spend_total'];

        return rest_ensure_response([
            'store_id'     => $store_id,
            'month'        => $ym,
            'quota'        => $quota,
            'used_orders'  => $used_count,
            'remaining_orders' => max(0, $quota - $used_count),
            'budget'       => $budget,
            'used_amount'  => $used_amt,
            'remaining_amount' => max(0.0, $budget - $used_amt),
            'currency'     => get_woocommerce_currency(),
        ]);
    }


}