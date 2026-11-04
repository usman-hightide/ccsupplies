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
 * Resolve a positive cheapest unit price for storefront display / cart.
 *
 * @param WC_Product $product Product.
 * @return float|null
 */
function cc_vendor_cheapest_unit_price( $product ) {
	if ( ! $product || ! is_a( $product, 'WC_Product' ) || ! function_exists( 'cc_vendor_prices_resolve_cheapest' ) ) {
		return null;
	}
	if ( ! empty( $GLOBALS['cc_vendor_cheapest_inflight'] ) ) {
		return null;
	}
	$GLOBALS['cc_vendor_cheapest_inflight'] = true;
	$resolved = cc_vendor_prices_resolve_cheapest( $product );
	$GLOBALS['cc_vendor_cheapest_inflight'] = false;
	if ( ! isset( $resolved['price'] ) || $resolved['price'] === null || ! is_numeric( $resolved['price'] ) ) {
		return null;
	}
	$price = (float) $resolved['price'];
	return $price > 0 ? $price : null;
}

/**
 * Filter product price to cheapest cached (vendor DB), else keep WooCommerce price.
 * Covers price / regular / sale — Elementor and loops may read any of these.
 *
 * Rule: min(Amazon ok, G&T ok, Woo) via `_cc_cheapest_*`. If no positive
 * cheapest meta, leave the native Woo price unchanged.
 *
 * @param string|float $price   Price.
 * @param WC_Product   $product Product.
 * @return string|float
 */
function cc_vendor_cheapest_filter_price( $price, $product ) {
	if ( ! cc_vendor_cheapest_should_filter() || ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return $price;
	}
	$cheapest = cc_vendor_cheapest_unit_price( $product );
	return $cheapest !== null ? $cheapest : $price;
}
add_filter( 'woocommerce_product_get_price', 'cc_vendor_cheapest_filter_price', 30, 2 );
add_filter( 'woocommerce_product_variation_get_price', 'cc_vendor_cheapest_filter_price', 30, 2 );
add_filter( 'woocommerce_product_get_regular_price', 'cc_vendor_cheapest_filter_price', 30, 2 );
add_filter( 'woocommerce_product_variation_get_regular_price', 'cc_vendor_cheapest_filter_price', 30, 2 );
add_filter( 'woocommerce_product_get_sale_price', 'cc_vendor_cheapest_filter_price', 30, 2 );
add_filter( 'woocommerce_product_variation_get_sale_price', 'cc_vendor_cheapest_filter_price', 30, 2 );

/**
 * Force price HTML (shop, product page, related, search cards) to the cheapest rate.
 *
 * @param string     $html    Price HTML.
 * @param WC_Product $product Product.
 * @return string
 */
function cc_vendor_cheapest_price_html( $html, $product ) {
	if ( ! cc_vendor_cheapest_should_filter() || ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return $html;
	}
	$cheapest = cc_vendor_cheapest_unit_price( $product );
	if ( $cheapest === null ) {
		return $html;
	}
	return wc_price( wc_get_price_to_display( $product, array( 'price' => $cheapest ) ) );
}
add_filter( 'woocommerce_get_price_html', 'cc_vendor_cheapest_price_html', 100, 2 );

/**
 * Stamp the cheapest unit onto the in-memory product so Elementor widgets
 * that read $product->get_regular_price() / raw props stay consistent with cart.
 *
 * @param WC_Product $product Product.
 */
function cc_vendor_cheapest_stamp_product( $product ) {
	if ( ! cc_vendor_cheapest_should_filter() || ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return;
	}
	$cheapest = cc_vendor_cheapest_unit_price( $product );
	if ( $cheapest === null ) {
		return;
	}
	$product->set_price( $cheapest );
	$product->set_regular_price( $cheapest );
}

/**
 * @param WC_Product $product Product in the loop / single.
 */
function cc_vendor_cheapest_stamp_global_product() {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return;
	}
	global $product;
	if ( $product && is_a( $product, 'WC_Product' ) ) {
		cc_vendor_cheapest_stamp_product( $product );
	}
}
add_action( 'woocommerce_before_shop_loop_item', 'cc_vendor_cheapest_stamp_global_product', 1 );
add_action( 'woocommerce_before_single_product', 'cc_vendor_cheapest_stamp_global_product', 1 );
add_action( 'woocommerce_before_single_product_summary', 'cc_vendor_cheapest_stamp_global_product', 1 );
add_action( 'the_post', 'cc_vendor_cheapest_stamp_from_post', 25 );

/**
 * @param WP_Post $post Current post.
 */
function cc_vendor_cheapest_stamp_from_post( $post ) {
	if ( ! $post || (string) $post->post_type !== 'product' || ! function_exists( 'wc_get_product' ) ) {
		return;
	}
	$product = wc_get_product( $post->ID );
	if ( $product ) {
		cc_vendor_cheapest_stamp_product( $product );
		$GLOBALS['product'] = $product;
	}
}

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
	// Do NOT store timestamps here — they make every Add-to-cart a unique line.
	if ( in_array( $vendor, array( 'amazon', 'gt' ), true ) && $price !== null && (float) $price > 0 ) {
		$cart_item_data['cc_vendor']          = $vendor;
		$cart_item_data['cc_vendor_price']    = (float) $price;
		$cart_item_data['cc_vendor_currency'] = 'CAD';
		$cart_item_data['cc_vendor_error']    = '';
		$cart_item_data['cc_cheapest_vendor'] = $vendor;
		$cart_item_data['cc_cheapest_price']  = (float) $price;

		$rows = function_exists( 'cc_vendor_prices_get_for_product' ) ? cc_vendor_prices_get_for_product( $pid ) : array();
		foreach ( $rows as $row ) {
			if ( $row && (string) $row->vendor === $vendor && ! empty( $row->vendor_sku ) ) {
				$cart_item_data['cc_vendor_id'] = (string) $row->vendor_sku;
				break;
			}
		}
	} else {
		$cart_item_data['cc_cheapest_vendor'] = 'woo';
		if ( $price !== null && (float) $price > 0 ) {
			$cart_item_data['cc_cheapest_price'] = (float) $price;
		}
		// Clear any stale manual vendor selection.
		unset(
			$cart_item_data['cc_vendor'],
			$cart_item_data['cc_vendor_price'],
			$cart_item_data['cc_vendor_id'],
			$cart_item_data['cc_vendor_error'],
			$cart_item_data['cc_vendor_currency'],
			$cart_item_data['cc_vendor_fetched_at']
		);
	}

	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'cc_vendor_cheapest_add_cart_item_data', 25, 3 );

/**
 * Keep cheapest / vendor keys in session so cart + checkout stay consistent.
 *
 * @param array $cart_item Cart item.
 * @param array $values    Session values.
 * @return array
 */
function cc_vendor_cheapest_get_cart_item_from_session( $cart_item, $values ) {
	foreach ( array(
		'cc_cheapest_vendor',
		'cc_cheapest_price',
		'cc_vendor',
		'cc_vendor_price',
		'cc_vendor_id',
		'cc_vendor_currency',
		'cc_vendor_error',
	) as $key ) {
		if ( isset( $values[ $key ] ) ) {
			$cart_item[ $key ] = $values[ $key ];
		}
	}
	return $cart_item;
}
add_filter( 'woocommerce_get_cart_item_from_session', 'cc_vendor_cheapest_get_cart_item_from_session', 25, 2 );

/**
 * Unit price for a cart line: cheapest vendor if set, else Woo product price.
 *
 * @param array           $cart_item Cart item.
 * @param WC_Product|null $product   Product object.
 * @return float|null
 */
function cc_vendor_cheapest_cart_unit_price( $cart_item, $product = null ) {
	if ( isset( $cart_item['cc_vendor_price'] ) && $cart_item['cc_vendor_price'] !== '' && empty( $cart_item['cc_vendor_error'] ) ) {
		$price = (float) $cart_item['cc_vendor_price'];
		if ( $price > 0 ) {
			return $price;
		}
	}
	if ( isset( $cart_item['cc_cheapest_price'] ) && $cart_item['cc_cheapest_price'] !== '' ) {
		$price = (float) $cart_item['cc_cheapest_price'];
		if ( $price > 0 ) {
			return $price;
		}
	}
	if ( ! $product && ! empty( $cart_item['data'] ) && is_a( $cart_item['data'], 'WC_Product' ) ) {
		$product = $cart_item['data'];
	}
	if ( $product && is_a( $product, 'WC_Product' ) ) {
		$cheapest = cc_vendor_cheapest_unit_price( $product );
		if ( $cheapest !== null ) {
			return $cheapest;
		}
		$woo = $product->get_price( 'edit' );
		if ( $woo !== '' && $woo !== null && is_numeric( $woo ) && (float) $woo > 0 ) {
			return (float) $woo;
		}
	}
	return null;
}

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
		$price = cc_vendor_cheapest_cart_unit_price( $item, $item['data'] );
		if ( $price === null || $price <= 0 ) {
			continue;
		}
		$item['data']->set_price( $price );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'cc_vendor_cheapest_apply_cart_prices', 25 );

/**
 * Cart / mini-cart displayed unit price → cheapest (or Woo fallback).
 *
 * @param string $price_html HTML.
 * @param array  $cart_item  Cart item.
 * @param string $cart_key   Cart key.
 * @return string
 */
function cc_vendor_cheapest_cart_item_price_html( $price_html, $cart_item, $cart_key ) {
	$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
	$unit    = cc_vendor_cheapest_cart_unit_price( $cart_item, $product );
	if ( $unit === null || ! $product ) {
		return $price_html;
	}
	return wc_price( $unit );
}
add_filter( 'woocommerce_cart_item_price', 'cc_vendor_cheapest_cart_item_price_html', 30, 3 );
