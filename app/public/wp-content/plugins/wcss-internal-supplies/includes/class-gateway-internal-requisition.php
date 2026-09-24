<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The no-payment gateway itself.
 */
class WC_Gateway_WCSS_Internal_Requisition extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'wcss_internal_requisition';
        $this->method_title       = __( 'Internal Requisition (No Payment)', 'wcss' );
        $this->method_description = __( 'Creates a requisition order without capturing payment. Orders are left Pending for approval.', 'wcss' );
        $this->has_fields         = false;
        $this->supports           = [ 'products' ];
        $this->title              = __( 'Internal Requisition', 'wcss' );

        $this->init_form_fields();
        $this->init_settings();

        // Admin settings
        $this->enabled      = $this->get_option( 'enabled', 'yes' );
        $this->title        = $this->get_option( 'title', $this->title );
        $this->description  = $this->get_option( 'description', __( 'Submit your requisition for approval. No payment required.', 'wcss' ) );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Enable/Disable', 'wcss' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Internal Requisition', 'wcss' ),
                'default' => 'yes',
            ],
            'title' => [
                'title'       => __( 'Title', 'wcss' ),
                'type'        => 'text',
                'default'     => __( 'Internal Requisition', 'wcss' ),
                'desc_tip'    => true,
                'description' => __( 'Shown to users on the checkout payment methods list.', 'wcss' ),
            ],
            'description' => [
                'title'       => __( 'Description', 'wcss' ),
                'type'        => 'textarea',
                'default'     => __( 'Submit your requisition for approval. No payment required.', 'wcss' ),
                'description' => __( 'Shown under the method title during checkout.', 'wcss' ),
            ],
        ];
    }

    public function process_payment( $order_id ) {

        $logger = wc_get_logger();
        $logger->info(
            'WCSS Gateway: process_payment started for order ' . $order_id,
            [ 'source' => 'wcss-gateway' ]
        );
    

    // Friendly guard: add notices + return failure (no exceptions)
        if ( class_exists( 'WCSS_Order_Quota' ) ) {
            $violations = WCSS_Order_Quota::get_violations_messages();
            if ( ! empty( $violations ) ) {
                foreach ( $violations as $msg ) {
                    wc_add_notice( $msg, 'error' );   // shown to user
                }
                return [ 'result' => 'failure' ];     // prevents the generic banner
            }
        }

        $order = wc_get_order( $order_id );

        if ( $order && $order->get_status() !== 'awaiting-approval' ) {
            $order->set_status( 'awaiting-approval' );
        }

        if ( $order ) {
            $user_id  = get_current_user_id();
            $username = $user_id ? wp_get_current_user()->user_login : 'guest';
            $order->add_order_note( sprintf(
                __( 'Requisition submitted by %1$s via "%2$s". Awaiting approval.', 'wcss' ),
                esc_html( $username ),
                esc_html( $this->get_title() )
            ) );
            $order->save();
        }

        wc_reduce_stock_levels( $order_id );
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        ];
    }
}