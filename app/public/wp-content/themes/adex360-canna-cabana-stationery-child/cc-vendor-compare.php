<?php
/**
 * Normalized vendor comparison result structure (read-only foundation).
 *
 * Does not place orders. One vendor failure must not erase the other vendor's row.
 * Fields are null/empty when the vendor does not provide them — never invent values.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blank normalized vendor result.
 *
 * @param string $vendor Vendor key: amazon|grand_and_toy.
 * @return array<string, mixed>
 */
function cc_vendor_result_blank( $vendor = '' ) {
	return array(
		'vendor'                 => (string) $vendor,
		'product_identifier'     => '',
		'equivalence_status'     => 'unknown', // exact|similar|unknown|unavailable|error
		'unit_quantity'          => null,
		'pack_quantity'          => null,
		'price'                  => null,
		'currency'               => '',
		'availability_quantity'  => null,
		'shipping_cost'          => null,
		'estimated_delivery'     => '',
		'status'                 => 'unavailable', // ok|unavailable|error
		'error'                  => '',
		'retrieved_at'           => gmdate( 'c' ),
	);
}

/**
 * Merge known fields into a blank result without inventing missing ones.
 *
 * @param string               $vendor Vendor key.
 * @param array<string, mixed> $fields Partial fields.
 * @return array<string, mixed>
 */
function cc_vendor_result_from( $vendor, array $fields ) {
	$base = cc_vendor_result_blank( $vendor );
	$allowed = array_keys( $base );
	foreach ( $fields as $key => $value ) {
		if ( ! in_array( $key, $allowed, true ) ) {
			continue;
		}
		if ( $value === null && in_array( $key, array( 'unit_quantity', 'pack_quantity', 'price', 'availability_quantity', 'shipping_cost' ), true ) ) {
			$base[ $key ] = null;
			continue;
		}
		$base[ $key ] = $value;
	}
	if ( empty( $base['retrieved_at'] ) ) {
		$base['retrieved_at'] = gmdate( 'c' );
	}
	return $base;
}

/**
 * Map a Grand & Toy stock-check result into a normalized row.
 * Does not invent price/qty when parse failed.
 *
 * @param array<string, mixed> $gt_result Output of cc_gt_stock_check().
 * @param string               $part_id   Requested SupplierPartID.
 * @return array<string, mixed>
 */
function cc_vendor_result_from_gt_stock( array $gt_result, $part_id = '' ) {
	$part_id = trim( (string) $part_id );
	$errors  = array_filter( array_map( 'strval', (array) ( $gt_result['errors'] ?? array() ) ) );

	if ( empty( $gt_result['ok'] ) ) {
		$msg = $errors ? implode( '; ', $errors ) : 'Grand & Toy stock check failed.';
		$http = isset( $gt_result['http_code'] ) ? (int) $gt_result['http_code'] : 0;
		if ( $http === 403 ) {
			$msg = 'Grand & Toy returned HTTP 403 (likely Incapsula / IP allowlist). ' . $msg;
		}
		return cc_vendor_result_from(
			'grand_and_toy',
			array(
				'product_identifier' => $part_id,
				'equivalence_status' => 'error',
				'status'             => 'error',
				'error'              => $msg,
			)
		);
	}

	// Stock parse stores rows on parsed.items (positions nested). Accept lines for tests/fixtures.
	$lines = (array) ( $gt_result['parsed']['items'] ?? $gt_result['parsed']['lines'] ?? array() );
	$match = null;
	foreach ( $lines as $line ) {
		$line_part = trim( (string) ( $line['supplier_part_id'] ?? $line['SupplierPartID'] ?? '' ) );
		if ( $part_id === '' || strcasecmp( $line_part, $part_id ) === 0 ) {
			$match = $line;
			if ( $part_id !== '' ) {
				break;
			}
		}
	}

	if ( ! is_array( $match ) ) {
		return cc_vendor_result_from(
			'grand_and_toy',
			array(
				'product_identifier' => $part_id,
				'equivalence_status' => 'unavailable',
				'status'             => 'unavailable',
				'error'              => 'No matching line in PriceAvailabilityResponse.',
			)
		);
	}

	$price = null;
	if ( isset( $match['unit_price'] ) && $match['unit_price'] !== '' && is_numeric( $match['unit_price'] ) ) {
		$price = (float) $match['unit_price'];
	} elseif ( isset( $match['price'] ) && $match['price'] !== '' && is_numeric( $match['price'] ) ) {
		$price = (float) $match['price'];
	}

	$avail = null;
	$eta   = '';
	$positions = (array) ( $match['positions'] ?? array() );
	if ( $positions ) {
		$pos = $positions[0];
		if ( isset( $pos['quantity'] ) && $pos['quantity'] !== '' && is_numeric( $pos['quantity'] ) ) {
			$avail = (float) $pos['quantity'];
		}
		$eta = (string) ( $pos['available_days'] ?? '' );
	}
	if ( $avail === null && isset( $match['quantity'] ) && $match['quantity'] !== '' && is_numeric( $match['quantity'] ) ) {
		$avail = (float) $match['quantity'];
	} elseif ( $avail === null && isset( $match['available_quantity'] ) && is_numeric( $match['available_quantity'] ) ) {
		$avail = (float) $match['available_quantity'];
	}
	if ( $eta === '' ) {
		$eta = (string) ( $match['available_days'] ?? $match['estimated_delivery'] ?? '' );
	}

	$currency = (string) ( $match['currency'] ?? $match['money_currency'] ?? '' );

	return cc_vendor_result_from(
		'grand_and_toy',
		array(
			'product_identifier'    => $part_id !== '' ? $part_id : (string) ( $match['supplier_part_id'] ?? '' ),
			'equivalence_status'    => 'exact',
			'price'                 => $price,
			'currency'              => $currency !== '' ? $currency : 'CAD',
			'availability_quantity' => $avail,
			'estimated_delivery'    => $eta,
			'status'                => 'ok',
			'error'                 => '',
		)
	);
}

/**
 * Map an Amazon Product Search ASIN hit into a normalized row.
 * Only copies fields present on the product array — does not invent shipping/ETA.
 *
 * @param array<string, mixed>|null $product Amazon product payload (or null).
 * @param string                    $asin    Requested ASIN.
 * @param string                    $error   Optional error message when lookup failed.
 * @return array<string, mixed>
 */
function cc_vendor_result_from_amazon_product( $product, $asin = '', $error = '' ) {
	$asin = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $asin ) );

	if ( $error !== '' ) {
		return cc_vendor_result_from(
			'amazon',
			array(
				'product_identifier' => $asin,
				'equivalence_status' => 'error',
				'status'             => 'error',
				'error'              => (string) $error,
			)
		);
	}

	if ( ! is_array( $product ) || empty( $product ) ) {
		return cc_vendor_result_from(
			'amazon',
			array(
				'product_identifier' => $asin,
				'equivalence_status' => 'unavailable',
				'status'             => 'unavailable',
				'error'              => 'No Amazon product returned for this ASIN.',
			)
		);
	}

	$price = null;
	if ( isset( $product['price']['value'] ) && is_numeric( $product['price']['value'] ) ) {
		$price = (float) $product['price']['value'];
	} elseif ( isset( $product['price'] ) && is_numeric( $product['price'] ) ) {
		$price = (float) $product['price'];
	}

	$currency = '';
	if ( ! empty( $product['price']['currencyCode'] ) ) {
		$currency = (string) $product['price']['currencyCode'];
	} elseif ( ! empty( $product['currency'] ) ) {
		$currency = (string) $product['currency'];
	}

	$id = $asin;
	if ( $id === '' && ! empty( $product['asin'] ) ) {
		$id = strtoupper( (string) $product['asin'] );
	}

	return cc_vendor_result_from(
		'amazon',
		array(
			'product_identifier' => $id,
			'equivalence_status' => 'exact',
			'price'              => $price,
			'currency'           => $currency,
			'status'             => 'ok',
			'error'              => '',
			// shipping_cost / estimated_delivery left empty — Product Search does not reliably provide them here.
		)
	);
}

/**
 * Pair Amazon + Grand & Toy results for one WooCommerce product comparison.
 * Failures stay isolated per vendor key.
 *
 * @param array<string, mixed>|null $amazon_result Normalized or null.
 * @param array<string, mixed>|null $gt_result     Normalized or null.
 * @return array{amazon: array<string, mixed>, grand_and_toy: array<string, mixed>}
 */
function cc_vendor_compare_pair( $amazon_result, $gt_result ) {
	return array(
		'amazon'        => is_array( $amazon_result )
			? $amazon_result
			: cc_vendor_result_blank( 'amazon' ),
		'grand_and_toy' => is_array( $gt_result )
			? $gt_result
			: cc_vendor_result_blank( 'grand_and_toy' ),
	);
}

/**
 * Ensure Amazon Business helpers are available without rendering the catalog page.
 */
function cc_vendor_bootstrap_amazon_api() {
	if ( function_exists( 'wp_amazon_business_get_product_by_asin' ) ) {
		return true;
	}
	$path = get_stylesheet_directory() . '/amazon-apis-integration.php';
	if ( ! file_exists( $path ) ) {
		return false;
	}
	if ( ! defined( 'WCSS_AMAZON_API_BOOTSTRAP_ONLY' ) ) {
		define( 'WCSS_AMAZON_API_BOOTSTRAP_ONLY', true );
	}
	require_once $path;
	return function_exists( 'wp_amazon_business_get_product_by_asin' );
}

/**
 * G&T SupplierPartID candidate for a product (SKU until Ops confirms a separate map).
 *
 * @param int $product_id Product ID.
 * @return string
 */
function cc_vendor_gt_part_for_product( $product_id ) {
	$product_id = (int) $product_id;
	$override   = trim( (string) get_post_meta( $product_id, 'wcss_gt_part_id', true ) );
	if ( $override !== '' ) {
		return $override;
	}
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
	if ( ! $product ) {
		return '';
	}
	return trim( (string) $product->get_sku() );
}

/**
 * Development-only G&T stock fixture (never used for purchase orders).
 *
 * @param string $part_id Part ID.
 * @return array<string, mixed>
 */
function cc_vendor_gt_dev_fixture_stock( $part_id ) {
	$part_id = trim( (string) $part_id );
	return array(
		'ok'        => true,
		'http_code' => 200,
		'fixture'   => true,
		'errors'    => array(),
		'parsed'    => array(
			'has_price_availability_response' => true,
			'items'                           => array(
				array(
					'supplier_part_id' => $part_id !== '' ? $part_id : 'FIXTURE-PART',
					'unit_price'       => '7.99',
					'currency'         => 'CAD',
					'positions'        => array(
						array(
							'facility_id'    => 'V5J4Z3',
							'available'      => 'Y',
							'quantity'       => '25',
							'available_days' => '2',
						),
					),
				),
			),
		),
	);
}

/**
 * Fetch Amazon normalized row for a Woo product.
 *
 * @param int $product_id Product ID.
 * @return array<string, mixed>
 */
function cc_vendor_fetch_amazon_for_product( $product_id ) {
	$product_id = (int) $product_id;
	$asin       = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) get_post_meta( $product_id, 'amazon_asin', true ) ) );

	if ( $asin === '' ) {
		return cc_vendor_result_from(
			'amazon',
			array(
				'equivalence_status' => 'unavailable',
				'status'             => 'unavailable',
				'error'              => 'No Amazon ASIN linked to this product.',
			)
		);
	}

	if ( function_exists( 'wcss_product_amazon_procurement_allowed' )
		&& ! wcss_product_amazon_procurement_allowed( $product_id )
	) {
		return cc_vendor_result_from(
			'amazon',
			array(
				'product_identifier' => $asin,
				'equivalence_status' => 'unavailable',
				'status'             => 'unavailable',
				'error'              => 'Vendor eligibility excludes Amazon for this product.',
			)
		);
	}

	if ( ! cc_vendor_bootstrap_amazon_api() ) {
		return cc_vendor_result_from(
			'amazon',
			array(
				'product_identifier' => $asin,
				'equivalence_status' => 'error',
				'status'             => 'error',
				'error'              => 'Amazon API helpers could not be loaded.',
			)
		);
	}

	$result = wp_amazon_business_get_product_by_asin( $asin );
	if ( is_wp_error( $result ) ) {
		return cc_vendor_result_from_amazon_product( null, $asin, $result->get_error_message() );
	}

	$raw = ( ! empty( $result['products'][0] ) && is_array( $result['products'][0] ) )
		? $result['products'][0]
		: null;

	if ( ! $raw ) {
		return cc_vendor_result_from_amazon_product( null, $asin, 'No Amazon product returned for this ASIN.' );
	}

	$offer = array();
	if ( ! empty( $raw['includedDataTypes']['OFFERS'][0] ) && is_array( $raw['includedDataTypes']['OFFERS'][0] ) ) {
		$offer = $raw['includedDataTypes']['OFFERS'][0];
	}

	$flat = array(
		'asin'     => isset( $raw['asin'] ) ? (string) $raw['asin'] : $asin,
		'price'    => null,
		'currency' => '',
	);
	if ( isset( $offer['price']['value']['amount'] ) && is_numeric( $offer['price']['value']['amount'] ) ) {
		$flat['price'] = array(
			'value'        => (float) $offer['price']['value']['amount'],
			'currencyCode' => isset( $offer['price']['value']['currencyCode'] )
				? (string) $offer['price']['value']['currencyCode']
				: 'CAD',
		);
	}

	$row = cc_vendor_result_from_amazon_product( $flat, $asin );
	if ( ! empty( $offer['availability'] ) ) {
		// Availability text is not a numeric qty — leave availability_quantity null; surface in error-free note via estimated_delivery only if days-like.
		$row['estimated_delivery'] = '';
	}
	$row['title'] = isset( $raw['title'] ) ? (string) $raw['title'] : '';
	$row['availability_text'] = isset( $offer['availability'] ) ? (string) $offer['availability'] : '';
	return $row;
}

/**
 * Fetch Grand & Toy normalized row for a Woo product.
 *
 * @param int  $product_id Product ID.
 * @param bool $use_fixture Force development fixture (no live POST).
 * @return array<string, mixed>
 */
function cc_vendor_fetch_gt_for_product( $product_id, $use_fixture = false ) {
	$product_id = (int) $product_id;

	if ( function_exists( 'wcss_product_gt_procurement_allowed' )
		&& ! wcss_product_gt_procurement_allowed( $product_id )
	) {
		return cc_vendor_result_from(
			'grand_and_toy',
			array(
				'equivalence_status' => 'unavailable',
				'status'             => 'unavailable',
				'error'              => 'Vendor eligibility excludes Grand & Toy for this product.',
			)
		);
	}

	$part = cc_vendor_gt_part_for_product( $product_id );
	if ( $part === '' ) {
		return cc_vendor_result_from(
			'grand_and_toy',
			array(
				'equivalence_status' => 'unavailable',
				'status'             => 'unavailable',
				'error'              => 'No Grand & Toy part ID (product has no SKU / wcss_gt_part_id).',
			)
		);
	}

	if ( $use_fixture ) {
		$row = cc_vendor_result_from_gt_stock( cc_vendor_gt_dev_fixture_stock( $part ), $part );
		$row['fixture'] = true;
		$row['error']   = $row['error'] !== ''
			? $row['error']
			: 'DEV FIXTURE — not live Grand & Toy data. Do not use for purchasing.';
		return $row;
	}

	if ( ! function_exists( 'cc_gt_stock_check' ) ) {
		return cc_vendor_result_from(
			'grand_and_toy',
			array(
				'product_identifier' => $part,
				'equivalence_status' => 'error',
				'status'             => 'error',
				'error'              => 'Grand & Toy cXML client is not loaded.',
			)
		);
	}

	$live = cc_gt_stock_check(
		array(
			array(
				'supplier_part_id' => $part,
				'quantity'         => 1,
				'line_number'      => 1,
			),
		),
		array()
	);

	$row = cc_vendor_result_from_gt_stock( $live, $part );
	$row['fixture'] = false;
	return $row;
}

/**
 * Full read-only comparison payload for one Woo product.
 *
 * @param int                $product_id Product ID.
 * @param array<string,mixed> $args       Optional. use_gt_fixture (bool).
 * @return array<string, mixed>
 */
function cc_vendor_compare_for_product( $product_id, $args = array() ) {
	$product_id = (int) $product_id;
	$use_fixture = ! empty( $args['use_gt_fixture'] );

	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
	if ( ! $product ) {
		return array(
			'ok'      => false,
			'error'   => 'Product not found.',
			'product' => null,
			'pair'    => cc_vendor_compare_pair( null, null ),
		);
	}

	$amazon = cc_vendor_fetch_amazon_for_product( $product_id );
	$gt     = cc_vendor_fetch_gt_for_product( $product_id, $use_fixture );

	$choice = (string) get_post_meta( $product_id, 'wcss_manual_vendor_choice', true );
	if ( ! in_array( $choice, array( '', 'amazon', 'gt', 'none' ), true ) ) {
		$choice = '';
	}

	return array(
		'ok'      => true,
		'error'   => '',
		'product' => array(
			'id'           => $product_id,
			'name'         => $product->get_name(),
			'sku'          => (string) $product->get_sku(),
			'asin'         => strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) get_post_meta( $product_id, 'amazon_asin', true ) ) ),
			'gt_part'      => cc_vendor_gt_part_for_product( $product_id ),
			'eligibility'  => function_exists( 'wcss_get_procurement_eligibility' )
				? wcss_get_procurement_eligibility( $product_id )
				: 'unset',
			'regular_price'=> $product->get_regular_price(),
			'manual_choice'=> $choice,
		),
		'pair'    => cc_vendor_compare_pair( $amazon, $gt ),
		'notes'   => array(
			'Read-only comparison. Saving a vendor choice does not place an order.',
			'Grand & Toy part defaults to Store SKU until Ops confirms a separate part map.',
			$use_fixture ? 'G&T row is a development fixture, not live stage data.' : 'G&T row attempted live stage stock check (no PO).',
		),
	);
}
