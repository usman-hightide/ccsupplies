<?php
/**
 * Cart / checkout UI helpers (steps, labels, qty steppers, design chrome).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shop URL with safe fallback.
 *
 * @return string
 */
function cc_cart_shop_url() {
	$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
	if ( ! $url ) {
		$url = home_url( '/' );
	}
	return $url;
}

/**
 * Handle Clear cart from cart form.
 */
function cc_cart_handle_clear_cart() {
	if ( empty( $_POST['cc_clear_cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}
	if ( ! isset( $_POST['woocommerce-cart-nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce-cart-nonce'] ) ), 'woocommerce-cart' ) ) {
		return;
	}
	if ( function_exists( 'WC' ) && WC()->cart ) {
		WC()->cart->empty_cart();
		wc_add_notice( __( 'Your cart has been cleared.', 'adex360' ), 'success' );
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}
}
add_action( 'wp_loaded', 'cc_cart_handle_clear_cart', 25 );

/**
 * Cart page chrome: breadcrumb + title row with 3-step progress (client design).
 */
function cc_cart_render_page_chrome() {
	if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
		return;
	}

	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;

	$shop_url = cc_cart_shop_url();
	$home_url = home_url( '/' );
	?>
	<header class="cc-cart-pagehead">
		<nav class="cc-cart-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'adex360' ); ?>">
			<ol class="cc-cart-breadcrumb__list">
				<li class="cc-cart-breadcrumb__item">
					<a href="<?php echo esc_url( $home_url ); ?>">
						<svg class="cc-cart-breadcrumb__home" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
						<span><?php esc_html_e( 'Home', 'adex360' ); ?></span>
					</a>
				</li>
				<li class="cc-cart-breadcrumb__item">
					<a href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Shop', 'adex360' ); ?></a>
				</li>
				<li class="cc-cart-breadcrumb__item is-current" aria-current="page">
					<span><?php esc_html_e( 'Cart', 'adex360' ); ?></span>
				</li>
			</ol>
		</nav>

		<div class="cc-cart-pagehead__main">
			<div class="cc-cart-pagehead__titleblock">
				<h1 class="cc-cart-pagehead__title"><?php esc_html_e( 'Your Cart', 'adex360' ); ?></h1>
				<p class="cc-cart-pagehead__lead">
					<?php esc_html_e( 'Review your items and continue to checkout. Prices use the best available vendor rate when on file.', 'adex360' ); ?>
				</p>
			</div>
			<?php cc_cart_render_steps( true ); ?>
		</div>
	</header>
	<?php
}
add_action( 'woocommerce_before_cart', 'cc_cart_render_page_chrome', 1 );
add_action( 'woocommerce_cart_is_empty', 'cc_cart_render_page_chrome', 1 );

/**
 * Hide the theme’s default Cart / Checkout page H1 (replaced by pagehead).
 *
 * @param string $title   Title.
 * @param int    $post_id Post ID.
 * @return string
 */
function cc_cart_suppress_theme_title( $title, $post_id = 0 ) {
	if ( is_admin() ) {
		return $title;
	}
	if ( ! in_the_loop() || ! is_main_query() ) {
		return $title;
	}
	if ( function_exists( 'is_cart' ) && is_cart() && function_exists( 'wc_get_page_id' ) && (int) $post_id === (int) wc_get_page_id( 'cart' ) ) {
		return '';
	}
	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && function_exists( 'wc_get_page_id' ) && (int) $post_id === (int) wc_get_page_id( 'checkout' ) ) {
		return '';
	}
	return $title;
}
add_filter( 'the_title', 'cc_cart_suppress_theme_title', 10, 2 );

/**
 * Checkout page chrome: breadcrumb, back to cart, title, steps (parity with cart).
 */
function cc_checkout_render_page_chrome() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
		return;
	}

	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;

	$shop_url = cc_cart_shop_url();
	$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
	$home_url = home_url( '/' );
	?>
	<header class="cc-cart-pagehead cc-checkout-pagehead">
		<nav class="cc-cart-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'adex360' ); ?>">
			<ol class="cc-cart-breadcrumb__list">
				<li class="cc-cart-breadcrumb__item">
					<a href="<?php echo esc_url( $home_url ); ?>">
						<svg class="cc-cart-breadcrumb__home" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
						<span><?php esc_html_e( 'Home', 'adex360' ); ?></span>
					</a>
				</li>
				<li class="cc-cart-breadcrumb__item">
					<a href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Shop', 'adex360' ); ?></a>
				</li>
				<li class="cc-cart-breadcrumb__item">
					<a href="<?php echo esc_url( $cart_url ); ?>"><?php esc_html_e( 'Cart', 'adex360' ); ?></a>
				</li>
				<li class="cc-cart-breadcrumb__item is-current" aria-current="page">
					<span><?php esc_html_e( 'Checkout', 'adex360' ); ?></span>
				</li>
			</ol>
		</nav>

		<div class="cc-cart-pagehead__main">
			<div class="cc-cart-pagehead__titleblock">
				<a class="cc-checkout-back" href="<?php echo esc_url( $cart_url ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><path d="M15 6 9 12l6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
					<span><?php esc_html_e( 'Back to cart', 'adex360' ); ?></span>
				</a>
				<h1 class="cc-cart-pagehead__title"><?php esc_html_e( 'Checkout', 'adex360' ); ?></h1>
				<p class="cc-cart-pagehead__lead">
					<?php esc_html_e( 'Confirm your details and submit your requisition. Line prices use the best available vendor rate when on file.', 'adex360' ); ?>
				</p>
			</div>
			<?php cc_cart_render_steps( true ); ?>
		</div>
	</header>
	<?php
	cc_checkout_render_alert_region();
}
add_action( 'woocommerce_before_checkout_form', 'cc_checkout_render_page_chrome', 1 );

/**
 * Prominent checkout alert region (notices / blockers) under pagehead.
 */
function cc_checkout_render_alert_region() {
	$all = function_exists( 'wc_get_notices' ) ? wc_get_notices() : array();
	$has = false;
	foreach ( array( 'error', 'success', 'notice' ) as $type ) {
		if ( ! empty( $all[ $type ] ) ) {
			$has = true;
			break;
		}
	}

	$is_error = $has && ! empty( $all['error'] );
	$role     = $is_error ? 'alert' : 'status';
	?>
	<div
		id="cc-checkout-alert"
		class="cc-checkout-alert<?php echo $has ? ' is-visible' : ''; ?><?php echo $is_error ? ' is-error' : ''; ?>"
		role="<?php echo esc_attr( $role ); ?>"
		aria-live="<?php echo $is_error ? 'assertive' : 'polite'; ?>"
		<?php echo $has ? '' : ' hidden'; ?>
	>
		<div class="cc-checkout-alert__inner">
			<span class="cc-checkout-alert__icon" aria-hidden="true">!</span>
			<div class="cc-checkout-alert__body">
				<?php if ( $has ) : ?>
					<p class="cc-checkout-alert__title"><?php esc_html_e( 'Please fix the following to continue', 'adex360' ); ?></p>
					<?php
					// Consume notices here so they are not duplicated elsewhere.
					if ( function_exists( 'wc_clear_notices' ) ) {
						foreach ( array( 'error', 'success', 'notice' ) as $notice_type ) {
							if ( empty( $all[ $notice_type ] ) ) {
								continue;
							}
							echo '<ul class="cc-checkout-alert__list cc-checkout-alert__list--' . esc_attr( $notice_type ) . '">';
							foreach ( $all[ $notice_type ] as $notice ) {
								$msg = is_array( $notice ) && isset( $notice['notice'] ) ? $notice['notice'] : (string) $notice;
								echo '<li>' . wp_kses_post( $msg ) . '</li>';
							}
							echo '</ul>';
						}
						wc_clear_notices();
					}
					?>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Cart → Checkout → Order Placed progress steps.
 *
 * @param bool $embedded When true, render inside pagehead (cart / checkout chrome).
 */
function cc_cart_render_steps( $embedded = false ) {
	if ( ! is_cart() && ! is_checkout() ) {
		return;
	}

	// Steps live inside page chrome only (cart + checkout).
	if ( ! $embedded ) {
		return;
	}

	$on_checkout = is_checkout() && ! is_wc_endpoint_url( 'order-received' );
	$on_thankyou = is_checkout() && is_wc_endpoint_url( 'order-received' );

	$cart_state  = ( $on_checkout || $on_thankyou ) ? ' is-complete' : ' is-active';
	$check_state = $on_thankyou ? ' is-complete' : ( $on_checkout ? ' is-active' : ' is-upcoming' );
	$done_state  = $on_thankyou ? ' is-active' : ' is-upcoming';
	?>
	<nav class="cc-cart-steps" aria-label="<?php esc_attr_e( 'Checkout progress', 'adex360' ); ?>">
		<ol class="cc-cart-steps__list">
			<li class="cc-cart-steps__item<?php echo esc_attr( $cart_state ); ?>"<?php echo ( ! $on_checkout && ! $on_thankyou ) ? ' aria-current="step"' : ''; ?>>
				<a class="cc-cart-steps__link" href="<?php echo esc_url( wc_get_cart_url() ); ?>">
					<span class="cc-cart-steps__num" aria-hidden="true">
						<?php if ( $on_checkout || $on_thankyou ) : ?>
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
					<span class="cc-cart-steps__num" aria-hidden="true">
						<?php if ( $on_thankyou ) : ?>
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12.5 10 17.5 19 7.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<?php else : ?>
							2
						<?php endif; ?>
					</span>
					<span class="cc-cart-steps__label"><?php esc_html_e( 'Checkout', 'adex360' ); ?></span>
				</span>
			</li>
			<li class="cc-cart-steps__item<?php echo esc_attr( $done_state ); ?>"<?php echo $on_thankyou ? ' aria-current="step"' : ''; ?>>
				<span class="cc-cart-steps__link">
					<span class="cc-cart-steps__num" aria-hidden="true">3</span>
					<span class="cc-cart-steps__label"><?php esc_html_e( 'Order Placed', 'adex360' ); ?></span>
				</span>
			</li>
		</ol>
	</nav>
	<?php
}

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
 * Cart “Proceed to checkout” label with lock icon (HTML allowed in button filter below).
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
 * Customize proceed-to-checkout button.
 */
function cc_cart_custom_proceed_to_checkout() {
	$icon = '<svg class="cc-checkout-lock" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
	echo '<a href="' . esc_url( wc_get_checkout_url() ) . '" class="checkout-button button alt wc-forward' . esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ) . '">' . $icon . '<span>' . esc_html__( 'Proceed to checkout →', 'adex360' ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Replace default proceed button on cart.
 */
function cc_cart_swap_proceed_button() {
	if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
		return;
	}
	remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
	add_action( 'woocommerce_proceed_to_checkout', 'cc_cart_custom_proceed_to_checkout', 20 );
}
add_action( 'wp', 'cc_cart_swap_proceed_button' );

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
	$css_path = get_stylesheet_directory() . '/cc-cart-ui.css';
	$js_path  = get_stylesheet_directory() . '/cc-cart-ui.js';
	$ver      = file_exists( $css_path ) ? (string) filemtime( $css_path ) : wp_get_theme()->get( 'Version' );

	wp_enqueue_style(
		'cc-cart-ui',
		get_stylesheet_directory_uri() . '/cc-cart-ui.css',
		array( 'adex360-cannacabana-child-style' ),
		$ver
	);
	wp_enqueue_script(
		'cc-cart-ui',
		get_stylesheet_directory_uri() . '/cc-cart-ui.js',
		array( 'jquery' ),
		file_exists( $js_path ) ? (string) filemtime( $js_path ) : $ver,
		true
	);
	wp_localize_script(
		'cc-cart-ui',
		'ccCartUi',
		array(
			'isCheckout' => is_checkout() && ! is_wc_endpoint_url( 'order-received' ),
			'i18n'       => array(
				'selectedOne'  => __( '%d item selected', 'adex360' ),
				'selectedMany' => __( '%d items selected', 'adex360' ),
				'checkoutErrorTitle' => __( 'Please fix the following to continue', 'adex360' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'cc_cart_enqueue_assets', 30 );

/**
 * Hide default checkout notice wrapper (alerts render in #cc-checkout-alert).
 */
function cc_checkout_body_class( $classes ) {
	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
		$classes[] = 'cc-checkout-alerts-relocated';
	}
	return $classes;
}
add_filter( 'body_class', 'cc_checkout_body_class' );

/**
 * Prefer a clearer empty-cart notice label.
 *
 * @param string $message Empty cart message.
 * @return string
 */
function cc_cart_empty_message( $message ) {
	return __( 'Your cart is currently empty. Add products from the shop to start a requisition.', 'adex360' );
}
add_filter( 'wc_empty_cart_message', 'cc_cart_empty_message' );

/**
 * Return-to-shop button label.
 *
 * @param string $text Button text.
 * @return string
 */
function cc_cart_return_to_shop_text( $text ) {
	return __( 'Continue shopping', 'adex360' );
}
add_filter( 'woocommerce_return_to_shop_text', 'cc_cart_return_to_shop_text' );
