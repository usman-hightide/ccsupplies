<?php
/**
 * Lightweight self-test for cc-vendor-compare.php (no WordPress bootstrap).
 *
 * Run: php docs/tests/cc-vendor-compare-selftest.php
 */

define( 'ABSPATH', true );

// Minimal WordPress stubs so theme helpers can load without a full bootstrap.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta() { return ''; }
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta() { return true; }
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta() { return true; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can() { return false; }
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce() { return false; }
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) { return $text; }
}
if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
	function woocommerce_wp_text_input() {}
}
if ( ! function_exists( 'woocommerce_wp_select' ) ) {
	function woocommerce_wp_select() {}
}

require dirname( __DIR__, 2 ) . '/app/public/wp-content/themes/adex360-canna-cabana-stationery-child/cc-vendor-compare.php';
require dirname( __DIR__, 2 ) . '/app/public/wp-content/themes/adex360-canna-cabana-stationery-child/cc-vendor-product-admin.php';

$failures = 0;

function assert_true( $cond, $msg ) {
	global $failures;
	if ( ! $cond ) {
		echo "FAIL: $msg\n";
		$failures++;
	} else {
		echo "OK:   $msg\n";
	}
}

$blank = cc_vendor_result_blank( 'amazon' );
assert_true( $blank['vendor'] === 'amazon', 'blank vendor set' );
assert_true( $blank['price'] === null, 'blank price is null (not invented)' );
assert_true( $blank['status'] === 'unavailable', 'blank status unavailable' );

$amazon_ok = cc_vendor_result_from_amazon_product(
	array(
		'asin'  => 'B00TESTASIN',
		'price' => array( 'value' => 12.34, 'currencyCode' => 'CAD' ),
	),
	'B00TESTASIN'
);
assert_true( $amazon_ok['status'] === 'ok', 'amazon ok status' );
assert_true( $amazon_ok['price'] === 12.34, 'amazon price copied' );
assert_true( $amazon_ok['shipping_cost'] === null, 'amazon shipping not invented' );

$amazon_err = cc_vendor_result_from_amazon_product( null, 'B00TESTASIN', 'timeout' );
assert_true( $amazon_err['status'] === 'error', 'amazon error isolated' );

$gt_fail = cc_vendor_result_from_gt_stock(
	array(
		'ok'        => false,
		'http_code' => 403,
		'errors'    => array( 'blocked' ),
	),
	'33111'
);
assert_true( $gt_fail['status'] === 'error', 'gt 403 is error' );
assert_true( strpos( $gt_fail['error'], '403' ) !== false, 'gt 403 message mentions 403' );

$gt_ok = cc_vendor_result_from_gt_stock(
	array(
		'ok'     => true,
		'parsed' => array(
			'lines' => array(
				array(
					'supplier_part_id' => '33111',
					'unit_price'       => '7.99',
					'quantity'         => '10',
					'available_days'   => '2',
					'currency'         => 'CAD',
				),
			),
		),
	),
	'33111'
);
assert_true( $gt_ok['status'] === 'ok', 'gt ok status' );
assert_true( $gt_ok['price'] === 7.99, 'gt price copied' );

$pair = cc_vendor_compare_pair( $amazon_ok, $gt_fail );
assert_true( $pair['amazon']['status'] === 'ok', 'pair keeps amazon ok when gt fails' );
assert_true( $pair['grand_and_toy']['status'] === 'error', 'pair keeps gt error' );

assert_true( wcss_sanitize_amazon_asin_value( ' b00test! ' ) === 'B00TEST', 'asin sanitize' );
assert_true( wcss_sanitize_procurement_eligibility( 'BOTH' ) === 'both', 'eligibility sanitize' );
assert_true( wcss_sanitize_procurement_eligibility( 'nope' ) === 'unset', 'eligibility fallback unset' );

if ( $failures > 0 ) {
	echo "\n$result: $failures failure(s)\n";
	exit( 1 );
}

echo "\nAll self-tests passed.\n";
exit( 0 );
