<?php
/**
 * Grand & Toy procurement helpers (separate from Amazon).
 *
 * Cart is local (user meta). Stage stock/PO use grand-and-toy-cxml.php.
 * Does not touch Amazon cart or Amazon order meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCSS_GT_CART_META_KEY', '_wcss_gt_cart' );
define( 'WCSS_GT_ORDER_META_KEY', '_wcss_gt_order_data' );

/**
 * Manager / Ops — can use G&T procurement cart + stage PO.
 */
function cc_gt_user_can_procure() {
	return is_user_logged_in()
		&& ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) );
}

/**
 * Store employee role (WCSS).
 */
function cc_gt_user_is_store_employee() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$user = wp_get_current_user();
	return $user && in_array( 'store_employee', (array) $user->roles, true );
}

/**
 * Browse G&T catalog: managers + store employees.
 * Store employees request via Woo cart (Internal Requisition) — they cannot place G&T POs.
 */
function cc_gt_user_can_browse() {
	return cc_gt_user_can_procure() || cc_gt_user_is_store_employee();
}

/**
 * Linked store id for the current store employee (0 if none).
 */
function cc_gt_current_store_id() {
	if ( ! is_user_logged_in() ) {
		return 0;
	}
	return (int) get_user_meta( get_current_user_id(), '_wcss_store_id', true );
}

/**
 * Resolve Woo order id from request.
 */
function cc_gt_get_request_order_id() {
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
 * G&T part for a product: explicit meta, else SKU.
 *
 * @param int $product_id Product ID.
 * @return string
 */
function cc_gt_part_for_product( $product_id ) {
	if ( function_exists( 'cc_vendor_gt_part_for_product' ) ) {
		return cc_vendor_gt_part_for_product( $product_id );
	}
	$product_id = (int) $product_id;
	$override   = trim( (string) get_post_meta( $product_id, 'wcss_gt_part_id', true ) );
	if ( $override !== '' ) {
		return $override;
	}
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
	return $product ? trim( (string) $product->get_sku() ) : '';
}

/**
 * @return array<int, array<string, mixed>>
 */
function cc_gt_get_cart() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return array();
	}
	$cart = get_user_meta( $user_id, WCSS_GT_CART_META_KEY, true );
	return is_array( $cart ) ? array_values( $cart ) : array();
}

/**
 * @param array<int, array<string, mixed>> $cart Cart lines.
 */
function cc_gt_save_cart( array $cart ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}
	update_user_meta( $user_id, WCSS_GT_CART_META_KEY, array_values( $cart ) );
}

function cc_gt_clear_cart() {
	$user_id = get_current_user_id();
	if ( $user_id ) {
		delete_user_meta( $user_id, WCSS_GT_CART_META_KEY );
	}
}

/**
 * @param array<string, mixed> $line Line.
 * @return array<string, mixed>|WP_Error
 */
function cc_gt_cart_add_line( array $line ) {
	$part = trim( (string) ( $line['part_id'] ?? '' ) );
	if ( $part === '' ) {
		return new WP_Error( 'gt_part_missing', 'Grand & Toy part ID is required.' );
	}
	$qty = isset( $line['qty'] ) ? max( 1, (int) $line['qty'] ) : 1;
	$key = md5( strtoupper( $part ) );
	$cart = cc_gt_get_cart();
	$found = false;
	foreach ( $cart as &$existing ) {
		if ( ( $existing['key'] ?? '' ) === $key ) {
			$existing['qty'] = max( 1, (int) ( $existing['qty'] ?? 1 ) ) + $qty;
			foreach ( array( 'unit_price', 'facility_id', 'available', 'available_qty', 'days', 'uom', 'name', 'sku', 'product_id' ) as $field ) {
				if ( array_key_exists( $field, $line ) && $line[ $field ] !== '' && $line[ $field ] !== null ) {
					$existing[ $field ] = $line[ $field ];
				}
			}
			$found = true;
			break;
		}
	}
	unset( $existing );
	if ( ! $found ) {
		$cart[] = array(
			'key'            => $key,
			'product_id'     => isset( $line['product_id'] ) ? (int) $line['product_id'] : 0,
			'part_id'        => $part,
			'name'           => (string) ( $line['name'] ?? $part ),
			'sku'            => (string) ( $line['sku'] ?? '' ),
			'qty'            => $qty,
			'uom'            => strtoupper( (string) ( $line['uom'] ?? 'EA' ) ),
			'unit_price'     => isset( $line['unit_price'] ) && is_numeric( $line['unit_price'] ) ? (float) $line['unit_price'] : null,
			'facility_id'    => (string) ( $line['facility_id'] ?? '' ),
			'available'      => (string) ( $line['available'] ?? '' ),
			'available_qty'  => $line['available_qty'] ?? null,
			'days'           => (string) ( $line['days'] ?? '' ),
			'added_at'       => gmdate( 'c' ),
		);
	}
	cc_gt_save_cart( $cart );
	return $cart;
}

/**
 * Search published Woo products for G&T catalog (by name/SKU/part).
 *
 * @param string $term Search term.
 * @param int    $limit Max results.
 * @return array<int, array<string, mixed>>
 */
function cc_gt_search_store_products( $term, $limit = 25 ) {
	$term  = trim( (string) $term );
	$limit = max( 1, min( 50, (int) $limit ) );
	if ( strlen( $term ) < 2 || ! function_exists( 'wc_get_products' ) ) {
		return array();
	}

	$products = wc_get_products(
		array(
			'status' => 'publish',
			'limit'  => $limit,
			's'      => $term,
			'orderby'=> 'title',
			'order'  => 'ASC',
			'return' => 'objects',
		)
	);

	// Also match exact SKU / G&T part meta.
	$extra_ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => '_sku',
					'value'   => $term,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'wcss_gt_part_id',
					'value'   => $term,
					'compare' => 'LIKE',
				),
			),
		)
	);

	$by_id = array();
	foreach ( (array) $products as $product ) {
		if ( $product instanceof WC_Product ) {
			$by_id[ $product->get_id() ] = $product;
		}
	}
	foreach ( (array) $extra_ids as $pid ) {
		if ( isset( $by_id[ $pid ] ) ) {
			continue;
		}
		$p = wc_get_product( (int) $pid );
		if ( $p ) {
			$by_id[ (int) $pid ] = $p;
		}
	}

	$out = array();
	foreach ( $by_id as $product ) {
		$pid  = $product->get_id();
		$part = cc_gt_part_for_product( $pid );
		$elig = function_exists( 'wcss_get_procurement_eligibility' )
			? wcss_get_procurement_eligibility( $pid )
			: 'unset';
		if ( $elig === 'amazon' ) {
			continue; // Amazon-only products stay off the G&T catalog.
		}
		$out[] = array(
			'id'          => $pid,
			'name'        => $product->get_name(),
			'sku'         => (string) $product->get_sku(),
			'gt_part'     => $part,
			'eligibility' => $elig,
			'price'       => $product->get_price(),
			'permalink'   => get_permalink( $pid ),
		);
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}

/**
 * Run stage stock check for one part and normalize first line.
 *
 * @param string $part_id Part.
 * @param string $facility Optional facility postal.
 * @param string $uom UOM.
 * @return array<string, mixed>
 */
function cc_gt_live_stock_for_part( $part_id, $facility = '', $uom = '' ) {
	$part_id = trim( (string) $part_id );
	if ( $part_id === '' ) {
		return array(
			'ok'    => false,
			'error' => 'Part ID is required.',
		);
	}
	if ( ! function_exists( 'cc_gt_stock_check' ) ) {
		return array(
			'ok'    => false,
			'error' => 'Grand & Toy cXML client is not loaded.',
		);
	}

	$facilities = array();
	$facility   = strtoupper( preg_replace( '/\s+/', '', (string) $facility ) );
	if ( $facility !== '' ) {
		$facilities[] = $facility;
	}

	$item = array(
		'supplier_part_id' => $part_id,
		'quantity'         => 1,
		'line_number'      => 1,
	);
	if ( $uom !== '' ) {
		$item['uom'] = strtoupper( $uom );
	}

	$live = cc_gt_stock_check( array( $item ), $facilities );
	if ( empty( $live['ok'] ) ) {
		$errors = array_filter( array_map( 'strval', (array) ( $live['errors'] ?? array() ) ) );
		return array(
			'ok'        => false,
			'http_code' => isset( $live['http_code'] ) ? (int) $live['http_code'] : 0,
			'error'     => $errors ? implode( '; ', $errors ) : 'Stock check failed.',
			'raw'       => $live,
		);
	}

	$lines = (array) ( $live['parsed']['items'] ?? $live['parsed']['lines'] ?? array() );
	$match = null;
	foreach ( $lines as $line ) {
		$line_part = trim( (string) ( $line['supplier_part_id'] ?? '' ) );
		if ( $line_part === '' || strcasecmp( $line_part, $part_id ) === 0 ) {
			$match = $line;
			break;
		}
	}
	if ( ! is_array( $match ) ) {
		return array(
			'ok'    => false,
			'error' => 'No matching line in PriceAvailabilityResponse.',
			'raw'   => $live,
		);
	}

	$positions = (array) ( $match['positions'] ?? array() );
	$pos       = ! empty( $positions[0] ) && is_array( $positions[0] ) ? $positions[0] : array();

	$price = null;
	if ( isset( $match['unit_price'] ) && is_numeric( $match['unit_price'] ) ) {
		$price = (float) $match['unit_price'];
	}

	return array(
		'ok'            => true,
		'http_code'     => isset( $live['http_code'] ) ? (int) $live['http_code'] : 200,
		'part_id'       => $part_id,
		'unit_price'    => $price,
		'currency'      => 'CAD',
		'uom'           => (string) ( $match['uom'] ?? $uom ),
		'facility_id'   => (string) ( $pos['facility_id'] ?? $facility ),
		'available'     => (string) ( $pos['available'] ?? '' ),
		'available_qty' => $pos['quantity'] ?? null,
		'days'          => (string) ( $pos['available_days'] ?? '' ),
		'raw'           => $live,
	);
}

/**
 * Persist G&T order snapshot on a Woo order (Amazon-style merge).
 *
 * @param int                  $order_id Order ID.
 * @param array<string, mixed> $data     Snapshot.
 * @return bool
 */
function cc_gt_save_order_data( $order_id, array $data ) {
	$order_id = (int) $order_id;
	if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
		return false;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return false;
	}

	$existing = $order->get_meta( WCSS_GT_ORDER_META_KEY, true );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}

	$snapshot = array_merge(
		$existing,
		$data,
		array(
			'wc_order_id' => $order_id,
			'updated_at'  => gmdate( 'c' ),
		)
	);
	if ( empty( $snapshot['created_at'] ) ) {
		$snapshot['created_at'] = gmdate( 'c' );
	}

	// Don't let a later cart sync overwrite a final stage PO result.
	if (
		! empty( $existing['status'] )
		&& in_array( $existing['status'], array( 'submitted', 'failed' ), true )
		&& ! empty( $data['status'] )
		&& in_array( $data['status'], array( 'cart_saved', 'checkout_ready' ), true )
	) {
		$snapshot['status']    = $existing['status'];
		$snapshot['gt_result'] = $existing['gt_result'] ?? ( $snapshot['gt_result'] ?? null );
		$snapshot['po_id']     = $existing['po_id'] ?? ( $snapshot['po_id'] ?? '' );
	}

	$order->update_meta_data( WCSS_GT_ORDER_META_KEY, $snapshot );
	$order->save();
	return true;
}

/**
 * Mirror current G&T cart onto a linked Woo order (like Amazon cart_saved).
 *
 * @param int $order_id Woo order ID.
 * @return bool
 */
function cc_gt_sync_cart_snapshot_to_order( $order_id ) {
	$order_id = (int) $order_id;
	if ( $order_id <= 0 ) {
		return false;
	}
	$cart = cc_gt_get_cart();
	return cc_gt_save_order_data(
		$order_id,
		array(
			'status'     => $cart ? 'cart_saved' : 'empty',
			'source'     => 'gt_manager_cart',
			'cart_items' => $cart,
			'stage_only' => true,
		)
	);
}

/**
 * @param int $order_id Order ID.
 * @return array<string, mixed>|null
 */
function cc_gt_get_order_data( $order_id ) {
	$order_id = (int) $order_id;
	if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
		return null;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return null;
	}
	$data = $order->get_meta( WCSS_GT_ORDER_META_KEY, true );
	return is_array( $data ) ? $data : null;
}

/**
 * Ensure private WP pages exist for G&T templates.
 */
function cc_gt_ensure_pages() {
	if ( get_option( 'cc_gt_pages_ready' ) ) {
		return;
	}
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$pages = array(
		'gt-catalog'  => array(
			'title'    => 'Grand & Toy Catalog',
			'template' => 'gt-catalog.php',
		),
		'gt-cart'     => array(
			'title'    => 'Grand & Toy Cart',
			'template' => 'gt-cart.php',
		),
		'gt-checkout' => array(
			'title'    => 'Grand & Toy Checkout',
			'template' => 'gt-checkout.php',
		),
	);
	foreach ( $pages as $slug => $cfg ) {
		$existing = get_page_by_path( $slug );
		if ( $existing ) {
			$tpl = get_page_template_slug( $existing->ID );
			if ( $tpl !== $cfg['template'] ) {
				update_post_meta( $existing->ID, '_wp_page_template', $cfg['template'] );
			}
			// Private pages 404 for store_employee; keep published so PHP can gate.
			if ( $existing->post_status === 'private' ) {
				wp_update_post(
					array(
						'ID'          => $existing->ID,
						'post_status' => 'publish',
					)
				);
			}
			continue;
		}
		// Publish (not private): store_employee cannot read_private_pages; PHP gates access.
		$id = wp_insert_post(
			array(
				'post_title'  => $cfg['title'],
				'post_name'   => $slug,
				'post_status' => 'publish',
				'post_type'   => 'page',
				'post_author' => get_current_user_id() ?: 1,
			),
			true
		);
		if ( ! is_wp_error( $id ) && $id ) {
			update_post_meta( (int) $id, '_wp_page_template', $cfg['template'] );
		}
	}
	update_option( 'cc_gt_pages_ready', 1, false );
}
add_action( 'init', 'cc_gt_ensure_pages', 30 );

/**
 * AJAX auth for G&T endpoints.
 * Page HTML is sometimes edge-cached with a stale nonce; check_ajax_referer() would
 * die(-1) and the UI shows "Search failed." Allow same-origin logged-in browse users.
 */
function cc_gt_verify_ajax_request() {
	if ( ! is_user_logged_in() || ! cc_gt_user_can_browse() ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $nonce && wp_verify_nonce( $nonce, 'cc_gt_procure' ) ) {
		return;
	}
	$ref  = wp_get_referer();
	$host = $ref ? (string) wp_parse_url( $ref, PHP_URL_HOST ) : '';
	$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( $host && $home && strtolower( $host ) === strtolower( $home ) ) {
		return;
	}
	wp_send_json_error(
		array( 'message' => 'Session expired. Hard-refresh the page (Cmd+Shift+R) and try again.' ),
		403
	);
}

/**
 * Same as cc_gt_verify_ajax_request but requires manager procure capability.
 */
function cc_gt_verify_ajax_procure() {
	if ( ! is_user_logged_in() || ! cc_gt_user_can_procure() ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $nonce && wp_verify_nonce( $nonce, 'cc_gt_procure' ) ) {
		return;
	}
	$ref  = wp_get_referer();
	$host = $ref ? (string) wp_parse_url( $ref, PHP_URL_HOST ) : '';
	$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( $host && $home && strtolower( $host ) === strtolower( $home ) ) {
		return;
	}
	wp_send_json_error(
		array( 'message' => 'Session expired. Hard-refresh the page (Cmd+Shift+R) and try again.' ),
		403
	);
}

/**
 * AJAX: fresh nonce (page HTML can be CDN-cached with a stale one).
 */
function cc_gt_ajax_nonce() {
	if ( ! is_user_logged_in() || ! cc_gt_user_can_browse() ) {
		wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	}
	wp_send_json_success( array( 'nonce' => wp_create_nonce( 'cc_gt_procure' ) ) );
}
add_action( 'wp_ajax_cc_gt_nonce', 'cc_gt_ajax_nonce' );

/**
 * AJAX: search products.
 */
function cc_gt_ajax_search() {
	cc_gt_verify_ajax_request();
	$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
	wp_send_json_success( array( 'products' => cc_gt_search_store_products( $term ) ) );
}
add_action( 'wp_ajax_cc_gt_search', 'cc_gt_ajax_search' );

/**
 * AJAX: stock check one part.
 */
function cc_gt_ajax_stock() {
	cc_gt_verify_ajax_request();
	$part     = isset( $_POST['part_id'] ) ? sanitize_text_field( wp_unslash( $_POST['part_id'] ) ) : '';
	$facility = isset( $_POST['facility'] ) ? sanitize_text_field( wp_unslash( $_POST['facility'] ) ) : '';
	$uom      = isset( $_POST['uom'] ) ? sanitize_text_field( wp_unslash( $_POST['uom'] ) ) : '';
	$result   = cc_gt_live_stock_for_part( $part, $facility, $uom );
	if ( empty( $result['ok'] ) ) {
		wp_send_json_error( $result, 502 );
	}
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_cc_gt_stock', 'cc_gt_ajax_stock' );

/**
 * AJAX: store employee adds Woo product to store cart (Internal Requisition path).
 */
function cc_gt_ajax_woo_cart_add() {
	cc_gt_verify_ajax_request();

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => 'Store cart is not available.' ), 500 );
	}

	$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$qty        = isset( $_POST['qty'] ) ? max( 1, absint( $_POST['qty'] ) ) : 1;
	$part_id    = isset( $_POST['part_id'] ) ? sanitize_text_field( wp_unslash( $_POST['part_id'] ) ) : '';

	$product = $product_id ? wc_get_product( $product_id ) : false;
	if ( ! $product || 'publish' !== $product->get_status() ) {
		wp_send_json_error( array( 'message' => 'Product not found.' ), 404 );
	}

	$elig = function_exists( 'wcss_get_procurement_eligibility' )
		? wcss_get_procurement_eligibility( $product_id )
		: 'unset';
	if ( $elig === 'amazon' ) {
		wp_send_json_error( array( 'message' => 'This product is Amazon-only.' ), 400 );
	}

	$cart_item_data = array(
		'wcss_requested_via' => 'gt',
		'wcss_gt_part_id'    => $part_id !== '' ? $part_id : cc_gt_part_for_product( $product_id ),
	);

	$key = WC()->cart->add_to_cart( $product_id, $qty, 0, array(), $cart_item_data );
	if ( ! $key ) {
		wp_send_json_error( array( 'message' => 'Could not add to store cart.' ), 400 );
	}

	wp_send_json_success(
		array(
			'message'   => 'Added to your store cart.',
			'cart_url'  => wc_get_cart_url(),
			'count'     => WC()->cart->get_cart_contents_count(),
			'cart_key'  => $key,
		)
	);
}
add_action( 'wp_ajax_cc_gt_woo_cart_add', 'cc_gt_ajax_woo_cart_add' );

/**
 * Show G&T request marker in Woo cart/checkout.
 *
 * @param array $item_data Item data rows.
 * @param array $cart_item Cart item.
 * @return array
 */
function cc_gt_cart_item_display_meta( $item_data, $cart_item ) {
	if ( ! empty( $cart_item['wcss_requested_via'] ) && $cart_item['wcss_requested_via'] === 'gt' ) {
		$item_data[] = array(
			'key'   => __( 'Requested via', 'adex360' ),
			'value' => __( 'Grand & Toy catalog', 'adex360' ),
		);
	}
	if ( ! empty( $cart_item['wcss_gt_part_id'] ) ) {
		$item_data[] = array(
			'key'   => __( 'G&T part', 'adex360' ),
			'value' => $cart_item['wcss_gt_part_id'],
		);
	}
	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'cc_gt_cart_item_display_meta', 10, 2 );

/**
 * Persist G&T request markers onto order line items.
 *
 * @param WC_Order_Item_Product $item          Order item.
 * @param string                $cart_item_key Cart key.
 * @param array                 $values        Cart values.
 */
function cc_gt_checkout_copy_cart_item_meta( $item, $cart_item_key, $values ) {
	if ( ! empty( $values['wcss_requested_via'] ) ) {
		$item->add_meta_data( '_wcss_requested_via', sanitize_key( $values['wcss_requested_via'] ), true );
	}
	if ( ! empty( $values['wcss_gt_part_id'] ) ) {
		$item->add_meta_data( '_wcss_gt_part_id', sanitize_text_field( $values['wcss_gt_part_id'] ), true );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'cc_gt_checkout_copy_cart_item_meta', 10, 3 );

/**
 * G&T pages embed per-user AJAX nonces — never let CDN/page cache serve them.
 */
function cc_gt_nocache_pages() {
	if ( ! is_page( array( 'gt-catalog', 'gt-cart', 'gt-checkout' ) ) ) {
		return;
	}
	nocache_headers();
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
}
add_action( 'template_redirect', 'cc_gt_nocache_pages', 0 );

/**
 * Store employees order via Explore products (Woo shop) only.
 * Do not surface a separate My Account → Grand & Toy supplies link.
 */

/**
 * AJAX: add to G&T cart.
 */
function cc_gt_ajax_cart_add() {
	cc_gt_verify_ajax_procure();
	$order_id = isset( $_POST['wc_order_id'] ) ? absint( $_POST['wc_order_id'] ) : 0;
	$line     = array(
		'product_id'    => isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0,
		'part_id'       => isset( $_POST['part_id'] ) ? sanitize_text_field( wp_unslash( $_POST['part_id'] ) ) : '',
		'name'          => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
		'sku'           => isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '',
		'qty'           => isset( $_POST['qty'] ) ? absint( $_POST['qty'] ) : 1,
		'uom'           => isset( $_POST['uom'] ) ? sanitize_text_field( wp_unslash( $_POST['uom'] ) ) : 'EA',
		'unit_price'    => isset( $_POST['unit_price'] ) ? wp_unslash( $_POST['unit_price'] ) : null, // phpcs:ignore
		'facility_id'   => isset( $_POST['facility_id'] ) ? sanitize_text_field( wp_unslash( $_POST['facility_id'] ) ) : '',
		'available'     => isset( $_POST['available'] ) ? sanitize_text_field( wp_unslash( $_POST['available'] ) ) : '',
		'available_qty' => isset( $_POST['available_qty'] ) ? sanitize_text_field( wp_unslash( $_POST['available_qty'] ) ) : '',
		'days'          => isset( $_POST['days'] ) ? sanitize_text_field( wp_unslash( $_POST['days'] ) ) : '',
	);
	$cart = cc_gt_cart_add_line( $line );
	if ( is_wp_error( $cart ) ) {
		wp_send_json_error( array( 'message' => $cart->get_error_message() ), 400 );
	}
	if ( $order_id > 0 ) {
		cc_gt_sync_cart_snapshot_to_order( $order_id );
	}
	wp_send_json_success(
		array(
			'cart'  => $cart,
			'count' => count( $cart ),
		)
	);
}
add_action( 'wp_ajax_cc_gt_cart_add', 'cc_gt_ajax_cart_add' );

/**
 * AJAX: update/remove cart line.
 */
function cc_gt_ajax_cart_update() {
	cc_gt_verify_ajax_procure();
	$order_id = isset( $_POST['wc_order_id'] ) ? absint( $_POST['wc_order_id'] ) : 0;
	$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$qty      = isset( $_POST['qty'] ) ? (int) $_POST['qty'] : 0;
	$cart     = cc_gt_get_cart();
	$next     = array();
	foreach ( $cart as $line ) {
		if ( ( $line['key'] ?? '' ) !== $key ) {
			$next[] = $line;
			continue;
		}
		if ( $qty > 0 ) {
			$line['qty'] = $qty;
			$next[]      = $line;
		}
	}
	cc_gt_save_cart( $next );
	if ( $order_id > 0 ) {
		cc_gt_sync_cart_snapshot_to_order( $order_id );
	}
	wp_send_json_success( array( 'cart' => $next, 'count' => count( $next ) ) );
}
add_action( 'wp_ajax_cc_gt_cart_update', 'cc_gt_ajax_cart_update' );

/**
 * AJAX: submit stage PO from cart.
 */
function cc_gt_ajax_place_order() {
	cc_gt_verify_ajax_procure();

	if ( ! function_exists( 'cc_gt_submit_order' ) ) {
		wp_send_json_error( array( 'message' => 'Grand & Toy cXML client is not loaded.' ), 500 );
	}

	$order_id = isset( $_POST['wc_order_id'] ) ? absint( $_POST['wc_order_id'] ) : 0;
	$sender   = isset( $_POST['sender'] ) ? sanitize_text_field( wp_unslash( $_POST['sender'] ) ) : '588';
	$backorder = isset( $_POST['backorder'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['backorder'] ) ) ) : 'BO';
	if ( ! in_array( $backorder, array( 'BO', 'FP' ), true ) ) {
		$backorder = 'BO';
	}

	$cart = cc_gt_get_cart();
	if ( ! $cart ) {
		wp_send_json_error( array( 'message' => 'Grand & Toy cart is empty.' ), 400 );
	}

	$ship = array(
		'name'        => isset( $_POST['ship_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ship_name'] ) ) : 'Canna Cabana',
		'deliver_to'  => isset( $_POST['ship_deliver_to'] ) ? sanitize_text_field( wp_unslash( $_POST['ship_deliver_to'] ) ) : '',
		'streets'     => array_filter(
			array(
				isset( $_POST['ship_street1'] ) ? sanitize_text_field( wp_unslash( $_POST['ship_street1'] ) ) : '',
				isset( $_POST['ship_street2'] ) ? sanitize_text_field( wp_unslash( $_POST['ship_street2'] ) ) : '',
			)
		),
		'city'        => isset( $_POST['ship_city'] ) ? sanitize_text_field( wp_unslash( $_POST['ship_city'] ) ) : '',
		'state'       => isset( $_POST['ship_state'] ) ? sanitize_text_field( wp_unslash( $_POST['ship_state'] ) ) : '',
		'postal_code' => isset( $_POST['ship_postal'] ) ? sanitize_text_field( wp_unslash( $_POST['ship_postal'] ) ) : '',
		'country'     => 'CA',
	);

	$lines = array();
	$n     = 1;
	foreach ( $cart as $line ) {
		$price = isset( $line['unit_price'] ) && is_numeric( $line['unit_price'] ) ? (float) $line['unit_price'] : 0;
		$lines[] = array(
			'quantity'         => max( 1, (int) ( $line['qty'] ?? 1 ) ),
			'line_number'      => $n++,
			'supplier_part_id' => (string) ( $line['part_id'] ?? '' ),
			'unit_price'       => (string) $price,
			'uom'              => (string) ( $line['uom'] ?? 'EA' ),
			'description'      => (string) ( $line['name'] ?? '' ),
		);
	}

	$po_id = 'TEST' . gmdate( 'ymdHis' );
	$payload = array(
		'sender_identity'  => $sender !== '' ? $sender : '588',
		'order_id'         => $po_id,
		'backorder_method' => $backorder,
		'ship_to'          => $ship,
		'bill_to'          => $ship,
		'lines'            => $lines,
	);

	$result   = cc_gt_submit_order( $payload );
	$snapshot = array(
		'status'       => ! empty( $result['ok'] ) ? 'submitted' : 'failed',
		'source'       => 'gt_stage_checkout',
		'po_id'        => $po_id,
		'sender'       => $payload['sender_identity'],
		'backorder'    => $backorder,
		'cart_items'   => $cart,
		'ship_to'      => $ship,
		'gt_result'    => array(
			'ok'        => ! empty( $result['ok'] ),
			'http_code' => isset( $result['http_code'] ) ? (int) $result['http_code'] : 0,
			'errors'    => (array) ( $result['errors'] ?? array() ),
			'parsed'    => $result['parsed'] ?? array(),
			'raw'       => $result['raw'] ?? null,
		),
		'wc_order_id'  => $order_id,
		'submitted_at' => gmdate( 'c' ),
		'stage_only'   => true,
	);

	if ( $order_id > 0 ) {
		cc_gt_save_order_data( $order_id, $snapshot );
	}

	if ( empty( $result['ok'] ) ) {
		$errors = array_filter( array_map( 'strval', (array) ( $result['errors'] ?? array() ) ) );
		wp_send_json_error(
			array(
				'message'  => $errors ? implode( '; ', $errors ) : 'Grand & Toy PO failed.',
				'snapshot' => $snapshot,
			),
			502
		);
	}

	cc_gt_clear_cart();
	wp_send_json_success(
		array(
			'message'  => 'Grand & Toy stage PO submitted.',
			'snapshot' => $snapshot,
			'view_url' => $order_id
				? home_url( '/manager/orders/gt-view/' . $order_id )
				: home_url( '/gt-catalog/' ),
		)
	);
}
add_action( 'wp_ajax_cc_gt_place_order', 'cc_gt_ajax_place_order' );
