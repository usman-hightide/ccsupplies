<?php
/**
 * Cart totals — Order Summary (client design).
 *
 * @package adex360-canna-cabana-stationery-child
 * @version 2.3.6
 */

defined( 'ABSPATH' ) || exit;

$item_count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
?>
<div class="cart_totals <?php echo ( WC()->customer->has_calculated_shipping() ) ? 'calculated_shipping' : ''; ?>">

	<?php do_action( 'woocommerce_before_cart_totals' ); ?>

	<h2><?php esc_html_e( 'Order Summary', 'adex360' ); ?></h2>

	<table cellspacing="0" class="shop_table shop_table_responsive">

		<tr class="cart-subtotal">
			<th>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: cart item count */
						_n( 'Subtotal (%d item)', 'Subtotal (%d items)', $item_count, 'adex360' ),
						$item_count
					)
				);
				?>
			</th>
			<td data-title="<?php esc_attr_e( 'Subtotal', 'woocommerce' ); ?>"><?php wc_cart_totals_subtotal_html(); ?></td>
		</tr>

		<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
			<tr class="cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
				<th><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
				<td data-title="<?php echo esc_attr( wc_cart_totals_coupon_label( $coupon, false ) ); ?>"><?php wc_cart_totals_coupon_html( $coupon ); ?></td>
			</tr>
		<?php endforeach; ?>

		<tr class="cc-summary-meta shipping">
			<th>
				<span><?php esc_html_e( 'Shipping', 'woocommerce' ); ?></span>
				<span class="cc-summary-help" title="<?php esc_attr_e( 'Shipping is calculated at checkout.', 'adex360' ); ?>" aria-label="<?php esc_attr_e( 'Shipping is calculated at checkout.', 'adex360' ); ?>">?</span>
			</th>
			<td data-title="<?php esc_attr_e( 'Shipping', 'woocommerce' ); ?>">
				<span class="cc-summary-muted"><?php esc_html_e( 'Calculated at checkout', 'adex360' ); ?></span>
			</td>
		</tr>

		<tr class="cc-summary-meta tax">
			<th>
				<span><?php esc_html_e( 'Taxes', 'adex360' ); ?></span>
				<span class="cc-summary-help" title="<?php esc_attr_e( 'Taxes are calculated at checkout.', 'adex360' ); ?>" aria-label="<?php esc_attr_e( 'Taxes are calculated at checkout.', 'adex360' ); ?>">?</span>
			</th>
			<td data-title="<?php esc_attr_e( 'Taxes', 'adex360' ); ?>">
				<span class="cc-summary-muted"><?php esc_html_e( 'Calculated at checkout', 'adex360' ); ?></span>
			</td>
		</tr>

		<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
			<tr class="fee">
				<th><?php echo esc_html( $fee->name ); ?></th>
				<td data-title="<?php echo esc_attr( $fee->name ); ?>"><?php wc_cart_totals_fee_html( $fee ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php do_action( 'woocommerce_cart_totals_before_order_total' ); ?>

		<tr class="order-total">
			<th><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
			<td data-title="<?php esc_attr_e( 'Total', 'woocommerce' ); ?>"><?php wc_cart_totals_order_total_html(); ?></td>
		</tr>

		<?php do_action( 'woocommerce_cart_totals_after_order_total' ); ?>

	</table>

	<div class="wc-proceed-to-checkout">
		<?php do_action( 'woocommerce_proceed_to_checkout' ); ?>
	</div>

	<?php do_action( 'woocommerce_after_cart_totals' ); ?>

</div>

<aside class="cc-cart-trust" aria-label="<?php esc_attr_e( 'Store benefits', 'adex360' ); ?>">
	<ul class="cc-cart-trust__list">
		<li>
			<span class="cc-cart-trust__icon" aria-hidden="true">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 7h13l3 4v6H3V7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M16 11h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="7.5" cy="18.5" r="1.5" fill="currentColor"/><circle cx="17.5" cy="18.5" r="1.5" fill="currentColor"/></svg>
			</span>
			<span><?php esc_html_e( 'Fast & Reliable Delivery', 'adex360' ); ?></span>
		</li>
		<li>
			<span class="cc-cart-trust__icon" aria-hidden="true">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.8"/><path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
			</span>
			<span><?php esc_html_e( 'Secure Checkout', 'adex360' ); ?></span>
		</li>
		<li>
			<span class="cc-cart-trust__icon" aria-hidden="true">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3c2.5 3 4 5.2 4 8a4 4 0 1 1-8 0c0-2.8 1.5-5 4-8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 21v-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
			</span>
			<span><?php esc_html_e( 'Quality Products', 'adex360' ); ?></span>
		</li>
	</ul>
</aside>
