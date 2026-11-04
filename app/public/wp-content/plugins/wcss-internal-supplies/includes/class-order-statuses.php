<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Order_Statuses {

    public function __construct() {
        add_action( 'init', [ $this, 'register_statuses' ] );
        add_filter( 'wc_order_statuses', [ $this, 'inject_into_list' ] );
    }

    public function register_statuses() {
        register_post_status( 'wc-awaiting-approval', [
            'label'                     => _x( 'Awaiting Approval', 'Order status', 'wcss' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Awaiting Approval <span class="count">(%s)</span>', 'Awaiting Approval <span class="count">(%s)</span>', 'wcss' ),
        ] );

        register_post_status( 'wc-approved', [
            'label'                     => _x( 'Approved', 'Order status', 'wcss' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Approved <span class="count">(%s)</span>', 'Approved <span class="count">(%s)</span>', 'wcss' ),
        ] );

        register_post_status( 'wc-rejected', [
            'label'                     => _x( 'Rejected', 'Order status', 'wcss' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Rejected <span class="count">(%s)</span>', 'Rejected <span class="count">(%s)</span>', 'wcss' ),
        ] );
    }

    public function inject_into_list( $statuses ) {
        // Insert neatly after "on-hold" if present
        $new = [];
        foreach ( $statuses as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'wc-on-hold' === $key ) {
                $new['wc-awaiting-approval'] = _x( 'Awaiting Approval', 'Order status', 'wcss' );
                $new['wc-approved']          = _x( 'Approved', 'Order status', 'wcss' );
                $new['wc-rejected']          = _x( 'Rejected', 'Order status', 'wcss' );
            }
        }
        // Fallback if wc-on-hold not found
        if ( ! isset( $new['wc-awaiting-approval'] ) ) {
            $new['wc-awaiting-approval'] = _x( 'Awaiting Approval', 'Order status', 'wcss' );
            $new['wc-approved']          = _x( 'Approved', 'Order status', 'wcss' );
            $new['wc-rejected']          = _x( 'Rejected', 'Order status', 'wcss' );
        }
        return $new;
    }
}