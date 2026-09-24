<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Status policy:
 * - Force new orders to be "pending"
 * - Hide unwanted statuses from admin status lists & dropdowns
 */
class WCSS_Status_Policy {

    /**
     * Which statuses should be visible/selectable in admin.
     * Keep "pending" + our custom approval statuses (and optionally "cancelled").
     */
    private $visible_statuses = [
        'wc-pending'            => true,
        'wc-awaiting-approval'  => true,
        'wc-approved'           => true,
        'wc-rejected'           => true,
        // 'wc-cancelled'        => true, // uncomment if you want to allow cancel
    ];

    public function __construct() {
        // 1) Force brand-new orders to be PENDING
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'force_pending_on_checkout' ], 5, 3 );

        // If any gateway tries to set "processing" on payment complete, force back to pending
        add_filter( 'woocommerce_payment_complete_order_status', [ $this, 'force_pending_on_payment_complete' ], 10, 3 );

        // 2) Hide unwanted statuses from lists/dropdowns
        add_filter( 'wc_order_statuses', [ $this, 'filter_admin_status_list' ], 999 );
    }

    /**
     * When checkout creates the order, make sure it’s pending (not processing/on-hold).
     */
    public function force_pending_on_checkout( $order_id, $posted_data, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) { return; }

        // Only override if not already our custom statuses
        $cur = $order->get_status(); // e.g., 'pending', 'processing'
        if ( $cur !== 'pending' && $cur !== 'awaiting-approval' && $cur !== 'approved' && $cur !== 'rejected' ) {
            $order->set_status( 'pending' );
            $order->save();
            $order->add_order_note( __( 'WCSS: Forced initial status to Pending.', 'wcss' ) );
        }
    }

    /**
     * Some gateways set "processing" automatically on payment complete;
     * force them to "pending" so the approval flow can take over.
     */
    public function force_pending_on_payment_complete( $status, $order_id, $order ) {
        return 'pending';
    }

    /**
     * Hide non-approved statuses from admin dropdowns/lists.
     */
    public function filter_admin_status_list( $statuses ) {
        $filtered = [];
        foreach ( $statuses as $slug => $label ) {
            if ( isset( $this->visible_statuses[ $slug ] ) ) {
                $filtered[ $slug ] = $label;
            }
        }

        // Ensure our three core statuses exist even if other plugins reorder things
        if ( ! isset( $filtered['wc-pending'] ) )            $filtered['wc-pending']           = _x( 'Pending For Approval', 'Order status', 'woocommerce' );
        if ( ! isset( $filtered['wc-awaiting-approval'] ) )  $filtered['wc-awaiting-approval'] = _x( 'Awaiting Approval', 'Order status', 'wcss' );
        if ( ! isset( $filtered['wc-approved'] ) )           $filtered['wc-approved']          = _x( 'Approved', 'Order status', 'wcss' );
        if ( ! isset( $filtered['wc-rejected'] ) )           $filtered['wc-rejected']          = _x( 'Rejected', 'Order status', 'wcss' );

        return $filtered;
    }
}