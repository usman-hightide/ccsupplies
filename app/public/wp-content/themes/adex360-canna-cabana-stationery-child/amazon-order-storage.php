<?php
/**
 * Persist Amazon cart/checkout snapshots against a WooCommerce order.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCSS_AMAZON_ORDER_META_KEY', '_wcss_amazon_order_data' );
define( 'WCSS_AMAZON_ASIN_TITLES_OPTION', 'wcss_amazon_asin_titles' );

/**
 * Resolve WC order ID from request (GET/POST).
 */
function wcss_amazon_get_request_order_id(): int {
	$order_id = 0;

	if ( isset( $_POST['wc_order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order_id = absint( wp_unslash( $_POST['wc_order_id'] ) );
	} elseif ( isset( $_GET['order-id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = absint( wp_unslash( $_GET['order-id'] ) );
	} elseif ( isset( $_GET['order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = absint( wp_unslash( $_GET['order_id'] ) );
	}

	return $order_id;
}

/**
 * Remember Amazon product title by ASIN (from catalog add-to-cart).
 */
function wcss_amazon_remember_asin_title( string $asin, string $title ): void {
	$asin  = trim( $asin );
	$title = trim( wp_strip_all_tags( $title ) );
	if ( '' === $asin || '' === $title ) {
		return;
	}

	$map = get_option( WCSS_AMAZON_ASIN_TITLES_OPTION, array() );
	if ( ! is_array( $map ) ) {
		$map = array();
	}
	$map[ $asin ] = $title;
	update_option( WCSS_AMAZON_ASIN_TITLES_OPTION, $map, false );
}

/**
 * Look up a remembered Amazon title by ASIN.
 */
function wcss_amazon_get_remembered_asin_title( string $asin ): string {
	$asin = trim( $asin );
	if ( '' === $asin ) {
		return '';
	}
	$map = get_option( WCSS_AMAZON_ASIN_TITLES_OPTION, array() );
	if ( ! is_array( $map ) || empty( $map[ $asin ] ) ) {
		return '';
	}
	return (string) $map[ $asin ];
}

/**
 * Resolve WooCommerce product title linked via amazon_asin meta.
 */
function wcss_amazon_get_woo_title_by_asin( string $asin ): string {
	$asin = trim( $asin );
	if ( '' === $asin ) {
		return '';
	}

	$ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'meta_key'       => 'amazon_asin',
			'meta_value'     => $asin,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	if ( empty( $ids[0] ) ) {
		return '';
	}

	$title = get_the_title( (int) $ids[0] );
	return is_string( $title ) ? trim( $title ) : '';
}

/**
 * Resolve best available product title for an Amazon line item / ASIN.
 */
function wcss_amazon_resolve_product_title( array $item, string $asin = '' ): string {
	$candidates = array(
		$item['title'] ?? '',
		$item['productTitle'] ?? '',
		$item['itemName'] ?? '',
		$item['productName'] ?? '',
		$item['name'] ?? '',
	);

	foreach ( $candidates as $candidate ) {
		if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
			return trim( $candidate );
		}
	}

	if ( '' === $asin ) {
		if ( ! empty( $item['productIdentifier'] ) ) {
			$asin = (string) $item['productIdentifier'];
		} elseif ( ! empty( $item['asin'] ) ) {
			$asin = (string) $item['asin'];
		}
	}

	$remembered = wcss_amazon_get_remembered_asin_title( $asin );
	if ( '' !== $remembered ) {
		return $remembered;
	}

	return wcss_amazon_get_woo_title_by_asin( $asin );
}

/**
 * Normalize Amazon cart line items for display/storage.
 *
 * @param array $items Raw Amazon cart items.
 * @return array
 */
function wcss_amazon_normalize_cart_items( array $items ): array {
	$normalized = array();

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$asin = '';
		if ( ! empty( $item['productIdentifier'] ) ) {
			$asin = (string) $item['productIdentifier'];
		} elseif ( ! empty( $item['asin'] ) ) {
			$asin = (string) $item['asin'];
		}

		$title = wcss_amazon_resolve_product_title( $item, $asin );

		$quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;

		$price    = '';
		$currency = 'CAD';
		if ( isset( $item['price']['value'] ) ) {
			$price = (string) $item['price']['value'];
			if ( ! empty( $item['price']['currencyCode'] ) ) {
				$currency = (string) $item['price']['currencyCode'];
			}
		} elseif ( isset( $item['price']['amount'] ) ) {
			$price = (string) $item['price']['amount'];
			if ( ! empty( $item['price']['currencyCode'] ) ) {
				$currency = (string) $item['price']['currencyCode'];
			}
		} elseif ( isset( $item['unitPrice']['value'] ) ) {
			$price = (string) $item['unitPrice']['value'];
		} elseif ( isset( $item['unitPrice']['amount'] ) ) {
			$price = (string) $item['unitPrice']['amount'];
		}

		$buying_option = '';
		if ( ! empty( $item['buyingOptionIdentifier'] ) ) {
			$buying_option = (string) $item['buyingOptionIdentifier'];
		}

		$normalized[] = array(
			'title'            => $title,
			'asin'             => $asin,
			'quantity'         => $quantity,
			'price'            => $price,
			'currency'         => $currency,
			'buying_option_id' => $buying_option,
			'cart_item_id'     => isset( $item['id'] ) ? (string) $item['id'] : ( isset( $item['itemId'] ) ? (string) $item['itemId'] : '' ),
		);
	}

	return $normalized;
}

/**
 * Classify a single Amazon charge row into subtotal / shipping / tax.
 *
 * Cost estimation API uses type + category.
 * Ordering API Charge artifacts often use category only (PRINCIPAL / TAX / SHIPPING).
 *
 * @param array $charge Charge row.
 * @return array{role:string,amount:float,currency:string}|null
 */
function wcss_amazon_classify_charge( array $charge ): ?array {
	$amount = 0.0;
	if ( isset( $charge['amount']['amount'] ) ) {
		$amount = (float) $charge['amount']['amount'];
	} elseif ( isset( $charge['amount']['value'] ) ) {
		$amount = (float) $charge['amount']['value'];
	} else {
		return null;
	}

	$currency = (string) ( $charge['amount']['currencyCode'] ?? 'CAD' );
	$type     = strtoupper( (string) ( $charge['type'] ?? '' ) );
	$category = strtoupper( (string) ( $charge['category'] ?? '' ) );

	// Tax: match checkout estimator (type=TAX) and Ordering Charge (category=TAX).
	if ( 'TAX' === $type || 'TAX' === $category ) {
		return array(
			'role'     => 'tax',
			'amount'   => $amount,
			'currency' => $currency,
		);
	}

	// Shipping.
	if (
		( 'PRINCIPAL' === $type && 'SHIPPING' === $category )
		|| 'SHIPPING' === $type
		|| 'SHIPPING' === $category
	) {
		return array(
			'role'     => 'shipping',
			'amount'   => $amount,
			'currency' => $currency,
		);
	}

	// Subtotal / principal merchandise.
	if (
		( 'PRINCIPAL' === $type && 'SUBTOTAL' === $category )
		|| ( 'PRINCIPAL' === $type && '' === $category )
		|| ( 'PRINCIPAL' === $category && '' === $type )
		|| ( 'SUBTOTAL' === $type && 'TAX' !== $category )
	) {
		return array(
			'role'     => 'subtotal',
			'amount'   => $amount,
			'currency' => $currency,
		);
	}

	// Ordering API sometimes returns category=SUBTOTAL without type for merchandise.
	if ( 'SUBTOTAL' === $category && '' === $type ) {
		return array(
			'role'     => 'subtotal',
			'amount'   => $amount,
			'currency' => $currency,
		);
	}

	return null;
}

/**
 * Sum classified charges with per-role amount dedupe (stops mirrored payload double-count).
 *
 * @param array $charges Charge rows.
 * @return array{subtotal:float,shipping:float,tax:float,total:float,currency:string}
 */
function wcss_amazon_sum_classified_charges( array $charges ): array {
	$totals = array(
		'subtotal' => 0.0,
		'shipping' => 0.0,
		'tax'      => 0.0,
		'total'    => 0.0,
		'currency' => 'CAD',
	);
	$seen = array();

	foreach ( $charges as $charge ) {
		if ( ! is_array( $charge ) ) {
			continue;
		}
		$classified = wcss_amazon_classify_charge( $charge );
		if ( ! $classified ) {
			continue;
		}

		$role       = $classified['role'];
		$amount     = round( (float) $classified['amount'], 2 );
		$currency   = $classified['currency'];
		$fingerprint = $role . '|' . number_format( $amount, 2, '.', '' ) . '|' . $currency;

		if ( isset( $seen[ $fingerprint ] ) ) {
			continue;
		}
		$seen[ $fingerprint ] = true;

		$totals[ $role ]   += $amount;
		$totals['currency'] = $currency;
	}

	$totals['subtotal'] = round( $totals['subtotal'], 2 );
	$totals['shipping'] = round( $totals['shipping'], 2 );
	$totals['tax']      = round( $totals['tax'], 2 );
	$totals['total']    = round( $totals['subtotal'] + $totals['shipping'] + $totals['tax'], 2 );

	return $totals;
}

/**
 * Collect Charge artifacts only from Ordering API lineItems (no full-tree walk).
 *
 * @param mixed $node Response fragment.
 * @return array
 */
function wcss_amazon_collect_order_charge_artifacts( $node ): array {
	$charges = array();

	if ( ! is_array( $node ) ) {
		return $charges;
	}

	$line_items = array();
	if ( ! empty( $node['data']['lineItems'] ) && is_array( $node['data']['lineItems'] ) ) {
		$line_items = $node['data']['lineItems'];
	} elseif ( ! empty( $node['lineItems'] ) && is_array( $node['lineItems'] ) ) {
		$line_items = $node['lineItems'];
	}

	foreach ( $line_items as $line_item ) {
		if ( empty( $line_item['acceptedItems'] ) || ! is_array( $line_item['acceptedItems'] ) ) {
			continue;
		}
		foreach ( $line_item['acceptedItems'] as $accepted ) {
			if ( empty( $accepted['artifacts'] ) || ! is_array( $accepted['artifacts'] ) ) {
				continue;
			}
			foreach ( $accepted['artifacts'] as $artifact ) {
				if ( ! is_array( $artifact ) ) {
					continue;
				}
				$artifact_type = strtoupper( (string) ( $artifact['acceptanceArtifactType'] ?? '' ) );
				if ( 'CHARGE' === $artifact_type ) {
					$charges[] = $artifact;
				}
			}
		}
	}

	return $charges;
}

/**
 * Extract subtotal / shipping / tax / total from Amazon charge payloads.
 *
 * Prefers explicit `charges` arrays (shipping estimate API), then Ordering
 * API Charge artifacts under lineItems. Does not recurse mirrored raw_body.
 *
 * @param mixed $node Response fragment.
 * @return array{subtotal:float,shipping:float,tax:float,total:float,currency:string}
 */
function wcss_amazon_extract_charge_totals( $node ): array {
	$empty = array(
		'subtotal' => 0.0,
		'shipping' => 0.0,
		'tax'      => 0.0,
		'total'    => 0.0,
		'currency' => 'CAD',
	);

	if ( ! is_array( $node ) ) {
		return $empty;
	}

	// 1) Cost estimation / totals API: top-level charges list.
	$charge_list = null;
	if ( ! empty( $node['data']['charges'] ) && is_array( $node['data']['charges'] ) ) {
		$charge_list = $node['data']['charges'];
	} elseif ( ! empty( $node['charges'] ) && is_array( $node['charges'] ) ) {
		$charge_list = $node['charges'];
	} elseif ( ! empty( $node['data']['totalCharges'] ) && is_array( $node['data']['totalCharges'] ) ) {
		$charge_list = $node['data']['totalCharges'];
	}

	if ( is_array( $charge_list ) && $charge_list ) {
		return wcss_amazon_sum_classified_charges( $charge_list );
	}

	// 2) Ordering place-order response: Charge artifacts on accepted line items only.
	$artifacts = wcss_amazon_collect_order_charge_artifacts( $node );
	if ( $artifacts ) {
		return wcss_amazon_sum_classified_charges( $artifacts );
	}

	return $empty;
}

/**
 * Enrich a stored snapshot for API/UI (titles + totals).
 *
 * @param array $data Snapshot.
 * @return array
 */
function wcss_amazon_enrich_snapshot( array $data ): array {
	if ( ! empty( $data['cart_items'] ) && is_array( $data['cart_items'] ) ) {
		foreach ( $data['cart_items'] as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$asin  = (string) ( $item['asin'] ?? '' );
			$title = trim( (string) ( $item['title'] ?? '' ) );
			if ( '' === $title || 'Untitled product' === $title ) {
				$resolved = wcss_amazon_resolve_product_title( $item, $asin );
				if ( '' !== $resolved ) {
					$data['cart_items'][ $i ]['title'] = $resolved;
				}
			}
		}
	}

	$has_shipping = array_key_exists( 'shipping_amount', $data ) && '' !== $data['shipping_amount'] && null !== $data['shipping_amount'];
	$has_tax      = array_key_exists( 'tax_amount', $data ) && '' !== $data['tax_amount'] && null !== $data['tax_amount'];

	// Prefer line-item math for subtotal when cart rows exist.
	$items_subtotal = 0.0;
	if ( ! empty( $data['cart_items'] ) && is_array( $data['cart_items'] ) ) {
		foreach ( $data['cart_items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$items_subtotal += ( (float) ( $item['price'] ?? 0 ) ) * ( (int) ( $item['quantity'] ?? 0 ) );
		}
		$items_subtotal = round( $items_subtotal, 2 );
		if ( $items_subtotal > 0 ) {
			$data['cart_subtotal'] = $items_subtotal;
		}
	}

	if ( ! empty( $data['amazon_response'] ) ) {
		$extracted = wcss_amazon_extract_charge_totals( $data['amazon_response'] );

		// Always refresh from Amazon response so duplicated charge walks can't stick.
		$data['shipping_amount'] = $extracted['shipping'];
		$data['tax_amount']      = $extracted['tax'];

		if ( empty( $data['cart_subtotal'] ) && $extracted['subtotal'] > 0 ) {
			$data['cart_subtotal'] = $extracted['subtotal'];
		}
		if ( empty( $data['currency'] ) && ! empty( $extracted['currency'] ) ) {
			$data['currency'] = $extracted['currency'];
		}
	} elseif ( ! $has_shipping ) {
		$data['shipping_amount'] = 0;
	} elseif ( ! $has_tax ) {
		$data['tax_amount'] = 0;
	}

	$subtotal = isset( $data['cart_subtotal'] ) ? (float) $data['cart_subtotal'] : 0.0;
	$shipping = isset( $data['shipping_amount'] ) ? (float) $data['shipping_amount'] : 0.0;
	$tax      = isset( $data['tax_amount'] ) ? (float) $data['tax_amount'] : 0.0;
	$data['order_total'] = round( $subtotal + $shipping + $tax, 2 );

	return $data;
}

/**
 * Save Amazon order snapshot onto a WooCommerce order.
 *
 * @param int   $wc_order_id WooCommerce order ID.
 * @param array $data        Snapshot payload.
 * @return bool
 */
function wcss_amazon_save_order_snapshot( int $wc_order_id, array $data ): bool {
	if ( $wc_order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
		return false;
	}

	$order = wc_get_order( $wc_order_id );
	if ( ! $order ) {
		return false;
	}

	$existing = $order->get_meta( WCSS_AMAZON_ORDER_META_KEY, true );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}

	// Fill blank titles on already-normalized cart rows (do not re-normalize flat snapshots).
	if ( ! empty( $data['cart_items'] ) && is_array( $data['cart_items'] ) ) {
		foreach ( $data['cart_items'] as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$asin  = (string) ( $item['asin'] ?? $item['productIdentifier'] ?? '' );
			$title = trim( (string) ( $item['title'] ?? '' ) );
			if ( '' === $title ) {
				$data['cart_items'][ $i ]['title'] = wcss_amazon_resolve_product_title( $item, $asin );
			}
			if ( empty( $data['cart_items'][ $i ]['asin'] ) && '' !== $asin ) {
				$data['cart_items'][ $i ]['asin'] = $asin;
			}
		}
	}

	$snapshot = array_merge(
		$existing,
		$data,
		array(
			'wc_order_id' => $wc_order_id,
			'updated_at'  => current_time( 'mysql' ),
		)
	);

	if ( empty( $snapshot['created_at'] ) ) {
		$snapshot['created_at'] = current_time( 'mysql' );
	}

	// Don't let an earlier checkout_ready overwrite a successful placed status.
	if (
		! empty( $existing['status'] )
		&& 'placed' === $existing['status']
		&& ! empty( $data['status'] )
		&& in_array( $data['status'], array( 'checkout_ready', 'cart_saved' ), true )
	) {
		$snapshot['status'] = 'placed';
		if ( ! empty( $existing['source'] ) ) {
			$snapshot['source'] = $existing['source'];
		}
	}

	$order->update_meta_data( WCSS_AMAZON_ORDER_META_KEY, $snapshot );
	$order->save();

	return true;
}

/**
 * Get saved Amazon snapshot for a WooCommerce order.
 *
 * @param int $wc_order_id Order ID.
 * @return array|null
 */
function wcss_amazon_get_order_snapshot( int $wc_order_id ): ?array {
	if ( $wc_order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
		return null;
	}

	$order = wc_get_order( $wc_order_id );
	if ( ! $order ) {
		return null;
	}

	$data = $order->get_meta( WCSS_AMAZON_ORDER_META_KEY, true );
	if ( ! is_array( $data ) || empty( $data ) ) {
		return null;
	}

	return wcss_amazon_enrich_snapshot( $data );
}

/**
 * Whether an order has Amazon snapshot data.
 */
function wcss_amazon_order_has_snapshot( int $wc_order_id ): bool {
	return null !== wcss_amazon_get_order_snapshot( $wc_order_id );
}
