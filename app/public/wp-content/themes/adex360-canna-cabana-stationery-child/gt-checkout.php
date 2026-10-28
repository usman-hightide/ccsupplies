<?php
/**
 * Template Name: Grand & Toy Checkout
 * Submits a stage PO only — never Amazon, never production G&T host.
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	auth_redirect();
	exit;
}
if ( function_exists( 'cc_gt_user_is_store_employee' ) && cc_gt_user_is_store_employee()
	&& ( ! function_exists( 'cc_gt_user_can_procure' ) || ! cc_gt_user_can_procure() ) ) {
	// Mirror gt-cart: send store employees to Woo checkout, not the manager stage PO form.
	wp_safe_redirect( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ) );
	exit;
}

if ( ! function_exists( 'cc_gt_user_can_procure' ) || ! cc_gt_user_can_procure() ) {
	wp_die( esc_html__( 'You need manager access to place a Grand & Toy order.', 'adex360' ), 403 );
}

$order_id = function_exists( 'cc_gt_get_request_order_id' ) ? cc_gt_get_request_order_id() : 0;
$cart     = function_exists( 'cc_gt_get_cart' ) ? cc_gt_get_cart() : array();
$nonce    = wp_create_nonce( 'cc_gt_procure' );
$order    = ( $order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : false;
$cart_url = $order_id ? add_query_arg( 'order-id', $order_id, home_url( '/gt-cart/' ) ) : home_url( '/gt-cart/' );

$ship_name = 'Canna Cabana';
$ship_to   = '';
$street1   = '';
$street2   = '';
$city      = '';
$state     = '';
$postal    = '';
if ( $order ) {
	$ship_name = $order->get_formatted_billing_full_name() ?: $ship_name;
	$ship_to   = $order->get_billing_company() ?: $order->get_formatted_billing_full_name();
	$street1   = $order->get_billing_address_1();
	$street2   = $order->get_billing_address_2();
	$city      = $order->get_billing_city();
	$state     = $order->get_billing_state();
	$postal    = $order->get_billing_postcode();
}

get_header();
?>
<style>
.gtco{max-width:920px;margin:32px auto 64px;padding:0 20px;font-family:Georgia,"Times New Roman",serif;color:#111}
.gtco h1{margin:0 0 8px;font-size:2rem}
.gtco .badge{display:inline-block;background:#0b6e4f;color:#fff;font-family:system-ui,sans-serif;font-size:.7rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:4px 8px;border-radius:4px;margin-bottom:10px}
.gtco .lead{font-family:system-ui,sans-serif;color:#444;margin:0 0 20px;line-height:1.45}
.gtco .panel{background:#f7faf8;border:1px solid #d5e5dc;border-radius:10px;padding:18px;margin-bottom:16px}
.gtco .panel h2{margin:0 0 12px;font-size:1.1rem}
.gtco label{display:block;font-family:system-ui,sans-serif;font-size:.8rem;font-weight:600;margin:10px 0 4px}
.gtco input,.gtco select{width:100%;max-width:420px;padding:9px 11px;border:1px solid #c5d4cb;border-radius:6px;font-family:system-ui,sans-serif}
.gtco .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:860px}
.gtco table{width:100%;border-collapse:collapse;font-family:system-ui,sans-serif;font-size:.9rem}
.gtco th,.gtco td{padding:10px;border-bottom:1px solid #e0ebe4;text-align:left}
.gtco .actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.gtco .btn,.gtco a.btn,button.btn{display:inline-flex!important;align-items:center;justify-content:center;background:#0b6e4f!important;color:#fff!important;border:0!important;border-radius:6px!important;padding:11px 16px!important;font-family:system-ui,sans-serif!important;font-weight:600!important;cursor:pointer;text-decoration:none!important;min-height:40px;line-height:1.2!important}
.gtco .btn.light,a.btn.light{background:#111!important}
.gtco .warn{background:#fff7e6;border:1px solid #f0d9a8;border-radius:8px;padding:12px 14px;font-family:system-ui,sans-serif;font-size:.88rem;margin-bottom:16px}
.gtco #gtco-msg{font-family:system-ui,sans-serif;margin-top:12px}
.gtco #gtco-msg.ok{color:#0b6e4f}
.gtco #gtco-msg.err{color:#a61b1b}
@media(max-width:720px){.gtco .grid2{grid-template-columns:1fr}}
</style>

<div class="gtco">
	<span class="badge">Grand &amp; Toy · stage only</span>
	<h1>Grand &amp; Toy checkout</h1>
	<p class="lead">
		Places a <strong>stage</strong> purchase order with Grand &amp; Toy. Does not call Amazon.
		<?php if ( $order ) : ?>
			Results save on Woo order <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong>.
		<?php else : ?>
			Tip: open this from Manager → <em>G&amp;T Test Order</em> so results attach to a Woo order.
		<?php endif; ?>
	</p>

	<div class="warn">
		Production Grand &amp; Toy host is blocked in code. SenderIdentity defaults to account bucket sample <code>588</code> until store-email mapping is finalized with Ops.
	</div>

	<?php if ( ! $cart ) : ?>
		<div class="panel">Cart is empty. <a href="<?php echo esc_url( $cart_url ); ?>">Return to cart</a></div>
	<?php else : ?>
		<div class="panel">
			<h2>Order lines</h2>
			<table>
				<thead><tr><th>Item</th><th>Part</th><th>Qty</th><th>Unit price</th></tr></thead>
				<tbody>
					<?php foreach ( $cart as $line ) : ?>
						<tr>
							<td><?php echo esc_html( $line['name'] ?? '' ); ?></td>
							<td><?php echo esc_html( $line['part_id'] ?? '' ); ?></td>
							<td><?php echo esc_html( (string) ( $line['qty'] ?? 1 ) ); ?></td>
							<td><?php echo isset( $line['unit_price'] ) && $line['unit_price'] !== null ? esc_html( $line['unit_price'] . ' CAD' ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="panel">
			<h2>Ship / bill to</h2>
			<div class="grid2">
				<div>
					<label for="ship_name">Company / name</label>
					<input id="ship_name" value="<?php echo esc_attr( $ship_name ); ?>">
					<label for="ship_deliver_to">Deliver to</label>
					<input id="ship_deliver_to" value="<?php echo esc_attr( $ship_to ); ?>">
					<label for="ship_street1">Street 1</label>
					<input id="ship_street1" value="<?php echo esc_attr( $street1 ); ?>">
					<label for="ship_street2">Street 2</label>
					<input id="ship_street2" value="<?php echo esc_attr( $street2 ); ?>">
				</div>
				<div>
					<label for="ship_city">City</label>
					<input id="ship_city" value="<?php echo esc_attr( $city ); ?>">
					<label for="ship_state">Province</label>
					<input id="ship_state" value="<?php echo esc_attr( $state ); ?>">
					<label for="ship_postal">Postal code</label>
					<input id="ship_postal" value="<?php echo esc_attr( $postal ); ?>">
					<label for="sender">SenderIdentity (account bucket)</label>
					<input id="sender" value="588">
					<label for="backorder">Backorder method</label>
					<select id="backorder">
						<option value="BO" selected>BO — ship available + backorder</option>
						<option value="FP">FP — ship available, cancel rest</option>
					</select>
				</div>
			</div>
		</div>

		<div class="actions">
			<a class="btn light" href="<?php echo esc_url( $cart_url ); ?>">← Back to cart</a>
			<button type="button" class="btn" id="gtco-submit">Submit stage PO</button>
		</div>
		<div id="gtco-msg"></div>
	<?php endif; ?>
</div>

<?php if ( $cart ) : ?>
<script>
(function(){
	const nonce = <?php echo wp_json_encode( $nonce ); ?>;
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const orderId = <?php echo (int) $order_id; ?>;
	const msg = document.getElementById('gtco-msg');
	document.getElementById('gtco-submit').addEventListener('click', function(){
		const btn = this;
		btn.disabled = true;
		msg.className = '';
		msg.textContent = 'Submitting to Grand & Toy stage…';
		const body = new FormData();
		body.append('action', 'cc_gt_place_order');
		body.append('nonce', nonce);
		body.append('wc_order_id', orderId);
		body.append('sender', document.getElementById('sender').value);
		body.append('backorder', document.getElementById('backorder').value);
		body.append('ship_name', document.getElementById('ship_name').value);
		body.append('ship_deliver_to', document.getElementById('ship_deliver_to').value);
		body.append('ship_street1', document.getElementById('ship_street1').value);
		body.append('ship_street2', document.getElementById('ship_street2').value);
		body.append('ship_city', document.getElementById('ship_city').value);
		body.append('ship_state', document.getElementById('ship_state').value);
		body.append('ship_postal', document.getElementById('ship_postal').value);
		fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body })
			.then(r => r.json())
			.then(res => {
				btn.disabled = false;
				if (!res || !res.success){
					msg.className = 'err';
					msg.textContent = (res && res.data && res.data.message) || 'PO failed.';
					return;
				}
				msg.className = 'ok';
				msg.textContent = res.data.message || 'Submitted.';
				if (res.data.view_url){
					setTimeout(function(){ window.location.href = res.data.view_url; }, 900);
				}
			})
			.catch(() => { btn.disabled = false; msg.className='err'; msg.textContent='PO failed.'; });
	});
})();
</script>
<?php endif; ?>
<?php
get_footer();
