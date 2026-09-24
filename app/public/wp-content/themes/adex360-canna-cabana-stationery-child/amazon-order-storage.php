<?php
/**
 * Persist Amazon cart/checkout snapshots against a WooCommerce order.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCSS_AMAZON_ORDER_META_KEY', '_wcss_amazon_order_data' );

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

		$title = '';
		if ( ! empty( $item['title'] ) ) {
			$title = (string) $item['title'];
		} elseif ( ! empty( $item['productTitle'] ) ) {
			$title = (string) $item['productTitle'];
		}

		$quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;

		$price = '';
		$currency = 'CAD';
		if ( isset( $item['price']['value'] ) ) {
			$price = (string) $item['price']['value'];
			if ( ! empty( $item['price']['currencyCode'] ) ) {
				$currency = (string) $item['price']['currencyCode'];
			}
		} elseif ( isset( $item['price']['amount'] ) ) {
			$price = (string) $item['price']['amount'];
		} elseif ( isset( $item['unitPrice']['value'] ) ) {
			$price = (string) $item['unitPrice']['value'];
		}

		$buying_option = '';
		if ( ! empty( $item['buyingOptionIdentifier'] ) ) {
			$buying_option = (string) $item['buyingOptionIdentifier'];
		}

		$normalized[] = array(
			'title'                  => $title,
			'asin'                   => $asin,
			'quantity'               => $quantity,
			'price'                  => $price,
			'currency'               => $currency,
			'buying_option_id'       => $buying_option,
			'cart_item_id'           => isset( $item['id'] ) ? (string) $item['id'] : '',
		);
	}

	return $normalized;
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
	return is_array( $data ) && ! empty( $data ) ? $data : null;
}

/**
 * Whether an order has Amazon snapshot data.
 */
function wcss_amazon_order_has_snapshot( int $wc_order_id ): bool {
	return null !== wcss_amazon_get_order_snapshot( $wc_order_id );
}
