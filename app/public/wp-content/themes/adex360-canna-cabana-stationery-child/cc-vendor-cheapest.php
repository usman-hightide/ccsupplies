<?php
/**
 * Frontend cheapest price — shop/product display + auto cart application.
 * No manual vendor picker; uses `_cc_cheapest_*` / DB cache with Woo fallback.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether price filters should run (storefront / AJAX cart).
 *
 * @return bool
 */
function cc_vendor_cheapest_should_filter() {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return false;
	}
	// Avoid breaking admin product editor REST when not in AJAX cart context.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST && is_admin() ) {
		return false;
	}
	return true;
}

/**
 * Guard against recursive get_price calls.
 *
 * @var bool
 */
$GLOBALS['cc_vendor_cheapest_inflight'] = false;

/**
 * Filter product price to cheapest cached (or Woo).
 *
 * @param string|float $price   Price.
 * @param WC_Product   $product Product.
 * @return string|float
 */
function cc_vendor_cheapest_filter_price( $price, $product ) {
	if ( ! cc_vendor_cheapest_should_filter() || ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return $price;
	}
	if ( ! empty( $GLOBALS['cc_vendor_cheapest_inflight'] ) ) {
		return $price;
	}
	if ( ! function_exists( 'cc_vendor_prices_resolve_cheapest' ) ) {
		return $price;
	}

	$GLOBALS['cc_vendor_cheapest_inflight'] = true;
	$resolved = cc_vendor_prices_resolve_cheapest( $product );
	$GLOBALS['cc_vendor_cheapest_inflight'] = false;

	if ( $resolved['price'] === null || ! is_numeric( $resolved['price'] ) ) {
		return $price;
	}
	return $resolved['price'];
}
add_filter( 'woocommerce_product_get_price', 'cc_vendor_cheapest_filter_price', 30, 2 );
add_filter( 'woocommerce_product_variation_get_price', 'cc_vendor_cheapest_filter_price', 30, 2 );

/**
 * Attach cheapest vendor meta when product is added to cart.
 *
 * @param array $cart_item_data Cart item data.
 * @param int   $product_id     Product ID.
 * @param int   $variation_id   Variation ID.
 * @return array
 */
function cc_vendor_cheapest_add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
	$pid = $variation_id ? (int) $variation_id : (int) $product_id;
	if ( $pid <= 0 || ! function_exists( 'cc_vendor_prices_resolve_cheapest' ) ) {
		return $cart_item_data;
	}

	$resolved = cc_vendor_prices_resolve_cheapest( $pid );
	$vendor   = (string) ( $resolved['vendor'] ?? 'woo' );
	$price    = $resolved['price'];

	// Auto-tag procurement vendor only when a vendor won.
	if ( in_array( $vendor, array( 'amazon', 'gt' ), true ) && $price !== null ) {
		$cart_item_data['cc_vendor']            = $vendor;
		$cart_item_data['cc_vendor_price']      = (float) $price;
		$cart_item_data['cc_vendor_currency']   = 'CAD';
		$cart_item_data['cc_vendor_error']      = '';
		$cart_item_data['cc_vendor_fetched_at'] = time();
		$cart_item_data['cc_cheapest_vendor']   = $vendor;
		$cart_item_data['cc_cheapest_price']    = (float) $price;

		$rows = function_exists( 'cc_vendor_prices_get_for_product' ) ? cc_vendor_prices_get_for_product( $pid ) : array();
		foreach ( $rows as $row ) {
			if ( $row && (string) $row->vendor === $vendor && ! empty( $row->vendor_sku ) ) {
				$cart_item_data['cc_vendor_id'] = (string) $row->vendor_sku;
				break;
			}
		}
	} else {
		$cart_item_data['cc_cheapest_vendor'] = 'woo';
		if ( $price !== null ) {
			$cart_item_data['cc_cheapest_price'] = (float) $price;
		}
		// Clear any stale manual vendor selection.
		unset( $cart_item_data['cc_vendor'], $cart_item_data['cc_vendor_price'], $cart_item_data['cc_vendor_id'], $cart_item_data['cc_vendor_error'] );
	}

	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'cc_vendor_cheapest_add_cart_item_data', 25, 3 );

/**
 * Keep cheapest keys in session.
 *
 * @param array $cart_item Cart item.
 * @param array $values    Session values.
 * @return array
 */
function cc_vendor_cheapest_get_cart_item_from_session( $cart_item, $values ) {
	foreach ( array( 'cc_cheapest_vendor', 'cc_cheapest_price' ) as $key ) {
		if ( isset( $values[ $key ] ) ) {
			$cart_item[ $key ] = $values[ $key ];
		}
	}
	return $cart_item;
}
add_filter( 'woocommerce_get_cart_item_from_session', 'cc_vendor_cheapest_get_cart_item_from_session', 25, 2 );

/**
 * Refresh cart lines from current cheapest cache (no live API).
 *
 * @param WC_Cart $cart Cart.
 */
function cc_vendor_cheapest_refresh_cart_lines( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) || ! function_exists( 'cc_vendor_prices_resolve_cheapest' ) ) {
		return;
	}

	$changed = false;
	foreach ( $cart->get_cart() as $key => $item ) {
		$product = isset( $item['data'] ) ? $item['data'] : null;
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			continue;
		}
		$pid      = (int) $product->get_id();
		$resolved = cc_vendor_prices_resolve_cheapest( $product );
		$vendor   = (string) ( $resolved['vendor'] ?? 'woo' );
		$price    = $resolved['price'];

		$cart->cart_contents[ $key ]['cc_cheapest_vendor'] = $vendor;
		if ( $price !== null ) {
			$cart->cart_contents[ $key ]['cc_cheapest_price'] = (float) $price;
		}

		if ( in_array( $vendor, array( 'amazon', 'gt' ), true ) && $price !== null ) {
			$cart->cart_contents[ $key ]['cc_vendor']       = $vendor;
			$cart->cart_contents[ $key ]['cc_vendor_price'] = (float) $price;
			$cart->cart_contents[ $key ]['cc_vendor_error'] = '';
			$cart->cart_contents[ $key ]['cc_vendor_currency'] = 'CAD';
			if ( empty( $cart->cart_contents[ $key ]['cc_vendor_id'] ) && function_exists( 'cc_vendor_prices_get_for_product' ) ) {
				foreach ( cc_vendor_prices_get_for_product( $pid ) as $row ) {
					if ( $row && (string) $row->vendor === $vendor && ! empty( $row->vendor_sku ) ) {
						$cart->cart_contents[ $key ]['cc_vendor_id'] = (string) $row->vendor_sku;
						break;
					}
				}
			}
		} else {
			unset(
				$cart->cart_contents[ $key ]['cc_vendor'],
				$cart->cart_contents[ $key ]['cc_vendor_price'],
				$cart->cart_contents[ $key ]['cc_vendor_id'],
				$cart->cart_contents[ $key ]['cc_vendor_error'],
				$cart->cart_contents[ $key ]['cc_vendor_currency'],
				$cart->cart_contents[ $key ]['cc_vendor_fetched_at']
			);
		}
		$changed = true;
	}

	if ( $changed ) {
		// Session write happens via Woo; price apply uses before_calculate_totals.
	}
}
add_action( 'woocommerce_before_calculate_totals', 'cc_vendor_cheapest_refresh_cart_lines', 15 );

/**
 * Apply cheapest / vendor unit price onto cart product objects.
 * Runs after refresh; replaces reliance on manual vendor-only apply when meta present.
 *
 * @param WC_Cart $cart Cart.
 */
function cc_vendor_cheapest_apply_cart_prices( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
		return;
	}

	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['data'] ) || ! is_a( $item['data'], 'WC_Product' ) ) {
			continue;
		}
		$price = null;
		if ( isset( $item['cc_vendor_price'] ) && $item['cc_vendor_price'] !== '' && empty( $item['cc_vendor_error'] ) ) {
			$price = (float) $item['cc_vendor_price'];
		} elseif ( isset( $item['cc_cheapest_price'] ) && $item['cc_cheapest_price'] !== '' ) {
			$price = (float) $item['cc_cheapest_price'];
		}
		if ( $price === null || $price < 0 ) {
			continue;
		}
		$item['data']->set_price( $price );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'cc_vendor_cheapest_apply_cart_prices', 25 );
