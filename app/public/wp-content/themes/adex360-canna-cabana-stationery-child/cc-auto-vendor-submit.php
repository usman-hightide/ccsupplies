<?php
/**
 * Auto-approve + auto-submit store requisitions to the cheapest vendor.
 *
 * After Internal Requisition creates an order (awaiting-approval), this module:
 * 0) Asks the store ordering policy (`cc_auto_vendor_submit_policy` filter, answered by
 *    WCSS_Order_Policy in the wcss plugin). Outside the window / over quota / over budget
 *    → stays awaiting approval as "needs_approval" for Supply Ops. When Ops approves it,
 *    the submit runs (see cc_auto_vendor_submit_on_approved).
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

	// Prefer the live OFFERS[0] id — nested buyingOptionIdentifier can be stale / unrelated.
	$offer = array();
	if ( ! empty( $product['includedDataTypes']['OFFERS'][0] ) && is_array( $product['includedDataTypes']['OFFERS'][0] ) ) {
		$offer = $product['includedDataTypes']['OFFERS'][0];
	} elseif ( ! empty( $product['offers'][0] ) && is_array( $product['offers'][0] ) ) {
		$offer = $product['offers'][0];
	}

	$buying_option_id = '';
	if ( ! empty( $offer['offerId'] ) ) {
		$buying_option_id = (string) $offer['offerId'];
	} elseif ( ! empty( $offer['buyingOptionIdentifier'] ) ) {
		$buying_option_id = (string) $offer['buyingOptionIdentifier'];
	} elseif ( function_exists( 'wp_amazon_find_nested_value' ) ) {
		$buying_option_id = (string) wp_amazon_find_nested_value( $product, 'offerId' );
		if ( $buying_option_id === '' ) {
			$buying_option_id = (string) wp_amazon_find_nested_value( $product, 'buyingOptionIdentifier' );
		}
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
/**
 * Index Amazon Business cart lines by uppercase ASIN.
 *
 * @return array{ok:bool,message:string,by_asin:array<string,array>}
 */
function cc_auto_vendor_submit_amazon_cart_index() {
	if ( ! function_exists( 'wp_amazon_checkout_get_cart_items' ) && ! function_exists( 'wp_amazon_get_cart_items' ) ) {
		return array( 'ok' => false, 'message' => 'Amazon cart helpers are not loaded.', 'by_asin' => array() );
	}
	$cart = function_exists( 'wp_amazon_checkout_get_cart_items' )
		? wp_amazon_checkout_get_cart_items()
		: wp_amazon_get_cart_items();
	if ( empty( $cart['success'] ) ) {
		return array(
			'ok'      => false,
			'message' => (string) ( $cart['message'] ?? 'Unable to read Amazon cart.' ),
			'by_asin' => array(),
		);
	}
	$by_asin = array();
	foreach ( (array) ( $cart['items'] ?? array() ) as $ci ) {
		$a = strtoupper( (string) ( $ci['productIdentifier'] ?? ( $ci['asin'] ?? '' ) ) );
		if ( $a !== '' && ! isset( $by_asin[ $a ] ) ) {
			$by_asin[ $a ] = $ci;
		}
	}
	return array( 'ok' => true, 'message' => '', 'by_asin' => $by_asin );
}

/**
 * Human message from Amazon cart rejectedItems.
 *
 * @param array  $result Cart API response.
 * @param string $asin   ASIN attempted.
 */
function cc_auto_vendor_submit_amazon_reject_message( array $result, $asin ) {
	$type = '';
	if ( ! empty( $result['rejectedItems'][0]['rejectionArtifacts'][0]['rejectionArtifactType'] ) ) {
		$type = (string) $result['rejectedItems'][0]['rejectionArtifacts'][0]['rejectionArtifactType'];
	}
	if ( $type === 'UnavailableQuantity' ) {
		return 'Amazon cart already has the maximum quantity for ASIN ' . $asin . '.';
	}
	return $type !== ''
		? ( 'Amazon rejected ASIN ' . $asin . ' (' . $type . ').' )
		: ( 'Amazon rejected ASIN ' . $asin . '.' );
}

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

	// Shared Business cart often already holds demo ASINs at max qty — reuse those lines.
	$cart_idx = cc_auto_vendor_submit_amazon_cart_index();
	$by_asin  = $cart_idx['ok'] ? $cart_idx['by_asin'] : array();

	foreach ( $lines as $line ) {
		$asin = strtoupper( (string) ( $line['vendor_id'] ?? '' ) );
		if ( $asin === '' ) {
			$errors[] = 'Missing ASIN for line ' . ( $line['name'] ?? '' );
			continue;
		}

		// Already in cart → place step will order the intended qty; no need to POST add.
		if ( isset( $by_asin[ $asin ] ) ) {
			$ci = $by_asin[ $asin ];
			$added[] = array_merge(
				$line,
				array(
					'asin'             => $asin,
					'buying_option_id' => (string) ( $ci['buyingOptionIdentifier'] ?? '' ),
					'title'            => (string) ( $line['name'] ?? '' ),
					'amazon_price'     => isset( $ci['price']['amount'] ) ? (float) $ci['price']['amount'] : null,
					'reused_cart'      => true,
				)
			);
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
		for ( $try = 2; $try <= 3 && is_wp_error( $result ); $try++ ) {
			$err  = $result->get_error_data();
			$http = (int) ( is_array( $err ) ? ( $err['status'] ?? 0 ) : 0 );
			if ( $http !== 429 && ( $http < 500 || $http > 599 ) ) {
				break; // only throttling / server errors are worth another try
			}
			sleep( $try === 2 ? 2 : 5 );
			$result = wp_amazon_business_cart_request( 'POST', $url, $token, $body );
		}
		if ( is_wp_error( $result ) ) {
			$errors[] = $result->get_error_message();
			continue;
		}

		if ( ! empty( $result['rejectedItems'] ) ) {
			// UnavailableQuantity often means the ASIN is already in the shared cart at max qty.
			$refresh = cc_auto_vendor_submit_amazon_cart_index();
			if ( $refresh['ok'] && isset( $refresh['by_asin'][ $asin ] ) ) {
				$ci = $refresh['by_asin'][ $asin ];
				$by_asin[ $asin ] = $ci;
				$added[] = array_merge(
					$line,
					array(
						'asin'             => $asin,
						'buying_option_id' => (string) ( $ci['buyingOptionIdentifier'] ?? $offer['buying_option_id'] ),
						'title'            => $offer['title'],
						'amazon_price'     => $offer['price'],
						'reused_cart'      => true,
					)
				);
				continue;
			}
			$errors[] = cc_auto_vendor_submit_amazon_reject_message( $result, $asin );
			continue;
		}

		// Keep local cart index fresh for mixed multi-line orders.
		if ( ! empty( $result['items'] ) && is_array( $result['items'] ) ) {
			foreach ( $result['items'] as $ci ) {
				$a = strtoupper( (string) ( $ci['productIdentifier'] ?? '' ) );
				if ( $a !== '' ) {
					$by_asin[ $a ] = $ci;
				}
			}
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
			? ( count( $added ) . ' Amazon item(s) ready to place.' )
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

	// The Business cart is shared (one AMAZON_CART_ID) and keeps items from manual sessions and
	// earlier orders. Order only this requisition's lines, at this requisition's quantities; the
	// cart entry is reused when present so Amazon's price expectations stay attached.
	$cart_by_asin = array();
	foreach ( (array) $cart_result['items'] as $ci ) {
		$ci_asin = strtoupper( (string) ( $ci['productIdentifier'] ?? ( $ci['asin'] ?? '' ) ) );
		if ( $ci_asin !== '' && ! isset( $cart_by_asin[ $ci_asin ] ) ) {
			$cart_by_asin[ $ci_asin ] = $ci;
		}
	}
	$place_items = array();
	$qty_by_asin = array();
	foreach ( $added as $line ) {
		$a = strtoupper( (string) $line['asin'] );
		$qty_by_asin[ $a ] = ( $qty_by_asin[ $a ] ?? 0 ) + max( 1, (int) ( $line['qty'] ?? 1 ) );
	}
	foreach ( $added as $line ) {
		$a = strtoupper( (string) $line['asin'] );
		if ( ! isset( $qty_by_asin[ $a ] ) ) {
			continue; // same ASIN on two lines: placed once with the summed quantity
		}
		$item = isset( $cart_by_asin[ $a ] ) ? $cart_by_asin[ $a ] : array(
			'productIdentifier'      => $a,
			'buyingOptionIdentifier' => (string) ( $line['buying_option_id'] ?? '' ),
		);
		$item['quantity'] = $qty_by_asin[ $a ];
		unset( $qty_by_asin[ $a ] );
		$place_items[] = $item;
	}
	if ( ! $place_items ) {
		return array(
			'ok'      => false,
			'status'  => 'failed',
			'message' => 'No Amazon lines to place for this order.',
		);
	}
	$ignored_cart_items = max( 0, count( (array) $cart_result['items'] ) - count( array_intersect_key( $cart_by_asin, array_flip( array_map( 'strtoupper', wp_list_pluck( $added, 'asin' ) ) ) ) ) );

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

	$payload = wp_amazon_checkout_build_order_payload( $place_items, $form_data );
	// Stable per order + item set, so a retried POST carries the same external ID as the first try.
	$payload['externalId'] = 'CC-' . $order->get_id() . '-' . substr( md5( wp_json_encode( $payload['lineItems'] ?? array() ) ), 0, 10 );
	if ( empty( $payload['lineItems'] ) ) {
		return array(
			'ok'      => false,
			'status'  => 'failed',
			'message' => 'No valid Amazon line items for Ordering API.',
		);
	}

	// Retry only answers that mean "not processed": 429 (throttled) and 5xx, same externalId each time.
	// A transport error (no HTTP status) is NOT retried — the order may already exist on Amazon.
	$attempts = array();
	for ( $try = 1; $try <= 3; $try++ ) {
		$result = wp_amazon_ordering_request(
			'POST',
			'https://na.business-api.amazon.com/ordering/2022-10-30/orders',
			$payload
		);
		$code       = (int) ( $result['status_code'] ?? 0 );
		$attempts[] = array(
			'try'        => $try,
			'http'       => $code,
			'request_id' => (string) ( $result['request_id'] ?? '' ),
			'at'         => gmdate( 'c' ),
		);
		$retryable = ( $code === 429 || ( $code >= 500 && $code < 600 ) );
		if ( ! empty( $result['success'] ) || ! $retryable || $try === 3 ) {
			break;
		}
		sleep( $try === 1 ? 2 : 5 );
	}

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

	$amazon_order_id = '';
	if ( $ok && ! empty( $result['data']['lineItems'] ) && is_array( $result['data']['lineItems'] ) ) {
		foreach ( $result['data']['lineItems'] as $li ) {
			foreach ( (array) ( $li['acceptedItems'] ?? array() ) as $acc ) {
				foreach ( (array) ( $acc['artifacts'] ?? array() ) as $art ) {
					if ( ( $art['acceptanceArtifactType'] ?? '' ) === 'OrderIdentifier' && ! empty( $art['identifier'] ) ) {
						$amazon_order_id = (string) $art['identifier'];
						break 3;
					}
				}
			}
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
		'placed_items'    => $place_items,
		'ignored_cart_items' => $ignored_cart_items,
		'attempts'        => $attempts,
		'order_payload'   => $payload,
		'amazon_response' => $result,
		'amazon_order_id' => $amazon_order_id,
		'amazon_request_id' => (string) ( $result['request_id'] ?? '' ),
		'order_total'     => null,
		'message'         => $ok ? 'Amazon order placed (trial mode).' : $fail_message,
		'placed_at'       => current_time( 'mysql' ),
	);
	if ( $ok && $amazon_order_id !== '' && function_exists( 'wc_get_order' ) ) {
		$order->update_meta_data( '_wcss_internal_ref', $amazon_order_id );
	}

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
 * One Amazon fill + place at a time: the Business cart is shared, so two orders must not
 * add to it concurrently. add_option() is atomic; a lock older than 10 minutes is stale.
 *
 * @return bool True when acquired.
 */
function cc_auto_vendor_submit_amazon_lock() {
	$key = 'cc_amazon_place_lock';
	if ( add_option( $key, time(), '', 'no' ) ) {
		return true;
	}
	$since = (int) get_option( $key, 0 );
	if ( $since && time() - $since > 10 * MINUTE_IN_SECONDS ) {
		delete_option( $key );
		return add_option( $key, time(), '', 'no' );
	}
	return false;
}

function cc_auto_vendor_submit_amazon_unlock() {
	delete_option( 'cc_amazon_place_lock' );
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
	// Allow retry when a previous attempt failed, or when Ops approved a held order.
	if ( is_array( $existing ) && ! empty( $existing['attempted'] ) ) {
		$prev_status = (string) ( $existing['status'] ?? '' );
		$force       = (bool) apply_filters( 'cc_auto_vendor_submit_force_retry', false, $order_id, $existing );
		$retryable   = in_array( $prev_status, array( 'needs_approval', 'failed', 'processing', 'partial' ), true );
		$approved_exception = $retryable && $order->get_meta( '_wcss_policy_override' );
		// Final states: already submitted or intentionally Woo-only — do not re-fire.
		if ( ! $force && ! $approved_exception && ! in_array( $prev_status, array( 'failed', 'processing' ), true ) ) {
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

	// Store ordering policy: only in-policy orders are auto-approved and sent to a vendor.
	$gate = apply_filters( 'cc_auto_vendor_submit_policy', array( 'allowed' => true, 'reasons' => array(), 'messages' => array() ), $order );
	if ( empty( $gate['allowed'] ) ) {
		$summary = array(
			'attempted' => true,
			'status'    => 'needs_approval',
			'reasons'   => (array) ( $gate['reasons'] ?? array() ),
			'message'   => 'Needs Supply Ops approval: ' . implode( ' ', (array) ( $gate['messages'] ?? array() ) ),
			'results'   => array(),
		);
		cc_auto_vendor_submit_save_meta( $order, $summary );
		if ( class_exists( 'WCSS_Order_Events' ) ) {
			WCSS_Order_Events::log( $order_id, 'auto_vendor_submit', array( 'status' => 'needs_approval', 'reasons' => $summary['reasons'] ) );
		}
		return $summary;
	}

	$collected = cc_auto_vendor_submit_collect_lines( $order );
	$vendors   = $collected['vendors'];

	if ( ! $vendors ) {
		// No Amazon / Grand & Toy match → keep as an approved WooCommerce order (no vendor API).
		if ( ! in_array( $order->get_status(), array( 'approved', 'completed' ), true ) ) {
			cc_auto_vendor_submit_approve( $order );
			$order = wc_get_order( $order_id );
		}
		$summary = array(
			'attempted'      => true,
			'status'         => 'woo_only',
			'winning_vendor' => '',
			'vendors'        => array(),
			'message'        => 'No Amazon or Grand & Toy product match — kept as a WooCommerce order.',
			'results'        => array(),
			'completed_at'   => gmdate( 'c' ),
		);
		cc_auto_vendor_submit_save_meta( $order, $summary );
		if ( class_exists( 'WCSS_Order_Events' ) ) {
			WCSS_Order_Events::log( $order_id, 'auto_vendor_submit', array( 'status' => 'woo_only', 'vendors' => array() ) );
		}
		do_action( 'cc_auto_vendor_submit_done', $order_id, $summary );
		return $summary;
	}

	// Take the Amazon lock before any vendor call, so a busy lock never leaves G&T half-sent.
	$amazon_locked = false;
	if ( in_array( 'amazon', $vendors, true ) ) {
		$amazon_locked = cc_auto_vendor_submit_amazon_lock();
		if ( ! $amazon_locked ) {
			cc_auto_vendor_submit_save_meta(
				$order,
				array(
					'attempted' => true,
					'status'    => 'processing',
					'message'   => 'Waiting for another Amazon order to finish — retrying in 1 minute.',
				)
			);
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, 'cc_auto_vendor_submit_async', array( 'order_id' => $order_id ), 'cc-auto-vendor' );
			}
			return null;
		}
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

	if ( $amazon_locked ) {
		cc_auto_vendor_submit_amazon_unlock();
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

	// Supply Manager → Emails: "Vendor accepted order" / "Vendor rejected / failed".
	do_action( 'cc_auto_vendor_submit_done', $order_id, $summary );

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
 * Supply Ops approved an order → auto-place with Amazon / G&T (or mark Woo-only).
 *
 * Runs for held policy exceptions, failed prior attempts, and orders that never
 * started auto-submit (e.g. filter was off at checkout).
 *
 * @param int $order_id Order ID.
 */
function cc_auto_vendor_submit_on_approved( $order_id ) {
	$order = wc_get_order( (int) $order_id );
	if ( ! $order ) {
		return;
	}
	if ( ! $order->get_meta( '_wcss_policy_override' ) ) {
		// Approved outside the manager portal (e.g. wp-admin): the approval itself is the override.
		$order->update_meta_data(
			'_wcss_policy_override',
			array(
				'by'     => get_current_user_id(),
				'at'     => current_time( 'mysql' ),
				'source' => 'status_change',
			)
		);
		$order->save();
	}

	$meta   = $order->get_meta( WCSS_AUTO_VENDOR_SUBMIT_META, true );
	$status = is_array( $meta ) ? (string) ( $meta['status'] ?? '' ) : '';

	// Already done — normalize legacy "skipped" label for the order detail UI.
	if ( in_array( $status, array( 'submitted', 'woo_only' ), true ) ) {
		return;
	}
	if ( $status === 'skipped' ) {
		cc_auto_vendor_submit_save_meta(
			$order,
			array(
				'status'  => 'woo_only',
				'message' => 'No Amazon or Grand & Toy product match — kept as a WooCommerce order.',
			)
		);
		return;
	}

	// needs_approval | failed | processing | partial | empty → place (or Woo-only).
	// Run sync on Approve so the order detail page can show Sent / Woo-only / Failed immediately
	// (Amazon lock contention still re-queues itself via Action Scheduler inside run()).
	cc_auto_vendor_submit_run( (int) $order_id );
}
add_action( 'woocommerce_order_status_approved', 'cc_auto_vendor_submit_on_approved', 30, 1 );

/**
 * Force a re-run of auto vendor submit (Supply Manager "Retry send").
 *
 * @param int $order_id Order ID.
 * @return array<string,mixed>|null Summary or null when queued async / disabled.
 */
function cc_auto_vendor_submit_retry( $order_id ) {
	$order_id = (int) $order_id;
	$order    = wc_get_order( $order_id );
	if ( ! $order ) {
		return null;
	}
	if ( ! $order->get_meta( '_wcss_policy_override' ) ) {
		$order->update_meta_data(
			'_wcss_policy_override',
			array(
				'by'     => get_current_user_id(),
				'at'     => current_time( 'mysql' ),
				'source' => 'retry',
			)
		);
		$order->save();
	}
	$force_cb = static function () {
		return true;
	};
	add_filter( 'cc_auto_vendor_submit_force_retry', $force_cb, 10, 0 );
	$result = cc_auto_vendor_submit_run( $order_id );
	remove_filter( 'cc_auto_vendor_submit_force_retry', $force_cb, 10 );
	return $result;
}

/**
 * Async runner (Action Scheduler).
 *
 * @param int $order_id Order ID.
 */
function cc_auto_vendor_submit_async_cb( $order_id ) {
	cc_auto_vendor_submit_run( (int) $order_id );
}
add_action( 'cc_auto_vendor_submit_async', 'cc_auto_vendor_submit_async_cb', 10, 1 );
