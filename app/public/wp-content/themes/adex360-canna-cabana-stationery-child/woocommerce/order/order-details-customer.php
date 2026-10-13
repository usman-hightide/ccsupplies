<?php
/**
 * Order Customer Details
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 8.7.0
 */

defined( 'ABSPATH' ) || exit;

$show_shipping = ! wc_ship_to_billing_address_only() && $order->needs_shipping_address();

/**
 * Render one address card with labeled Name, Address, Phone, and Email rows.
 *
 * @param WC_Order $order Order object.
 * @param string   $type  billing|shipping.
 */
$cc_render_order_address = static function ( $order, $type ) {
	$is_billing = 'billing' === $type;
	$name       = $is_billing
		? trim( $order->get_formatted_billing_full_name() )
		: trim( $order->get_formatted_shipping_full_name() );
	$company    = $is_billing ? $order->get_billing_company() : $order->get_shipping_company();
	$address_1  = $is_billing ? $order->get_billing_address_1() : $order->get_shipping_address_1();
	$address_2  = $is_billing ? $order->get_billing_address_2() : $order->get_shipping_address_2();
	$city       = $is_billing ? $order->get_billing_city() : $order->get_shipping_city();
	$state      = $is_billing ? $order->get_billing_state() : $order->get_shipping_state();
	$postcode   = $is_billing ? $order->get_billing_postcode() : $order->get_shipping_postcode();
	$country    = $is_billing ? $order->get_billing_country() : $order->get_shipping_country();
	$phone      = $is_billing ? $order->get_billing_phone() : $order->get_shipping_phone();
	$email      = $is_billing ? $order->get_billing_email() : '';

	$city_line = trim(
		implode(
			' ',
			array_filter(
				array(
					$city,
					$state,
					$postcode,
				)
			)
		)
	);

	$country_name = '';
	if ( $country && function_exists( 'WC' ) && WC()->countries ) {
		$countries    = WC()->countries->get_countries();
		$country_name = isset( $countries[ $country ] ) ? $countries[ $country ] : $country;
	}

	$lines = array_filter(
		array(
			$company,
			$address_1,
			$address_2,
			$city_line,
			$country_name,
		)
	);
	?>
	<address class="cc-order-address">
		<?php if ( $name ) : ?>
			<div class="cc-addr-row">
				<span class="cc-addr-label"><?php esc_html_e( 'Name', 'woocommerce' ); ?></span>
				<span class="cc-addr-value"><?php echo esc_html( $name ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( $lines ) : ?>
			<div class="cc-addr-row">
				<span class="cc-addr-label"><?php esc_html_e( 'Address', 'woocommerce' ); ?></span>
				<span class="cc-addr-value cc-addr-lines">
					<?php foreach ( $lines as $line ) : ?>
						<span><?php echo esc_html( $line ); ?></span>
					<?php endforeach; ?>
				</span>
			</div>
		<?php elseif ( ! $name ) : ?>
			<div class="cc-addr-row">
				<span class="cc-addr-label"><?php esc_html_e( 'Address', 'woocommerce' ); ?></span>
				<span class="cc-addr-value"><?php esc_html_e( 'N/A', 'woocommerce' ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( $phone ) : ?>
			<div class="cc-addr-row">
				<span class="cc-addr-label"><?php esc_html_e( 'Phone', 'woocommerce' ); ?></span>
				<span class="cc-addr-value"><?php echo esc_html( $phone ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( $email ) : ?>
			<div class="cc-addr-row">
				<span class="cc-addr-label"><?php esc_html_e( 'Email', 'woocommerce' ); ?></span>
				<span class="cc-addr-value"><?php echo esc_html( $email ); ?></span>
			</div>
		<?php endif; ?>

		<?php
		/**
		 * Action hook fired after an address in the order customer details.
		 *
		 * @since 8.7.0
		 * @param string   $address_type Type of address (billing or shipping).
		 * @param WC_Order $order        Order object.
		 */
		do_action( 'woocommerce_order_details_after_customer_address', $type, $order );
		?>
	</address>
	<?php
};
?>
<section class="woocommerce-customer-details">

	<?php if ( $show_shipping ) : ?>

	<section class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">
		<div class="woocommerce-column woocommerce-column--1 woocommerce-column--billing-address col-1">

	<?php endif; ?>

	<h2 class="woocommerce-column__title"><?php esc_html_e( 'Billing address', 'woocommerce' ); ?></h2>

	<?php $cc_render_order_address( $order, 'billing' ); ?>

	<?php if ( $show_shipping ) : ?>

		</div><!-- /.col-1 -->

		<div class="woocommerce-column woocommerce-column--2 woocommerce-column--shipping-address col-2">
			<h2 class="woocommerce-column__title"><?php esc_html_e( 'Shipping address', 'woocommerce' ); ?></h2>
			<?php $cc_render_order_address( $order, 'shipping' ); ?>
		</div><!-- /.col-2 -->

	</section><!-- /.col2-set -->

	<?php endif; ?>

	<?php do_action( 'woocommerce_order_details_after_customer_details', $order ); ?>

</section>
