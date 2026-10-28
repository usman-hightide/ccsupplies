<?php
/**
 * Cart / checkout UI helpers (steps, labels, qty steppers).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart → Checkout progress steps.
 */
function cc_cart_render_steps() {
	if ( ! is_cart() && ! is_checkout() ) {
		return;
	}
	$on_checkout = is_checkout() && ! is_wc_endpoint_url( 'order-received' );
	$cart_state  = $on_checkout ? ' is-complete' : ' is-active';
	$check_state = $on_checkout ? ' is-active' : ' is-upcoming';
	?>
	<nav class="cc-cart-steps" aria-label="<?php esc_attr_e( 'Checkout progress', 'adex360' ); ?>">
		<ol class="cc-cart-steps__list">
			<li class="cc-cart-steps__item<?php echo esc_attr( $cart_state ); ?>"<?php echo $on_checkout ? '' : ' aria-current="step"'; ?>>
				<a class="cc-cart-steps__link" href="<?php echo esc_url( wc_get_cart_url() ); ?>">
					<span class="cc-cart-steps__num" aria-hidden="true">
						<?php if ( $on_checkout ) : ?>
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12.5 10 17.5 19 7.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<?php else : ?>
							1
						<?php endif; ?>
					</span>
					<span class="cc-cart-steps__label"><?php esc_html_e( 'Cart', 'adex360' ); ?></span>
				</a>
			</li>
			<li class="cc-cart-steps__item<?php echo esc_attr( $check_state ); ?>"<?php echo $on_checkout ? ' aria-current="step"' : ''; ?>>
				<span class="cc-cart-steps__link">
					<span class="cc-cart-steps__num" aria-hidden="true">2</span>
					<span class="cc-cart-steps__label"><?php esc_html_e( 'Checkout', 'adex360' ); ?></span>
				</span>
			</li>
		</ol>
	</nav>
	<?php
}
add_action( 'woocommerce_before_cart', 'cc_cart_render_steps', 1 );
add_action( 'woocommerce_before_checkout_form', 'cc_cart_render_steps', 1 );

/**
 * Rename cart totals heading.
 *
 * @param string $translated Translated text.
 * @param string $text       Original text.
 * @param string $domain     Text domain.
 * @return string
 */
function cc_cart_rename_totals_heading( $translated, $text, $domain ) {
	if ( 'woocommerce' !== $domain || 'Cart totals' !== $text ) {
		return $translated;
	}
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		return __( 'Order Summary', 'adex360' );
	}
	return $translated;
}
add_filter( 'gettext', 'cc_cart_rename_totals_heading', 20, 3 );

/**
 * Cart “Proceed to checkout” label.
 *
 * @param string $translated Translated text.
 * @param string $text       Original text.
 * @param string $domain     Text domain.
 * @return string
 */
function cc_cart_proceed_button_label( $translated, $text, $domain ) {
	if ( 'woocommerce' === $domain && 'Proceed to checkout' === $text && function_exists( 'is_cart' ) && is_cart() ) {
		return __( 'Proceed to checkout →', 'adex360' );
	}
	return $translated;
}
add_filter( 'gettext', 'cc_cart_proceed_button_label', 20, 3 );

/**
 * “Your order” heading inside #order_review (must be inside the card, not a grid sibling).
 */
function cc_checkout_order_review_title() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}
	echo '<h3 class="cc-checkout-order-title">' . esc_html__( 'Your order', 'woocommerce' ) . '</h3>';
}
add_action( 'woocommerce_checkout_order_review', 'cc_checkout_order_review_title', 1 );

/**
 * Enqueue cart page assets.
 */
function cc_cart_enqueue_assets() {
	if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) ) {
		return;
	}
	$ver = wp_get_theme()->get( 'Version' );
	wp_enqueue_style(
		'cc-cart-ui',
		get_stylesheet_directory_uri() . '/cc-cart-ui.css',
		array( 'adex360-cannacabana-child-style' ),
		$ver
	);
	if ( is_cart() ) {
		wp_enqueue_script(
			'cc-cart-ui',
			get_stylesheet_directory_uri() . '/cc-cart-ui.js',
			array( 'jquery' ),
			$ver,
			true
		);
	}
}
add_action( 'wp_enqueue_scripts', 'cc_cart_enqueue_assets', 30 );
