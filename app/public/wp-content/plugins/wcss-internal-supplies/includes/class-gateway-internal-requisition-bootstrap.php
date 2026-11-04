<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCSS_Gateway_Internal_Requisition_Bootstrap {

    const GATEWAY_ID = 'wcss_internal_requisition';

    public function __construct() {
        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );
        add_filter( 'woocommerce_available_payment_gateways', [ $this, 'restrict_gateways_for_employees' ], 20 );
    }

    public function register_gateway( $methods ) {
        $methods[] = 'WC_Gateway_WCSS_Internal_Requisition';
        return $methods;
    }

    public function restrict_gateways_for_employees( $gateways ) {
        // Allow admins to see all gateways
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
            return $gateways;
        }

        $bypass = wcss_get_option( 'bypass_method', 'internal_requisition' );
        if ( $bypass !== 'internal_requisition' ) {
            return $gateways;
        }

        foreach ( $gateways as $key => $gw ) {
            if ( $key !== self::GATEWAY_ID ) {
                unset( $gateways[ $key ] );
            }
        }
        return $gateways;
    }
}