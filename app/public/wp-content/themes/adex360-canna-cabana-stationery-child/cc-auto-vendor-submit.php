<?php
/**
 * Auto-approve + auto-submit store requisitions to the cheapest vendor.
 *
 * After Internal Requisition creates an order (awaiting-approval), this module:
 * 1) Reads line `_cc_vendor` / `_cc_vendor_id` / `_cc_vendor_price` (cheapest winner).
 * 2) Auto-approves the Woo order.
 * 3) Submits to Grand & Toy (stage cXML) and/or Amazon Business (trial when possible).
 * 4) Stores `_wcss_auto_vendor_submit` plus existing Amazon/G&T snapshots for Manager View.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCSS_AUTO_VENDOR_SUBMIT_META', '_wcss_auto_vendor_submit' );

/**
 * Whether auto-submit is enabled.
 *
 * @return bool
 */
function cc_auto_vendor_submit_enabled() {
	/**
	 * Filter: disable with `add_filter( 'cc_auto_vendor_submit_enabled', '__return_false' );`
	 *
	 * @param bool $enabled Default true.
	 */
	return (bool) apply_filters( 'cc_auto_vendor_submit_enabled', true );
}

/**
 * Load Amazon Business helpers (catalog/cart/checkout) without rendering those page templates.
 *
 * Those files are page templates and normally only load when visiting Amazon pages.
 * Auto-submit runs during Woo order status hooks, so helpers must be bootstrapped here.
 *
 * @return bool True when cart + checkout helpers are available.
 */
function cc_auto_vendor_submit_bootstrap_amazon() {
	static $done = null;
	if ( null !== $done ) {
		return $done;
	}

	$dir = get_stylesheet_directory();

	if ( ! defined( 'WCSS_AMAZON_API_BOOTSTRAP_ONLY' ) ) {
		define( 'WCSS_AMAZON_API_BOOTSTRAP_ONLY', true );
	}
	if ( ! defined( 'AMAZON_CART_ID' ) ) {
		define( 'AMAZON_CART_ID', 'cart-wxXmutF5kiXGRb9J' );
	}
	if ( ! defined( 'AMAZON_CART_REGION' ) ) {
		define( 'AMAZON_CART_REGION', 'CA' );
	}
	if ( ! defined( 'AMAZON_REGION' ) ) {
		define( 'AMAZON_REGION', 'CA' );
	}
	if ( ! defined( 'AMAZON_USER_EMAIL' ) ) {
		define( 'AMAZON_USER_EMAIL', 'ecom@hightideinc.com' );
	}
	if ( ! defined( 'AMAZON_GROUP_ID' ) ) {
		define( 'AMAZON_GROUP_ID', 'OrderingAPI5259590399' );
	}

	// Catalog/API helpers (token, product by ASIN, cart request).
	if ( ! function_exists( 'wp_amazon_business_cart_request' ) ) {
		$api = $dir . '/amazon-apis-integration.php';
		if ( file_exists( $api ) ) {
			require_once $api;
		}
	}

	// Cart helpers / cart id constants.
	if ( ! function_exists( 'wp_amazon_get_cart_items' ) ) {
		$cart = $dir . '/amazon-cart.php';
		if ( file_exists( $cart ) ) {
			require_once $cart;
		}
	}

	// Checkout / Ordering API helpers.
	if ( ! function_exists( 'wp_amazon_checkout_get_cart_items' ) || ! function_exists( 'wp_amazon_ordering_request' ) ) {
		$checkout = $dir . '/amazon-checkout.php';
		if ( file_exists( $checkout ) ) {
			require_once $checkout;
		}
	}

	$done = function_exists( 'wp_amazon_get_access_token' )
		&& function_exists( 'wp_amazon_business_cart_request' )
		&& function_exists( 'wp_amazon_checkout_get_cart_items' )
		&& function_exists( 'wp_amazon_checkout_build_order_payload' )
		&& function_exists( 'wp_amazon_ordering_request' );

	return $done;
}

/**
 * Collect cheapest-vendor lines from a Woo order.
 *
 * @param WC_Order $order Order.
 * @return array{amazon:array<int,array>,gt:array<int,array>,vendors:array<int,string>}
 */
function cc_auto_vendor_submit_collect_lines( $order ) {
	$out = array(
		'amazon'  => array(),
		'gt'      => array(),
		'vendors' => array(),
	);
	if ( ! $order instanceof WC_Order ) {
		return $out;
	}

	foreach ( $order->get_items() as $item_id => $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$vendor = sanitize_key( (string) $item->get_meta( '_cc_vendor', true ) );
		if ( $vendor === 'grand_and_toy' || $vendor === 'grand-toy' ) {
			$vendor = 'gt';
		}
		if ( $vendor !== 'amazon' && $vendor !== 'gt' ) {
			continue;
		}

		$vendor_id = (string) $item->get_meta( '_cc_vendor_id', true );
		if ( $vendor_id === '' && $vendor === 'amazon' ) {
			$pid = (int) $item->get_product_id();
			if ( $pid > 0 ) {
				$vendor_id = (string) get_post_meta( $pid, 'amazon_asin', true );
			}
		}
		if ( $vendor_id === '' && $vendor === 'gt' ) {
			$pid = (int) $item->get_product_id();
			if ( $pid > 0 ) {
				$vendor_id = (string) get_post_meta( $pid, 'wcss_gt_part_id', true );
				if ( $vendor_id === '' && function_exists( 'wc_get_product' ) ) {
					$p = wc_get_product( $pid );
					if ( $p ) {
						$vendor_id = (string) $p->get_sku();
					}
				}
			}
		}

		$price = $item->get_meta( '_cc_vendor_price', true );
		$line  = array(
			'item_id'    => (int) $item_id,
			'product_id' => (int) $item->get_product_id(),
			'name'       => (string) $item->get_name(),
			'qty'        => max( 1, (int) $item->get_quantity() ),
			'vendor'     => $vendor,
			'vendor_id'  => $vendor_id,
			'unit_price' => ( $price !== '' && $price !== null && is_numeric( $price ) ) ? (float) $price : (float) $item->get_total() / max( 1, (int) $item->get_quantity() ),
			'sku'        => '',
		);
		$product = $item->get_product();
		if ( $product instanceof WC_Product ) {
			$line['sku'] = (string) $product->get_sku();
		}

		$out[ $vendor ][] = $line;
	}

	if ( $out['amazon'] ) {
		$out['vendors'][] = 'amazon';
	}
	if ( $out['gt'] ) {
		$out['vendors'][] = 'gt';
	}
	return $out;
}

/**
 * Shipping payload for vendor APIs from Woo order.
 *
 * @param WC_Order $order Order.
 * @return array<string,string>
 */
function cc_auto_vendor_submit_ship_from_order( $order ) {
	$ship = $order->get_address( 'shipping' );
	$bill = $order->get_address( 'billing' );
	$src  = ( ! empty( $ship['address_1'] ) || ! empty( $ship['city'] ) ) ? $ship : $bill;

	$name = trim(
		( isset( $src['first_name'] ) ? (string) $src['first_name'] : '' ) . ' ' .
		( isset( $src['last_name'] ) ? (string) $src['last_name'] : '' )
	);
	if ( $name === '' ) {
		$name = 'Canna Cabana';
	}

	$company = ! empty( $src['company'] ) ? (string) $src['company'] : (string) $order->get_billing_company();
	if ( $company === '' ) {
		$company = 'Canna Cabana';
	}

	return array(
		'name'        => $name,
		'deliver_to'  => $company,
		'streets'     => array_values(
			array_filter(
				array(
					isset( $src['address_1'] ) ? (string) $src['address_1'] : '',
					isset( $src['address_2'] ) ? (string) $src['address_2'] : '',
				)
			)
		),
		'city'        => isset( $src['city'] ) ? (string) $src['city'] : '',
		'state'       => isset( $src['state'] ) ? (string) $src['state'] : '',
		'postal_code' => isset( $src['postcode'] ) ? (string) $src['postcode'] : '',
		'country'     => 'CA',
		'email'       => (string) $order->get_billing_email(),
		'phone'       => (string) $order->get_billing_phone(),
		'company'     => $company,
	);
}

/**
 * Persist auto-submit meta.
 *
 * @param WC_Order             $order Order.
 * @param array<string, mixed> $data  Snapshot.
 */
function cc_auto_vendor_submit_save_meta( $order, array $data ) {
	$existing = $order->get_meta( WCSS_AUTO_VENDOR_SUBMIT_META, true );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}
	$merged = array_merge( $existing, $data );
	$merged['updated_at'] = gmdate( 'c' );
	if ( empty( $merged['created_at'] ) ) {
		$merged['created_at'] = gmdate( 'c' );
	}
	$order->update_meta_data( WCSS_AUTO_VENDOR_SUBMIT_META, $merged );
	$order->save();
}

/**
 * Auto-approve order if still awaiting approval / pending.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function cc_auto_vendor_submit_approve( $order ) {
	$status = $order->get_status();
	if ( in_array( $status, array( 'approved', 'completed', 'rejected', 'cancelled', 'failed' ), true ) ) {
		return $status === 'approved' || $status === 'completed';
	}
	$order->update_status( 'approved', __( 'Auto-approved: cheapest vendor auto-submit.', 'ccsupplies' ), true );
	if ( class_exists( 'WCSS_Order_Events' ) ) {
		WCSS_Order_Events::log(
			$order->get_id(),
			'auto_approved',
			array( 'source' => 'cc_auto_vendor_submit' )
		);
	}
	return true;
}

/**
 * Submit Grand & Toy stage PO from order lines.
 *
 * @param WC_Order             $order Order.
 * @param array<int, array>    $lines GT lines.
 * @param array<string,string> $ship  Ship-to.
 * @return array<string, mixed>
 */
function cc_auto_vendor_submit_gt( $order, array $lines, array $ship ) {
	if ( ! function_exists( 'cc_gt_submit_order' ) || ! function_exists( 'cc_gt_save_order_data' ) ) {
		return array(
			'ok'      => false,
			'status'  => 'failed',
			'message' => 'Grand & Toy cXML client is not loaded.',
		);
	}

	$gt_lines = array();
	$n        = 1;
	foreach ( $lines as $line ) {
		$part = (string) ( $line['vendor_id'] ?? '' );
		if ( $part === '' ) {
			continue;
		}
		$gt_lines[] = array(
			'quantity'         => max( 1, (int) ( $line['qty'] ?? 1 ) ),
			'line_number'      => $n++,
			'supplier_part_id' => $part,
			'unit_price'       => (string) (float) ( $line['unit_price'] ?? 0 ),
			'uom'              => 'EA',
			'description'      => (string) ( $line['name'] ?? '' ),
		);
	}

	if ( ! $gt_lines ) {
		return array(
			'ok'      => false,
			'status'  => 'failed',
			'message' => 'No Grand & Toy part IDs on order lines.',
		);
	}

	$sender = defined( 'CC_GT_STOCK_SENDER' ) ? (string) CC_GT_STOCK_SENDER : '588';
	if ( $sender === '' ) {
		$sender = '588';
	}

	$po_id   = 'AUTO' . $order->get_id() . 'T' . gmdate( 'ymdHis' );
	$payload = array(
		'sender_identity'  => $sender,
		'order_id'         => $po_id,
		'backorder_method' => 'BO',
		'ship_to'          => array(
			'name'        => $ship['name'],
			'deliver_to'  => $ship['deliver_to'],
			'streets'     => $ship['streets'],
			'city'        => $ship['city'],
			'state'       => $ship['state'],
			'postal_code' => $ship['postal_code'],
			'country'     => 'CA',
		),
		'bill_to'          => array(
			'name'        => $ship['name'],
			'deliver_to'  => $ship['deliver_to'],
			'streets'     => $ship['streets'],
			'city'        => $ship['city'],
			'state'       => $ship['state'],
			'postal_code' => $ship['postal_code'],
			'country'     => 'CA',
		),
		'lines'            => $gt_lines,
	);

	$result   = cc_gt_submit_order( $payload );
	$ok       = ! empty( $result['ok'] );
	$snapshot = array(
		'status'       => $ok ? 'submitted' : 'failed',
		'source'       => 'cc_auto_vendor_submit',
		'po_id'        => $po_id,
		'sender'       => $sender,
		'backorder'    => 'BO',
		'cart_items'   => $lines,
		'ship_to'      => $payload['ship_to'],
		'gt_result'    => array(
			'ok'        => $ok,
			'http_code' => isset( $result['http_code'] ) ? (int) $result['http_code'] : 0,
			'errors'    => (array) ( $result['errors'] ?? array() ),
			'parsed'    => $result['parsed'] ?? array(),
		),
		'wc_order_id'  => $order->get_id(),
		'submitted_at' => gmdate( 'c' ),
		'stage_only'   => true,
		'auto'         => true,
	);
	cc_gt_save_order_data( $order->get_id(), $snapshot );

	$errors = array_filter( array_map( 'strval', (array) ( $result['errors'] ?? array() ) ) );
	return array(
		'ok'       => $ok,
		'status'   => $snapshot['status'],
		'po_id'    => $po_id,
		'message'  => $ok ? 'Grand & Toy stage PO submitted.' : ( $errors ? implode( '; ', $errors ) : 'Grand & Toy PO failed.' ),
		'snapshot' => $snapshot,
	);
}

/**
 * Resolve Amazon buying option for an ASIN.
 *
 * @param string $asin ASIN.
 * @return array{asin:string,buying_option_id:string,title:string,price:float|null}|WP_Error
 */
function cc_auto_vendor_submit_amazon_resolve_offer( $asin ) {
	$asin = strtoupper( sanitize_text_field( (string) $asin ) );
	if ( $asin === '' || ! function_exists( 'wp_amazon_business_get_product_by_asin' ) ) {
		return new WP_Error( 'amazon_asin', 'Amazon product lookup unavailable.' );
	}

	$product = wp_amazon_business_get_product_by_asin( $asin );
	if ( is_wp_error( $product ) ) {
		return $product;
	}
	if ( ! is_array( $product ) ) {
		return new WP_Error( 'amazon_asin', 'Amazon returned no product for ASIN ' . $asin );
	}

	// Catalog API often wraps the item as { products: [ {...} ] }.
	if ( ! empty( $product['products'][0] ) && is_array( $product['products'][0] ) ) {
		$product = $product['products'][0];
	}

	$buying_option_id = '';
	if ( function_exists( 'wp_amazon_find_nested_value' ) ) {
		$buying_option_id = (string) wp_amazon_find_nested_value( $product, 'buyingOptionIdentifier' );
		if ( $buying_option_id === '' ) {
			$buying_option_id = (string) wp_amazon_find_nested_value( $product, 'offerId' );
		}
	}

	$offer = array();
	if ( ! empty( $product['includedDataTypes']['OFFERS'][0] ) && is_array( $product['includedDataTypes']['OFFERS'][0] ) ) {
		$offer = $product['includedDataTypes']['OFFERS'][0];
	} elseif ( ! empty( $product['offers'][0] ) && is_array( $product['offers'][0] ) ) {
		$offer = $product['offers'][0];
	}

	if ( $buying_option_id === '' && ! empty( $offer['offerId'] ) ) {
		$buying_option_id = (string) $offer['offerId'];
	}
	if ( $buying_option_id === '' && ! empty( $offer['buyingOptionIdentifier'] ) ) {
		$buying_option_id = (string) $offer['buyingOptionIdentifier'];
	}
	if ( $buying_option_id === '' ) {
		return new WP_Error( 'amazon_offer', 'No Amazon buying option for ASIN ' . $asin );
	}

	$title = '';
	if ( ! empty( $product['title'] ) ) {
		$title = (string) $product['title'];
	} elseif ( ! empty( $product['productOverview']['title'] ) ) {
		$title = (string) $product['productOverview']['title'];
	} elseif ( function_exists( 'wp_amazon_find_nested_value' ) ) {
		$title = (string) wp_amazon_find_nested_value( $product, 'title' );
	}

	$price = null;
	if ( ! empty( $offer['price']['value'] ) && is_numeric( $offer['price']['value'] ) ) {
		$price = (float) $offer['price']['value'];
	} elseif ( ! empty( $offer['taxExclusivePrice']['price'] ) && is_numeric( $offer['taxExclusivePrice']['price'] ) ) {
		$price = (float) $offer['taxExclusivePrice']['price'];
	} elseif ( function_exists( 'wp_amazon_find_nested_value' ) ) {
		$raw = wp_amazon_find_nested_value( $product, 'price' );
		if ( is_array( $raw ) && isset( $raw['value'] ) && is_numeric( $raw['value'] ) ) {
			$price = (float) $raw['value'];
		} elseif ( is_numeric( $raw ) ) {
			$price = (float) $raw;
		}
	}

	return array(
		'asin'             => $asin,
		'buying_option_id' => $buying_option_id,
		'title'            => $title,
		'price'            => $price,
	);
}

/**
 * Add Amazon lines to the Business cart.
 *
 * @param array<int, array> $lines Order lines.
 * @return array{ok:bool,message:string,added:array<int,array>,errors:array<int,string>}
 */
function cc_auto_vendor_submit_amazon_fill_cart( array $lines ) {
	$added  = array();
	$errors = array();

	if ( ! cc_auto_vendor_submit_bootstrap_amazon() ) {
		return array(
			'ok'      => false,
			'message' => 'Amazon cart API helpers are not loaded.',
			'added'   => array(),
			'errors'  => array( 'Amazon cart API helpers are not loaded.' ),
		);
	}

	$token = wp_amazon_get_access_token();
	if ( is_wp_error( $token ) ) {
		return array(
			'ok'      => false,
			'message' => $token->get_error_message(),
			'added'   => array(),
			'errors'  => array( $token->get_error_message() ),
		);
	}

	$cart_id = function_exists( 'wp_amazon_business_get_cart_id' )
		? wp_amazon_business_get_cart_id( $token )
		: ( defined( 'AMAZON_CART_ID' ) ? AMAZON_CART_ID : '' );
	if ( is_wp_error( $cart_id ) ) {
		return array(
			'ok'      => false,
			'message' => $cart_id->get_error_message(),
			'added'   => array(),
			'errors'  => array( $cart_id->get_error_message() ),
		);
	}
	if ( ! $cart_id ) {
		return array(
			'ok'      => false,
			'message' => 'Amazon cart id is not configured.',
			'added'   => array(),
			'errors'  => array( 'Amazon cart id is not configured.' ),
		);
	}

	$url = add_query_arg(
		array( 'region' => defined( 'AMAZON_REGION' ) ? AMAZON_REGION : 'CA' ),
		'https://na.business-api.amazon.com/cart/2025-04-30/carts/' . rawurlencode( (string) $cart_id ) . '/items'
	);

	foreach ( $lines as $line ) {
		$asin = (string) ( $line['vendor_id'] ?? '' );
		if ( $asin === '' ) {
			$errors[] = 'Missing ASIN for line ' . ( $line['name'] ?? '' );
			continue;
		}
		$offer = cc_auto_vendor_submit_amazon_resolve_offer( $asin );
		if ( is_wp_error( $offer ) ) {
			$errors[] = $offer->get_error_message();
			continue;
		}

		$body = array(
			'items' => array(
				array(
					'productIdentifier'      => $offer['asin'],
					'buyingOptionIdentifier' => $offer['buying_option_id'],
					'quantity'               => max( 1, (int) ( $line['qty'] ?? 1 ) ),
					'externalId'             => 'cc-auto-' . wp_generate_uuid4(),
				),
			),
		);

		$result = wp_amazon_business_cart_request( 'POST', $url, $token, $body );
		if ( is_wp_error( $result ) ) {
			$errors[] = $result->get_error_message();
			continue;
		}
		if ( ! empty( $result['rejectedItems'] ) ) {
			$errors[] = 'Amazon rejected ASIN ' . $asin;
			continue;
		}

		$added[] = array_merge(
			$line,
			array(
				'asin'             => $offer['asin'],
				'buying_option_id' => $offer['buying_option_id'],
				'title'            => $offer['title'],
				'amazon_price'     => $offer['price'],
			)
		);
	}

	return array(
		'ok'      => ! empty( $added ) && empty( $errors ),
		'message' => empty( $errors )
			? ( count( $added ) . ' Amazon item(s) added to cart.' )
			: implode( '; ', $errors ),
		'added'   => $added,
		'errors'  => $errors,
	);
}

/**
 * Place Amazon order from current Business cart using store shipping (trial mode).
 *
 * @param WC_Order             $order Order.
 * @param array<string,string> $ship  Ship fields.
 * @param array<int, array>    $added Lines that were added.
 * @return array<string, mixed>
 */
function cc_auto_vendor_submit_amazon_place( $order, array $ship, array $added ) {
	if ( ! cc_auto_vendor_submit_bootstrap_amazon() ) {
		return array(
			'ok'      => false,
			'status'  => 'failed',
			'message' => 'Amazon checkout helpers are not loaded.',
		);
	}

	$cart_result = wp_amazon_checkout_get_cart_items();
	if ( empty( $cart_result['success'] ) ) {
		return array(
			'ok'      => false,
			'status'  => 'failed',
			'message' => (string) ( $cart_result['message'] ?? 'Unable to read Amazon cart.' ),
		);
	}
	if ( empty( $cart_result['items'] ) ) {
		return array(
			'ok'      => false,
			'status'  => 'failed',
			'message' => 'Amazon cart is empty after auto-add.',
		);
	}

	// Buyer email must be the Amazon Business authorized user (not the store employee).
	$amazon_buyer_email = defined( 'AMAZON_USER_EMAIL' ) ? (string) AMAZON_USER_EMAIL : 'ecom@hightideinc.com';

	$form_data = array(
		'full_name'             => $ship['name'] !== '' ? $ship['name'] : 'Canna Cabana',
		'email'                 => $amazon_buyer_email,
		'phone'                 => $ship['phone'] !== '' ? $ship['phone'] : '0000000000',
		'company_name'          => $ship['company'] !== '' ? $ship['company'] : 'Canna Cabana',
		'address_line1'         => isset( $ship['streets'][0] ) ? $ship['streets'][0] : '',
		'address_line2'         => isset( $ship['streets'][1] ) ? $ship['streets'][1] : '',
		'city'                  => $ship['city'],
		'state'                 => function_exists( 'wcss_amazon_checkout_normalize_province' )
			? wcss_amazon_checkout_normalize_province( $ship['state'] )
			: $ship['state'],
		'postal_code'           => $ship['postal_code'],
		'purchase_order_number' => (string) $order->get_order_number(),
		'group_identifier'      => defined( 'AMAZON_GROUP_ID' ) ? (string) AMAZON_GROUP_ID : '',
		'trial_mode'            => true,
	);

	$required = array( 'full_name', 'email', 'address_line1', 'city', 'state', 'postal_code', 'group_identifier' );
	foreach ( $required as $key ) {
		if ( empty( $form_data[ $key ] ) ) {
			return array(
				'ok'      => false,
				'status'  => 'failed',
				'message' => 'Missing Amazon shipping field: ' . $key,
			);
		}
	}

	$payload = wp_amazon_checkout_build_order_payload( $cart_result['items'], $form_data );
	if ( empty( $payload['lineItems'] ) ) {
		return array(
			'ok'      => false,
			'status'  => 'failed',
			'message' => 'No valid Amazon line items for Ordering API.',
		);
	}

	// Force trial attribute when helper supports it via form_data already set.
	$result = wp_amazon_ordering_request(
		'POST',
		'https://na.business-api.amazon.com/ordering/2022-10-30/orders',
		$payload
	);

	$ok = ! empty( $result['success'] );
	$normalized_items = function_exists( 'wcss_amazon_normalize_cart_items' )
		? wcss_amazon_normalize_cart_items( $cart_result['items'] )
		: $cart_result['items'];

	$fail_message = 'Amazon place order failed.';
	if ( ! $ok ) {
		if ( ! empty( $result['message'] ) ) {
			$fail_message = (string) $result['message'];
		} elseif ( ! empty( $result['data']['errors'][0]['message'] ) ) {
			$fail_message = (string) $result['data']['errors'][0]['message'];
			if ( ! empty( $result['data']['errors'][0]['details'] ) ) {
				$fail_message .= ' ' . (string) $result['data']['errors'][0]['details'];
			}
		} elseif ( ! empty( $result['status_code'] ) ) {
			$fail_message = 'Amazon Ordering API HTTP ' . (int) $result['status_code'];
		}
	}

	$snapshot = array(
		'status'          => $ok ? 'placed' : 'place_failed',
		'source'          => 'cc_auto_vendor_submit',
		'auto'            => true,
		'trial_mode'      => true,
		'cart_id'         => defined( 'AMAZON_CART_ID' ) ? AMAZON_CART_ID : '',
		'region'          => defined( 'AMAZON_REGION' ) ? AMAZON_REGION : 'CA',
		'shipping'        => array_merge( $form_data, array( 'country' => 'CA' ) ),
		'cart_items'      => $normalized_items,
		'intended_lines'  => $added,
		'order_payload'   => $payload,
		'amazon_response' => $result,
		'message'         => $ok ? 'Amazon order placed (trial mode).' : $fail_message,
		'placed_at'       => current_time( 'mysql' ),
	);

	if ( function_exists( 'wcss_amazon_save_order_snapshot' ) ) {
		wcss_amazon_save_order_snapshot( $order->get_id(), $snapshot );
	} else {
		$order->update_meta_data( '_wcss_amazon_order_data', $snapshot );
		$order->save();
	}

	return array(
		'ok'       => $ok,
		'status'   => $snapshot['status'],
		'message'  => $snapshot['message'],
		'snapshot' => $snapshot,
	);
}

/**
 * Run auto-submit for one order (idempotent).
 *
 * @param int $order_id Order ID.
 * @return array<string, mixed>|null
 */
function cc_auto_vendor_submit_run( $order_id ) {
	if ( ! cc_auto_vendor_submit_enabled() ) {
		return null;
	}

	$order_id = (int) $order_id;
	$order    = wc_get_order( $order_id );
	if ( ! $order ) {
		return null;
	}

	$existing = $order->get_meta( WCSS_AUTO_VENDOR_SUBMIT_META, true );
	// Allow retry when a previous attempt failed (e.g. helpers not loaded).
	if ( is_array( $existing ) && ! empty( $existing['attempted'] ) ) {
		$prev_status = (string) ( $existing['status'] ?? '' );
		$force       = (bool) apply_filters( 'cc_auto_vendor_submit_force_retry', false, $order_id, $existing );
		if ( ! $force && ! in_array( $prev_status, array( 'failed', 'processing' ), true ) ) {
			return $existing;
		}
	}

	// Lock immediately to avoid double fire from status hooks.
	cc_auto_vendor_submit_save_meta(
		$order,
		array(
			'attempted'    => true,
			'attempted_at' => gmdate( 'c' ),
			'status'       => 'processing',
			'message'      => 'Auto-submit in progress…',
		)
	);
	$order = wc_get_order( $order_id );

	$collected = cc_auto_vendor_submit_collect_lines( $order );
	$vendors   = $collected['vendors'];

	if ( ! $vendors ) {
		$summary = array(
			'attempted'       => true,
			'status'          => 'skipped',
			'winning_vendor'  => '',
			'vendors'         => array(),
			'message'         => 'No Amazon/G&T cheapest vendor tags on line items — left for Ops.',
			'results'         => array(),
		);
		cc_auto_vendor_submit_save_meta( $order, $summary );
		return $summary;
	}

	cc_auto_vendor_submit_approve( $order );
	$order = wc_get_order( $order_id );
	$ship  = cc_auto_vendor_submit_ship_from_order( $order );

	$results = array();
	$ok_any  = false;
	$fail    = false;

	if ( in_array( 'gt', $vendors, true ) ) {
		$gt = cc_auto_vendor_submit_gt( $order, $collected['gt'], $ship );
		$results['gt'] = $gt;
		if ( ! empty( $gt['ok'] ) ) {
			$ok_any = true;
		} else {
			$fail = true;
		}
	}

	if ( in_array( 'amazon', $vendors, true ) ) {
		$fill = cc_auto_vendor_submit_amazon_fill_cart( $collected['amazon'] );
		if ( empty( $fill['added'] ) ) {
			$results['amazon'] = array(
				'ok'      => false,
				'status'  => 'failed',
				'message' => $fill['message'],
				'errors'  => $fill['errors'],
			);
			$fail = true;
		} else {
			$place = cc_auto_vendor_submit_amazon_place( $order, $ship, $fill['added'] );
			$place['cart_fill'] = $fill;
			$results['amazon']  = $place;
			if ( ! empty( $place['ok'] ) ) {
				$ok_any = true;
			} else {
				$fail = true;
			}
		}
	}

	$winning = count( $vendors ) === 1 ? $vendors[0] : 'mixed';
	$status  = 'failed';
	if ( $ok_any && ! $fail ) {
		$status = 'submitted';
	} elseif ( $ok_any && $fail ) {
		$status = 'partial';
	}

	$messages = array();
	foreach ( $results as $vendor_key => $res ) {
		$messages[] = strtoupper( $vendor_key ) . ': ' . ( $res['message'] ?? '' );
	}

	$summary = array(
		'attempted'      => true,
		'status'         => $status,
		'winning_vendor' => $winning,
		'vendors'        => $vendors,
		'message'        => implode( ' | ', $messages ),
		'results'        => $results,
		'ship_to'        => $ship,
		'completed_at'   => gmdate( 'c' ),
	);
	cc_auto_vendor_submit_save_meta( $order, $summary );

	if ( class_exists( 'WCSS_Order_Events' ) ) {
		WCSS_Order_Events::log(
			$order_id,
			'auto_vendor_submit',
			array(
				'status'  => $status,
				'vendors' => $vendors,
				'message' => $summary['message'],
			)
		);
	}

	return $summary;
}

/**
 * Trigger after Internal Requisition lands on awaiting-approval
 * (runs after gateway process_payment — safe to auto-approve from here).
 *
 * @param int $order_id Order ID.
 */
function cc_auto_vendor_submit_on_awaiting_approval( $order_id ) {
	$order_id = (int) $order_id;
	if ( $order_id <= 0 ) {
		return;
	}
	// Prefer async so checkout response stays fast; fall back to sync.
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		$already = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( 'cc_auto_vendor_submit_async', array( 'order_id' => $order_id ), 'cc-auto-vendor' )
			: false;
		if ( ! $already ) {
			as_enqueue_async_action( 'cc_auto_vendor_submit_async', array( 'order_id' => $order_id ), 'cc-auto-vendor' );
		}
		return;
	}
	cc_auto_vendor_submit_run( $order_id );
}
add_action( 'woocommerce_order_status_awaiting-approval', 'cc_auto_vendor_submit_on_awaiting_approval', 30, 1 );

/**
 * Async runner (Action Scheduler).
 *
 * @param int $order_id Order ID.
 */
function cc_auto_vendor_submit_async_cb( $order_id ) {
	cc_auto_vendor_submit_run( (int) $order_id );
}
add_action( 'cc_auto_vendor_submit_async', 'cc_auto_vendor_submit_async_cb', 10, 1 );
