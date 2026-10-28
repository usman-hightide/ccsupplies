<?php
/**
 * Cart line vendor select (Amazon / Grand & Toy) with live price refresh.
 * Does not place vendor POs — only updates Woo cart line price + meta.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether current user may use cart vendor pricing.
 *
 * @return bool
 */
function cc_cart_vendor_user_can() {
	return is_user_logged_in();
}

/**
 * Allowed vendor keys for cart selection.
 *
 * @return string[]
 */
function cc_cart_vendor_allowed_keys() {
	return array( 'amazon', 'gt' );
}

/**
 * Vendor options available for a product (eligibility-aware).
 *
 * @param int $product_id Product ID.
 * @return array<string, string> key => label
 */
function cc_cart_vendor_options_for_product( $product_id ) {
	$product_id = (int) $product_id;
	$options    = array();

	$amazon_ok = ! function_exists( 'wcss_product_amazon_procurement_allowed' )
		|| wcss_product_amazon_procurement_allowed( $product_id );
	$gt_ok     = ! function_exists( 'wcss_product_gt_procurement_allowed' )
		|| wcss_product_gt_procurement_allowed( $product_id );

	if ( $amazon_ok ) {
		$options['amazon'] = __( 'Amazon', 'adex360' );
	}
	if ( $gt_ok ) {
		$options['gt'] = __( 'Grand & Toy', 'adex360' );
	}

	return $options;
}

/**
 * Human label for a vendor key.
 *
 * @param string $vendor amazon|gt.
 * @return string
 */
function cc_cart_vendor_label( $vendor ) {
	$map = array(
		'amazon' => __( 'Amazon', 'adex360' ),
		'gt'     => __( 'Grand & Toy', 'adex360' ),
	);
	return isset( $map[ $vendor ] ) ? $map[ $vendor ] : $vendor;
}

/**
 * Persist custom cart keys across session.
 *
 * @param array $cart_item Cart item.
 * @param array $values    Session values.
 * @return array
 */
function cc_cart_vendor_get_cart_item_from_session( $cart_item, $values ) {
	foreach ( array( 'cc_vendor', 'cc_vendor_price', 'cc_vendor_currency', 'cc_vendor_id', 'cc_vendor_error', 'cc_vendor_fetched_at' ) as $key ) {
		if ( isset( $values[ $key ] ) ) {
			$cart_item[ $key ] = $values[ $key ];
		}
	}
	return $cart_item;
}
add_filter( 'woocommerce_get_cart_item_from_session', 'cc_cart_vendor_get_cart_item_from_session', 20, 2 );

/**
 * Apply selected vendor unit price to cart lines.
 *
 * @param WC_Cart $cart Cart.
 */
function cc_cart_vendor_apply_prices( $cart ) {
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
		if ( ! isset( $item['cc_vendor_price'] ) || $item['cc_vendor_price'] === '' || $item['cc_vendor_price'] === null ) {
			continue;
		}
		if ( ! empty( $item['cc_vendor_error'] ) ) {
			continue;
		}
		$price = (float) $item['cc_vendor_price'];
		if ( $price < 0 ) {
			continue;
		}
		$item['data']->set_price( $price );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'cc_cart_vendor_apply_prices', 20 );

/**
 * Vendor select under product name (after kses’d name HTML).
 *
 * @param array  $cart_item     Cart item.
 * @param string $cart_item_key Cart key.
 */
function cc_cart_vendor_after_item_name( $cart_item, $cart_item_key ) {
	if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
		return;
	}
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}

	$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
	if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	$product_id = (int) $product->get_id();
	$options    = cc_cart_vendor_options_for_product( $product_id );
	if ( ! $options ) {
		return;
	}

	$selected = isset( $cart_item['cc_vendor'] ) ? sanitize_key( (string) $cart_item['cc_vendor'] ) : '';
	if ( $selected && ! isset( $options[ $selected ] ) ) {
		$selected = '';
	}

	$error = isset( $cart_item['cc_vendor_error'] ) ? (string) $cart_item['cc_vendor_error'] : '';
	$id    = isset( $cart_item['cc_vendor_id'] ) ? (string) $cart_item['cc_vendor_id'] : '';
	?>
	<div
		class="cc-cart-vendor"
		data-cart-key="<?php echo esc_attr( $cart_item_key ); ?>"
		data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
	>
		<label class="cc-cart-vendor__label" for="cc-vendor-<?php echo esc_attr( $cart_item_key ); ?>">
			<?php esc_html_e( 'Vendor', 'adex360' ); ?>
		</label>
		<div class="cc-cart-vendor__control">
			<select
				id="cc-vendor-<?php echo esc_attr( $cart_item_key ); ?>"
				class="cc-cart-vendor__select"
				name="cc_cart_vendor[<?php echo esc_attr( $cart_item_key ); ?>]"
				data-prev="<?php echo esc_attr( $selected ); ?>"
			>
				<option value=""><?php esc_html_e( 'Select vendor…', 'adex360' ); ?></option>
				<?php foreach ( $options as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected, $key ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="cc-cart-vendor__loader" hidden aria-hidden="true">
				<span class="cc-cart-vendor__spinner"></span>
			</span>
		</div>
		<div class="cc-cart-vendor__meta"<?php echo ( $selected || $error ) ? '' : ' hidden'; ?>>
			<?php if ( $selected && ! $error ) : ?>
				<span class="cc-cart-vendor__badge"><?php echo esc_html( cc_cart_vendor_label( $selected ) ); ?></span>
				<?php if ( $id !== '' ) : ?>
					<span class="cc-cart-vendor__id"><?php echo esc_html( $id ); ?></span>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( $error !== '' ) : ?>
				<span class="cc-cart-vendor__error"><?php echo esc_html( $error ); ?></span>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
add_action( 'woocommerce_after_cart_item_name', 'cc_cart_vendor_after_item_name', 20, 2 );

/**
 * Copy vendor choice onto order line items.
 *
 * @param WC_Order_Item_Product $item          Order item.
 * @param string                $cart_item_key Cart key.
 * @param array                 $values        Cart values.
 * @param WC_Order              $order         Order.
 */
function cc_cart_vendor_checkout_line_item( $item, $cart_item_key, $values, $order ) {
	if ( empty( $values['cc_vendor'] ) ) {
		return;
	}
	$vendor = sanitize_key( (string) $values['cc_vendor'] );
	if ( ! in_array( $vendor, cc_cart_vendor_allowed_keys(), true ) ) {
		return;
	}

	$item->add_meta_data( '_cc_vendor', $vendor, true );
	$item->add_meta_data( __( 'Vendor', 'adex360' ), cc_cart_vendor_label( $vendor ), true );

	if ( isset( $values['cc_vendor_price'] ) && $values['cc_vendor_price'] !== '' && empty( $values['cc_vendor_error'] ) ) {
		$item->add_meta_data( '_cc_vendor_price', (float) $values['cc_vendor_price'], true );
	}
	if ( ! empty( $values['cc_vendor_id'] ) ) {
		$item->add_meta_data( '_cc_vendor_id', (string) $values['cc_vendor_id'], true );
		$label = $vendor === 'amazon' ? __( 'ASIN', 'adex360' ) : __( 'G&T part', 'adex360' );
		$item->add_meta_data( $label, (string) $values['cc_vendor_id'], true );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'cc_cart_vendor_checkout_line_item', 20, 4 );

/**
 * Fetch one vendor row for a product.
 *
 * @param int    $product_id Product ID.
 * @param string $vendor     amazon|gt.
 * @return array{ok:bool,price:?float,currency:string,vendor_id:string,error:string,raw:array}
 */
function cc_cart_vendor_fetch_price( $product_id, $vendor ) {
	$product_id = (int) $product_id;
	$vendor     = sanitize_key( $vendor );
	$out        = array(
		'ok'        => false,
		'price'     => null,
		'currency'  => '',
		'vendor_id' => '',
		'error'     => '',
		'raw'       => array(),
	);

	if ( $vendor === 'amazon' ) {
		if ( ! function_exists( 'cc_vendor_fetch_amazon_for_product' ) ) {
			$out['error'] = __( 'Amazon price lookup is unavailable.', 'adex360' );
			return $out;
		}
		$row = cc_vendor_fetch_amazon_for_product( $product_id );
	} elseif ( $vendor === 'gt' ) {
		if ( ! function_exists( 'cc_vendor_fetch_gt_for_product' ) ) {
			$out['error'] = __( 'Grand & Toy price lookup is unavailable.', 'adex360' );
			return $out;
		}
		$row = cc_vendor_fetch_gt_for_product( $product_id, false );
	} else {
		$out['error'] = __( 'Unknown vendor.', 'adex360' );
		return $out;
	}

	$out['raw']       = $row;
	$out['vendor_id'] = (string) ( $row['product_identifier'] ?? '' );
	$out['currency']  = (string) ( $row['currency'] ?? '' );
	$status           = (string) ( $row['status'] ?? '' );

	if ( $status !== 'ok' || ! isset( $row['price'] ) || $row['price'] === null || $row['price'] === '' ) {
		$out['error'] = (string) ( $row['error'] ?? '' );
		if ( $out['error'] === '' ) {
			$out['error'] = __( 'No live price returned for this vendor.', 'adex360' );
		}
		// Friendlier cart copy for G&T edge / Incapsula blocks.
		if ( $vendor === 'gt' && ( false !== stripos( $out['error'], '403' ) || false !== stripos( $out['error'], 'Incapsula' ) ) ) {
			$out['error'] = __( 'Grand & Toy price is unavailable from this network right now. Try Amazon, or retry later from an allowed network.', 'adex360' );
		}
		return $out;
	}

	$out['ok']    = true;
	$out['price'] = (float) $row['price'];
	if ( $out['currency'] === '' ) {
		$out['currency'] = 'CAD';
	}
	return $out;
}

/**
 * AJAX: select vendor on a cart line and refresh price.
 */
function cc_cart_vendor_ajax_select() {
	if ( ! cc_cart_vendor_user_can() ) {
		wp_send_json_error( array( 'message' => __( 'Please log in to select a vendor.', 'adex360' ) ), 403 );
	}
	check_ajax_referer( 'cc_cart_vendor', 'nonce' );

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => __( 'Cart is not available.', 'adex360' ) ), 400 );
	}

	$cart_key   = isset( $_POST['cart_key'] ) ? wc_clean( wp_unslash( $_POST['cart_key'] ) ) : '';
	$vendor     = isset( $_POST['vendor'] ) ? sanitize_key( wp_unslash( $_POST['vendor'] ) ) : '';
	$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

	$cart = WC()->cart->get_cart();
	if ( $cart_key === '' || ! isset( $cart[ $cart_key ] ) ) {
		wp_send_json_error( array( 'message' => __( 'Cart item not found.', 'adex360' ) ), 404 );
	}

	$item = $cart[ $cart_key ];
	$pid  = isset( $item['data'] ) && is_a( $item['data'], 'WC_Product' )
		? (int) $item['data']->get_id()
		: (int) ( $item['product_id'] ?? 0 );
	if ( $product_id > 0 && $pid > 0 && $product_id !== $pid ) {
		wp_send_json_error( array( 'message' => __( 'Product mismatch for cart item.', 'adex360' ) ), 400 );
	}
	$product_id = $pid;

	$options = cc_cart_vendor_options_for_product( $product_id );

	// Clear selection → restore catalog price.
	if ( $vendor === '' ) {
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor'] );
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor_price'] );
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor_currency'] );
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor_id'] );
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor_error'] );
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor_fetched_at'] );
		WC()->cart->set_session();
		WC()->cart->calculate_totals();

		wp_send_json_success(
			array(
				'cleared'      => true,
				'cart_key'     => $cart_key,
				'product_id'   => $product_id,
				'vendor'       => '',
				'price_html'   => '',
				'message'      => __( 'Vendor cleared. Catalog price restored.', 'adex360' ),
				'fragments'    => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
			)
		);
	}

	if ( ! isset( $options[ $vendor ] ) ) {
		wp_send_json_error( array( 'message' => __( 'That vendor is not available for this product.', 'adex360' ) ), 400 );
	}

	$fetched = cc_cart_vendor_fetch_price( $product_id, $vendor );

	if ( empty( $fetched['ok'] ) ) {
		// Do not persist a failed vendor choice on the cart line.
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor'] );
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor_price'] );
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor_currency'] );
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor_id'] );
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor_error'] );
		unset( WC()->cart->cart_contents[ $cart_key ]['cc_vendor_fetched_at'] );
		WC()->cart->set_session();
		WC()->cart->calculate_totals();

		wp_send_json_error(
			array(
				'message'    => $fetched['error'],
				'cart_key'   => $cart_key,
				'product_id' => $product_id,
				'vendor'     => $vendor,
				'vendor_id'  => $fetched['vendor_id'],
			),
			422
		);
	}

	WC()->cart->cart_contents[ $cart_key ]['cc_vendor']            = $vendor;
	WC()->cart->cart_contents[ $cart_key ]['cc_vendor_fetched_at'] = time();
	WC()->cart->cart_contents[ $cart_key ]['cc_vendor_id']         = $fetched['vendor_id'];
	WC()->cart->cart_contents[ $cart_key ]['cc_vendor_currency']   = $fetched['currency'];

	WC()->cart->cart_contents[ $cart_key ]['cc_vendor_price'] = (float) $fetched['price'];
	WC()->cart->cart_contents[ $cart_key ]['cc_vendor_error'] = '';
	WC()->cart->set_session();
	WC()->cart->calculate_totals();

	$unit_html = wc_price( (float) $fetched['price'] );

	wp_send_json_success(
		array(
			'cart_key'     => $cart_key,
			'product_id'   => $product_id,
			'vendor'       => $vendor,
			'vendor_label' => cc_cart_vendor_label( $vendor ),
			'vendor_id'    => $fetched['vendor_id'],
			'price'        => (float) $fetched['price'],
			'currency'     => $fetched['currency'],
			'price_html'   => $unit_html,
			'message'      => sprintf(
				/* translators: 1: vendor name 2: price html */
				__( '%1$s price loaded: %2$s', 'adex360' ),
				cc_cart_vendor_label( $vendor ),
				wp_strip_all_tags( $unit_html )
			),
		)
	);
}
add_action( 'wp_ajax_cc_cart_vendor_select', 'cc_cart_vendor_ajax_select' );

/**
 * Localize cart vendor AJAX for cart page script.
 */
function cc_cart_vendor_localize_script() {
	if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
		return;
	}
	if ( ! wp_script_is( 'cc-cart-ui', 'enqueued' ) ) {
		return;
	}
	wp_localize_script(
		'cc-cart-ui',
		'ccCartVendor',
		array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'cc_cart_vendor' ),
			'loaderDelay'  => 280,
			'i18n'         => array(
				'loading' => __( 'Loading vendor price…', 'adex360' ),
				'error'   => __( 'Could not load vendor price.', 'adex360' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'cc_cart_vendor_localize_script', 40 );
