<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Approval_Workflow {

    public function __construct() {
        // Row action buttons in Orders list
        add_filter( 'woocommerce_admin_order_actions', [ $this, 'add_row_actions' ], 10, 2 );
        add_action( 'admin_action_wcss_approve_order', [ $this, 'handle_row_approve' ] );
        add_action( 'admin_action_wcss_reject_order',  [ $this, 'handle_row_reject' ] );

        // Actions dropdown in single order screen
        add_filter( 'woocommerce_order_actions', [ $this, 'add_order_actions_dropdown' ] );
        add_action( 'woocommerce_order_action_wcss_approve', [ $this, 'handle_dropdown_approve' ] );
        add_action( 'woocommerce_order_action_wcss_reject',  [ $this, 'handle_dropdown_reject' ] );
    }

    /** -----------------------------
     * Orders list – buttons
     * ------------------------------*/
    public function add_row_actions( $actions, $order ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return $actions;
        }

        $status = $order->get_status(); // slug without "wc-"
        $order_id = $order->get_id();

        // Show Approve button unless already approved
        if ( $status !== 'approved' ) {
            $url = wp_nonce_url(
                add_query_arg( [
                    'action'   => 'wcss_approve_order',
                    'order_id' => $order_id,
                ], admin_url( 'edit.php?post_type=shop_order' ) ),
                'wcss_approve_' . $order_id
            );
            $actions['wcss_approve'] = [
                'url'    => $url,
                'name'   => __( 'Approve', 'wcss' ),
                'action' => 'wcss-approve', // CSS class
            ];
        }

        // Show Reject button unless already rejected
        if ( $status !== 'rejected' ) {
            $url = wp_nonce_url(
                add_query_arg( [
                    'action'   => 'wcss_reject_order',
                    'order_id' => $order_id,
                ], admin_url( 'edit.php?post_type=shop_order' ) ),
                'wcss_reject_' . $order_id
            );
            $actions['wcss_reject'] = [
                'url'    => $url,
                'name'   => __( 'Reject', 'wcss' ),
                'action' => 'wcss-reject', // CSS class
            ];
        }

        return $actions;
    }

    public function handle_row_approve() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die(); }
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        check_admin_referer( 'wcss_approve_' . $order_id );
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_status( 'wc-approved', __( 'Approved by admin (row action).', 'wcss' ), true );
            }
        }
        wp_safe_redirect( remove_query_arg( [ 'action', 'order_id', '_wpnonce' ] ) );
        exit;
    }

    public function handle_row_reject() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die(); }
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        check_admin_referer( 'wcss_reject_' . $order_id );
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_status( 'wc-rejected', __( 'Rejected by admin (row action).', 'wcss' ), true );
            }
        }
        wp_safe_redirect( remove_query_arg( [ 'action', 'order_id', '_wpnonce' ] ) );
        exit;
    }

    /** -----------------------------
     * Single order screen – dropdown
     * ------------------------------*/
    public function add_order_actions_dropdown( $actions ) {
        if ( current_user_can( 'manage_woocommerce' ) ) {
            $actions['wcss_approve'] = __( 'Approve (WCSS)', 'wcss' );
            $actions['wcss_reject']  = __( 'Reject (WCSS)', 'wcss' );
        }
        return $actions;
    }

    public function handle_dropdown_approve( $order ) {
        if ( ! $order instanceof WC_Order ) { return; }
        $order->update_status( 'wc-approved', __( 'Approved by admin (order screen).', 'wcss' ), true );
    }

    public function handle_dropdown_reject( $order ) {
        if ( ! $order instanceof WC_Order ) { return; }
        $order->update_status( 'wc-rejected', __( 'Rejected by admin (order screen).', 'wcss' ), true );
    }
}