<?php
/**
 * WooCommerce Product Data fields for Amazon ASIN + procurement eligibility.
 *
 * Preserves existing post meta key `amazon_asin`.
 * Eligibility meta `wcss_procurement_eligibility` defaults to unset (current behaviour).
 * Missing ASIN does not invalidate the product or block Grand & Toy eligibility.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowed procurement eligibility values.
 *
 * @return string[]
 */
function wcss_procurement_eligibility_values() {
	return array( 'unset', 'amazon', 'gt', 'both' );
}

/**
 * Normalize ASIN for storage (A-Z0-9, uppercase). Empty string clears the meta.
 *
 * @param string $asin Raw ASIN.
 * @return string
 */
function wcss_sanitize_amazon_asin_value( $asin ) {
	$asin = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $asin ) );
	if ( $asin !== '' && strlen( $asin ) > 16 ) {
		$asin = substr( $asin, 0, 16 );
	}
	return $asin;
}

/**
 * Sanitize eligibility select value.
 *
 * @param string $value Raw.
 * @return string One of wcss_procurement_eligibility_values().
 */
function wcss_sanitize_procurement_eligibility( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$allowed = wcss_procurement_eligibility_values();
	return in_array( $value, $allowed, true ) ? $value : 'unset';
}

/**
 * Read procurement eligibility; missing meta = unset.
 *
 * @param int $product_id Product ID.
 * @return string
 */
function wcss_get_procurement_eligibility( $product_id ) {
	$product_id = (int) $product_id;
	if ( $product_id <= 0 ) {
		return 'unset';
	}
	$raw = get_post_meta( $product_id, 'wcss_procurement_eligibility', true );
	if ( $raw === '' || $raw === false || $raw === null ) {
		return 'unset';
	}
	return wcss_sanitize_procurement_eligibility( (string) $raw );
}

/**
 * Whether Amazon procurement is allowed for this product (eligibility only).
 * Does not require an ASIN — search still needs ASIN at runtime.
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function wcss_product_amazon_procurement_allowed( $product_id ) {
	$el = wcss_get_procurement_eligibility( $product_id );
	return in_array( $el, array( 'unset', 'amazon', 'both' ), true );
}

/**
 * Whether Grand & Toy procurement is allowed (eligibility only).
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function wcss_product_gt_procurement_allowed( $product_id ) {
	$el = wcss_get_procurement_eligibility( $product_id );
	return in_array( $el, array( 'unset', 'gt', 'both' ), true );
}

/**
 * Product Data → General: Amazon ASIN + eligibility.
 */
function wcss_render_product_vendor_fields() {
	global $post;

	if ( ! $post || ! current_user_can( 'edit_product', $post->ID ) ) {
		return;
	}

	$asin = (string) get_post_meta( $post->ID, 'amazon_asin', true );
	$eligibility = wcss_get_procurement_eligibility( $post->ID );

	echo '<div class="options_group show_if_simple show_if_variable">';

	woocommerce_wp_text_input(
		array(
			'id'          => 'wcss_amazon_asin_field',
			'label'       => __( 'Amazon ASIN', 'adex360-canna-cabana-stationery-child' ),
			'description' => __( 'Optional. Used for Amazon Business Product Search. Leave blank if this product is Grand & Toy only or not yet mapped. Does not remove the product from the store catalog.', 'adex360-canna-cabana-stationery-child' ),
			'desc_tip'    => true,
			'value'       => $asin,
		)
	);

	woocommerce_wp_select(
		array(
			'id'          => 'wcss_procurement_eligibility_field',
			'label'       => __( 'Vendor eligibility', 'adex360-canna-cabana-stationery-child' ),
			'description' => __( 'Unset keeps current behaviour (Amazon when ASIN is set; Grand & Toy remains allowed). Does not auto-place orders.', 'adex360-canna-cabana-stationery-child' ),
			'desc_tip'    => true,
			'options'     => array(
				'unset'  => __( 'Unset (default)', 'adex360-canna-cabana-stationery-child' ),
				'amazon' => __( 'Amazon', 'adex360-canna-cabana-stationery-child' ),
				'gt'     => __( 'Grand & Toy only', 'adex360-canna-cabana-stationery-child' ),
				'both'   => __( 'Both Amazon and Grand & Toy', 'adex360-canna-cabana-stationery-child' ),
			),
			'value'       => $eligibility,
		)
	);

	echo '</div>';
}
add_action( 'woocommerce_product_options_general_product_data', 'wcss_render_product_vendor_fields' );

/**
 * Save Product Data vendor fields. Uses WooCommerce product save nonce.
 *
 * @param int $post_id Product ID.
 */
function wcss_save_product_vendor_fields( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! current_user_can( 'edit_product', $post_id ) ) {
		return;
	}

	// WooCommerce product editor nonce (same as core product fields).
	if ( empty( $_POST['woocommerce_meta_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' )
	) {
		return;
	}

	if ( isset( $_POST['wcss_amazon_asin_field'] ) ) {
		$asin = wcss_sanitize_amazon_asin_value( wp_unslash( $_POST['wcss_amazon_asin_field'] ) );
		if ( $asin === '' ) {
			delete_post_meta( $post_id, 'amazon_asin' );
		} else {
			update_post_meta( $post_id, 'amazon_asin', $asin );
		}
	}

	if ( isset( $_POST['wcss_procurement_eligibility_field'] ) ) {
		$eligibility = wcss_sanitize_procurement_eligibility(
			wp_unslash( $_POST['wcss_procurement_eligibility_field'] )
		);
		if ( $eligibility === 'unset' ) {
			// Keep DB clean: unset = default behaviour, no meta required.
			delete_post_meta( $post_id, 'wcss_procurement_eligibility' );
		} else {
			update_post_meta( $post_id, 'wcss_procurement_eligibility', $eligibility );
		}
	}
}
add_action( 'woocommerce_process_product_meta', 'wcss_save_product_vendor_fields' );
