<?php
/**
 * AJAX for read-only vendor comparison UI.
 * Does not place Amazon or Grand & Toy orders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability check for compare tools.
 *
 * @return bool
 */
function cc_vendor_compare_user_can() {
	return is_user_logged_in()
		&& ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) );
}

/**
 * Run comparison for one product.
 */
function cc_vendor_compare_ajax_run() {
	if ( ! cc_vendor_compare_user_can() ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}
	check_ajax_referer( 'cc_vendor_compare', 'nonce' );

	$product_id  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$use_fixture = ! empty( $_POST['use_gt_fixture'] );

	if ( $product_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Product ID is required.' ), 400 );
	}

	$payload = cc_vendor_compare_for_product(
		$product_id,
		array( 'use_gt_fixture' => $use_fixture )
	);

	if ( empty( $payload['ok'] ) ) {
		wp_send_json_error( array( 'message' => $payload['error'] ?: 'Compare failed.' ), 404 );
	}

	wp_send_json_success( $payload );
}
add_action( 'wp_ajax_cc_vendor_compare_run', 'cc_vendor_compare_ajax_run' );

/**
 * Save manual vendor selection only (no purchase).
 */
function cc_vendor_compare_ajax_save_choice() {
	if ( ! cc_vendor_compare_user_can() ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}
	check_ajax_referer( 'cc_vendor_compare', 'nonce' );

	$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$choice     = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';

	if ( $product_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'Product ID is required.' ), 400 );
	}
	if ( ! in_array( $choice, array( 'amazon', 'gt', 'none' ), true ) ) {
		wp_send_json_error( array( 'message' => 'Choice must be amazon, gt, or none.' ), 400 );
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		wp_send_json_error( array( 'message' => 'Product not found.' ), 404 );
	}

	if ( $choice === 'none' ) {
		delete_post_meta( $product_id, 'wcss_manual_vendor_choice' );
	} else {
		update_post_meta( $product_id, 'wcss_manual_vendor_choice', $choice );
	}

	wp_send_json_success(
		array(
			'product_id' => $product_id,
			'choice'     => $choice === 'none' ? '' : $choice,
			'message'    => 'Manual vendor choice saved. No order was placed.',
		)
	);
}
add_action( 'wp_ajax_cc_vendor_compare_save_choice', 'cc_vendor_compare_ajax_save_choice' );
