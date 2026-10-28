<?php
/**
 * Review order table (child theme) — shows vendor + unit price from cart selection.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 5.2.0
 */

defined( 'ABSPATH' ) || exit;
?>
<table class="shop_table woocommerce-checkout-review-order-table cc-checkout-review-table">
	<thead>
		<tr>
			<th class="product-name"><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
			<th class="product-vendor"><?php esc_html_e( 'Vendor', 'adex360' ); ?></th>
			<th class="product-unit"><?php esc_html_e( 'Unit price', 'adex360' ); ?></th>
			<th class="product-quantity"><?php esc_html_e( 'Qty', 'adex360' ); ?></th>
			<th class="product-total"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		do_action( 'woocommerce_review_order_before_cart_contents' );

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

			if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
				$vendor_key   = isset( $cart_item['cc_vendor'] ) ? sanitize_key( (string) $cart_item['cc_vendor'] ) : '';
				$vendor_label = ( $vendor_key && function_exists( 'cc_cart_vendor_label' ) )
					? cc_cart_vendor_label( $vendor_key )
					: '';
				$vendor_id    = isset( $cart_item['cc_vendor_id'] ) ? (string) $cart_item['cc_vendor_id'] : '';
				$unit_price   = null;
				if ( isset( $cart_item['cc_vendor_price'] ) && $cart_item['cc_vendor_price'] !== '' && empty( $cart_item['cc_vendor_error'] ) ) {
					$unit_price = (float) $cart_item['cc_vendor_price'];
				} elseif ( $_product ) {
					$unit_price = (float) $_product->get_price();
				}
				?>
				<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">
					<td class="product-name" data-title="<?php esc_attr_e( 'Product', 'woocommerce' ); ?>">
						<?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key ) ); ?>
						<?php echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</td>
					<td class="product-vendor" data-title="<?php esc_attr_e( 'Vendor', 'adex360' ); ?>">
						<?php if ( $vendor_label ) : ?>
							<span class="cc-checkout-vendor-badge"><?php echo esc_html( $vendor_label ); ?></span>
							<?php if ( $vendor_id !== '' ) : ?>
								<span class="cc-checkout-vendor-id"><?php echo esc_html( $vendor_id ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							<span class="cc-checkout-vendor-muted"><?php esc_html_e( 'Not selected', 'adex360' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="product-unit" data-title="<?php esc_attr_e( 'Unit price', 'adex360' ); ?>">
						<?php
						echo $unit_price !== null
							? wp_kses_post( wc_price( $unit_price ) )
							: '&mdash;';
						?>
					</td>
					<td class="product-quantity" data-title="<?php esc_attr_e( 'Qty', 'adex360' ); ?>">
						<?php echo apply_filters( 'woocommerce_checkout_cart_item_quantity', esc_html( (string) $cart_item['quantity'] ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</td>
					<td class="product-total" data-title="<?php esc_attr_e( 'Subtotal', 'woocommerce' ); ?>">
						<?php echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</td>
				</tr>
				<?php
			}
		}

		do_action( 'woocommerce_review_order_after_cart_contents' );
		?>
	</tbody>
	<tfoot>

		<tr class="cart-subtotal">
			<th colspan="4"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
			<td><?php wc_cart_totals_subtotal_html(); ?></td>
		</tr>

		<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
			<tr class="cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
				<th colspan="4"><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
				<td><?php wc_cart_totals_coupon_html( $coupon ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>

			<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>

			<?php wc_cart_totals_shipping_html(); ?>

			<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>

		<?php endif; ?>

		<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
			<tr class="fee">
				<th colspan="4"><?php echo esc_html( $fee->name ); ?></th>
				<td><?php wc_cart_totals_fee_html( $fee ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) : ?>
			<?php if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) : ?>
				<?php foreach ( WC()->cart->get_tax_totals() as $code => $tax ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited ?>
					<tr class="tax-rate tax-rate-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
						<th colspan="4"><?php echo esc_html( $tax->label ); ?></th>
						<td><?php echo wp_kses_post( $tax->formatted_amount ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr class="tax-total">
					<th colspan="4"><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
					<td><?php wc_cart_totals_taxes_total_html(); ?></td>
				</tr>
			<?php endif; ?>
		<?php endif; ?>

		<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>

		<tr class="order-total">
			<th colspan="4"><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
			<td><?php wc_cart_totals_order_total_html(); ?></td>
		</tr>

		<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>

	</tfoot>
</table>
