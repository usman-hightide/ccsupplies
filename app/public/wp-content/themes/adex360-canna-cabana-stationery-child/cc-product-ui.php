<?php
/**
 * Single product page polish + "added to cart" toast (storefront).
 *
 * - Replaces Woo's plain add-to-cart notice with a toast card (product, qty,
 *   cart count / subtotal, View cart + Checkout).
 * - Shows "In stock" instead of the raw stock count to stores.
 * - Assets: cc-product-ui.css / cc-product-ui.js (quantity stepper, toast dismiss).
 *
 * @package adex360-canna-cabana-stationery-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add-to-cart success message as a toast card.
 *
 * @param string    $message  Default message HTML.
 * @param int|array $products Product ID => quantity.
 * @param bool      $show_qty Whether quantities were requested.
 * @return string
 */
function cc_product_ui_add_to_cart_message( $message, $products, $show_qty = false ) {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! is_array( $products ) || ! $products ) {
		return $message;
	}

	$names = array();
	$qty   = 0;
	$thumb = '';
	foreach ( $products as $product_id => $count ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			continue;
		}
		$names[] = $product->get_name();
		$qty    += max( 1, (int) $count );
		if ( $thumb === '' && $product->get_image_id() ) {
			$thumb = (string) wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_gallery_thumbnail' );
		}
	}
	if ( ! $names ) {
		return $message;
	}

	$cart_count = (int) WC()->cart->get_cart_contents_count();

	ob_start();
	?>
	<div class="cc-atc" data-cc-atc>
		<span class="cc-atc__icon" aria-hidden="true"></span>
		<?php if ( $thumb ) : ?>
			<img class="cc-atc__thumb" src="<?php echo esc_url( $thumb ); ?>" alt="">
		<?php endif; ?>
		<div class="cc-atc__body">
			<p class="cc-atc__title"><?php esc_html_e( 'Added to your cart', 'adex360' ); ?></p>
			<p class="cc-atc__text"><strong><?php echo esc_html( implode( ', ', $names ) ); ?></strong> &times; <?php echo (int) $qty; ?></p>
			<p class="cc-atc__meta">
				<?php
				/* translators: %d: number of items in the cart. */
				echo esc_html( sprintf( _n( '%d item in cart', '%d items in cart', $cart_count, 'adex360' ), $cart_count ) );
				?>
				&middot; <?php esc_html_e( 'Subtotal', 'adex360' ); ?> <?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?>
			</p>
			<div class="cc-atc__actions">
				<a class="cc-atc__btn cc-atc__btn--primary" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'View cart', 'adex360' ); ?></a>
				<a class="cc-atc__btn" href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><?php esc_html_e( 'Checkout', 'adex360' ); ?></a>
			</div>
		</div>
		<button type="button" class="cc-atc__close" aria-label="<?php esc_attr_e( 'Dismiss', 'adex360' ); ?>" data-cc-atc-close>&times;</button>
	</div>
	<?php
	return trim( (string) ob_get_clean() );
}
add_filter( 'wc_add_to_cart_message_html', 'cc_product_ui_add_to_cart_message', 20, 3 );

// Stores don't need this on the product page. An empty message means WooCommerce shows nothing.
add_filter( 'woocommerce_cart_product_not_enough_stock_already_in_cart_message', '__return_empty_string' );

/**
 * Stores don't need the raw stock count ("9998 in stock"); keep low-stock warnings.
 *
 * @param string     $text    Availability text.
 * @param WC_Product $product Product.
 * @return string
 */
function cc_product_ui_availability_text( $text, $product ) {
	if ( is_admin() || ! $product || ! $product->is_in_stock() ) {
		return $text;
	}
	$stock = $product->get_stock_quantity();
	if ( $product->managing_stock() && $stock !== null && $stock > 0 && $stock <= 5 ) {
		/* translators: %d: units left. */
		return sprintf( __( 'Only %d left', 'adex360' ), $stock );
	}
	return __( 'In stock', 'adex360' );
}
add_filter( 'woocommerce_get_availability_text', 'cc_product_ui_availability_text', 20, 2 );

/**
 * Enqueue on WooCommerce storefront pages (notices can show on shop + product pages).
 */
function cc_product_ui_enqueue() {
	if ( ! function_exists( 'is_woocommerce' ) || ! is_woocommerce() ) {
		return;
	}
	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();
	wp_enqueue_style( 'cc-product-ui', $uri . '/cc-product-ui.css', array(), (string) filemtime( $dir . '/cc-product-ui.css' ) );
	wp_enqueue_script( 'cc-product-ui', $uri . '/cc-product-ui.js', array(), (string) filemtime( $dir . '/cc-product-ui.js' ), true );
}
add_action( 'wp_enqueue_scripts', 'cc_product_ui_enqueue', 40 );
