<?php
/**
 * Template Name: Grand & Toy Cart
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	auth_redirect();
	exit;
}
if ( function_exists( 'cc_gt_user_is_store_employee' ) && cc_gt_user_is_store_employee()
	&& ( ! function_exists( 'cc_gt_user_can_procure' ) || ! cc_gt_user_can_procure() ) ) {
	wp_safe_redirect( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ) );
	exit;
}

if ( ! function_exists( 'cc_gt_user_can_procure' ) || ! cc_gt_user_can_procure() ) {
	wp_die( esc_html__( 'You need manager access to use the Grand & Toy cart.', 'adex360' ), 403 );
}

$order_id = function_exists( 'cc_gt_get_request_order_id' ) ? cc_gt_get_request_order_id() : 0;
$catalog  = $order_id ? add_query_arg( 'order-id', $order_id, home_url( '/gt-catalog/' ) ) : home_url( '/gt-catalog/' );
$checkout = $order_id ? add_query_arg( 'order-id', $order_id, home_url( '/gt-checkout/' ) ) : home_url( '/gt-checkout/' );
$cart     = function_exists( 'cc_gt_get_cart' ) ? cc_gt_get_cart() : array();
$nonce    = wp_create_nonce( 'cc_gt_procure' );
$order    = ( $order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : false;

$subtotal = 0.0;
$priced_lines = 0;
foreach ( $cart as $line ) {
	$price = isset( $line['unit_price'] ) && is_numeric( $line['unit_price'] ) ? (float) $line['unit_price'] : null;
	$qty   = max( 1, (int) ( $line['qty'] ?? 1 ) );
	if ( $price !== null ) {
		$subtotal += $price * $qty;
		$priced_lines++;
	}
}

get_header();
?>
<style>
/* Theme (Elementor/Woo) often overrides bare button/input — scope + !important. */
.gtcart{max-width:980px;margin:32px auto 64px;padding:0 20px;font-family:system-ui,-apple-system,BlinkMacSystemFont,sans-serif;color:#111;box-sizing:border-box}
.gtcart *,.gtcart *::before,.gtcart *::after{box-sizing:border-box}
.gtcart .badge{display:inline-block;background:#0b6e4f;color:#fff;font-size:.7rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:4px 8px;border-radius:4px;margin-bottom:10px}
.gtcart h1{margin:0 0 6px;font-family:Georgia,"Times New Roman",serif;font-size:2rem;letter-spacing:-.02em}
.gtcart .lead{color:#555;margin:0 0 8px;line-height:1.45;font-size:.95rem}
.gtcart .steps{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 22px;padding:0;list-style:none;font-size:.78rem;color:#666}
.gtcart .steps li{background:#f0f7f3;border:1px solid #d5e5dc;border-radius:999px;padding:5px 10px}
.gtcart .steps li.on{background:#0b6e4f;border-color:#0b6e4f;color:#fff;font-weight:600}
.gtcart .empty{padding:32px 24px;border:1px dashed #b7cfc2;border-radius:10px;color:#555;background:#f7faf8}
.gtcart .empty a{color:#0b6e4f;font-weight:600}
.gtcart .layout{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:20px;align-items:start}
@media(max-width:860px){.gtcart .layout{grid-template-columns:1fr}}
.gtcart .lines{display:grid;gap:12px;min-width:0}
.gtcart .line{background:#fff;border:1px solid #d5e5dc;border-radius:10px;padding:18px 20px;display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:20px;align-items:start}
@media(max-width:640px){.gtcart .line{grid-template-columns:1fr}}
.gtcart .line h3{margin:0 0 4px;font-family:Georgia,serif;font-size:1.05rem}
.gtcart .meta{font-size:.82rem;color:#666;line-height:1.45}
.gtcart .meta code{font-size:.78rem;background:#f0f7f3;padding:1px 5px;border-radius:3px}
.gtcart .stock{margin-top:8px;font-size:.8rem;color:#0b6e4f}
.gtcart .stock.warn{color:#8a5a00}
.gtcart .side{display:flex;flex-direction:column;gap:12px;align-items:stretch;width:100%}
.gtcart .price-row{display:grid;grid-template-columns:1fr auto;gap:6px 12px;font-size:.85rem;align-items:baseline}
.gtcart .price-row .lbl{color:#666}
.gtcart .price-row .line-total{font-size:1.05rem;font-weight:700;color:#0b6e4f}
.gtcart .qty-wrap{display:inline-flex!important;align-items:stretch;align-self:flex-end;border:1px solid #c5d4cb!important;border-radius:8px!important;overflow:hidden;background:#fff!important;height:40px;width:fit-content;max-width:100%}
.gtcart .qty-wrap .gtcart-qty-btn{appearance:none!important;-webkit-appearance:none!important;width:40px!important;min-width:40px!important;height:40px!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;outline:none!important;box-shadow:none!important;background:#f0f7f3!important;color:#111!important;font-size:1.15rem!important;font-weight:600!important;line-height:1!important;cursor:pointer!important;text-decoration:none!important}
.gtcart .qty-wrap .gtcart-qty-btn:hover,.gtcart .qty-wrap .gtcart-qty-btn:focus{background:#d5ebe0!important;color:#111!important;outline:none!important;box-shadow:none!important}
.gtcart .qty-wrap .gtcart-qty-btn:focus-visible{outline:2px solid #0b6e4f!important;outline-offset:-2px!important}
.gtcart .qty-wrap input.qty{appearance:textfield!important;-moz-appearance:textfield!important;width:56px!important;min-width:56px!important;height:40px!important;margin:0!important;padding:0 4px!important;border:0!important;border-left:1px solid #c5d4cb!important;border-right:1px solid #c5d4cb!important;border-radius:0!important;outline:none!important;box-shadow:none!important;background:#fff!important;color:#111!important;text-align:center!important;font-size:1rem!important;font-family:system-ui,sans-serif!important;font-weight:600!important;line-height:40px!important}
.gtcart .qty-wrap input.qty::-webkit-outer-spin-button,.gtcart .qty-wrap input.qty::-webkit-inner-spin-button{-webkit-appearance:none!important;margin:0!important}
.gtcart .qty-wrap input.qty:focus{outline:none!important;box-shadow:inset 0 0 0 2px #0b6e4f!important}
.gtcart button.gtcart-remove{appearance:none!important;-webkit-appearance:none!important;display:inline-flex!important;align-items:center;justify-content:center;align-self:flex-end;width:auto!important;min-width:96px;height:36px!important;margin:0!important;padding:0 14px!important;border:1px solid #c9a0a0!important;border-radius:6px!important;outline:none!important;box-shadow:none!important;background:#fff!important;color:#8b1e1e!important;font-size:.82rem!important;font-weight:600!important;font-family:system-ui,sans-serif!important;line-height:1!important;cursor:pointer!important;text-decoration:none!important;letter-spacing:0!important}
.gtcart button.gtcart-remove:hover,.gtcart button.gtcart-remove:focus{background:#fdf2f2!important;border-color:#8b1e1e!important;color:#8b1e1e!important;outline:none!important;box-shadow:none!important}
.gtcart button.gtcart-remove:focus-visible{outline:2px solid #8b1e1e!important;outline-offset:2px!important}
.gtcart .summary{background:#f7faf8;border:1px solid #d5e5dc;border-radius:10px;padding:18px;position:sticky;top:16px}
.gtcart .summary h2{margin:0 0 12px;font-family:Georgia,serif;font-size:1.15rem}
.gtcart .sum-row{display:flex;justify-content:space-between;gap:12px;font-size:.9rem;margin-bottom:8px}
.gtcart .sum-row.total{margin-top:12px;padding-top:12px;border-top:1px solid #d5e5dc;font-size:1.15rem;font-weight:700}
.gtcart .sum-row.total span:last-child{color:#0b6e4f}
.gtcart .hint{font-size:.78rem;color:#666;margin:10px 0 0;line-height:1.4}
.gtcart .status{font-size:.78rem;color:#0b6e4f;min-height:1.2em;margin-top:6px}
.gtcart .status.err{color:#a61b1b}
.gtcart .actions{display:flex;flex-direction:column;gap:8px;margin-top:16px}
.gtcart a.btn,.gtcart button.btn{appearance:none!important;-webkit-appearance:none!important;display:inline-flex!important;align-items:center;justify-content:center;background:#0b6e4f!important;color:#fff!important;text-decoration:none!important;padding:11px 16px!important;border-radius:6px!important;font-weight:600!important;font-family:system-ui,sans-serif!important;font-size:.9rem!important;border:0!important;outline:none!important;box-shadow:none!important;cursor:pointer;min-height:42px;line-height:1.2!important;width:100%;letter-spacing:0!important}
.gtcart a.btn:hover,.gtcart button.btn:hover,.gtcart a.btn:focus,.gtcart button.btn:focus{background:#095c42!important;color:#fff!important;outline:none!important;box-shadow:none!important;text-decoration:none!important}
.gtcart a.btn:focus-visible,.gtcart button.btn:focus-visible{outline:2px solid #0b6e4f!important;outline-offset:2px!important}
.gtcart a.btn.light{background:#111!important}
.gtcart a.btn.light:hover,.gtcart a.btn.light:focus{background:#000!important;color:#fff!important}
</style>

<div class="gtcart">
	<span class="badge">Grand &amp; Toy only</span>
	<h1>Grand &amp; Toy cart</h1>
	<p class="lead">
		Review items, change quantity — line total updates right away.
		<?php if ( $order ) : ?>
			Linked Woo order <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong>.
		<?php endif; ?>
	</p>
	<ol class="steps">
		<li>1 · Catalog</li>
		<li class="on">2 · Cart</li>
		<li>3 · Checkout</li>
	</ol>

	<?php if ( ! $cart ) : ?>
		<div class="empty">Cart is empty. <a href="<?php echo esc_url( $catalog ); ?>">Browse G&amp;T catalog</a></div>
	<?php else : ?>
		<div class="layout">
			<div class="lines" id="gtcart-body">
				<?php foreach ( $cart as $line ) :
					$key   = (string) ( $line['key'] ?? '' );
					$qty   = max( 1, (int) ( $line['qty'] ?? 1 ) );
					$price = isset( $line['unit_price'] ) && is_numeric( $line['unit_price'] ) ? (float) $line['unit_price'] : null;
					$line_total = $price !== null ? $price * $qty : null;
					$stock_bits = array();
					if ( ! empty( $line['available'] ) ) {
						$stock_bits[] = $line['available'];
					}
					if ( isset( $line['available_qty'] ) && $line['available_qty'] !== '' && $line['available_qty'] !== null ) {
						$stock_bits[] = 'avail ' . $line['available_qty'];
					}
					if ( ! empty( $line['days'] ) ) {
						$stock_bits[] = $line['days'];
					}
					if ( ! empty( $line['facility_id'] ) ) {
						$stock_bits[] = 'facility ' . $line['facility_id'];
					}
					?>
					<article class="line" data-key="<?php echo esc_attr( $key ); ?>" data-unit="<?php echo esc_attr( $price !== null ? (string) $price : '' ); ?>">
						<div>
							<h3><?php echo esc_html( $line['name'] ?? '' ); ?></h3>
							<div class="meta">
								SKU <?php echo esc_html( $line['sku'] ?: '—' ); ?>
								· Part <code><?php echo esc_html( $line['part_id'] ?? '' ); ?></code>
								<?php if ( ! empty( $line['uom'] ) ) : ?>
									· UOM <?php echo esc_html( $line['uom'] ); ?>
								<?php endif; ?>
							</div>
							<?php if ( $stock_bits ) : ?>
								<div class="stock"><?php echo esc_html( implode( ' · ', $stock_bits ) ); ?></div>
							<?php else : ?>
								<div class="stock warn">No stock snapshot — re-check from catalog before checkout.</div>
							<?php endif; ?>
						</div>
						<div class="side">
							<div class="price-row">
								<span class="lbl">Unit</span>
								<span data-unit-label><?php echo $price !== null ? esc_html( number_format( $price, 2 ) ) . ' CAD' : '—'; ?></span>
								<span class="lbl">Line total</span>
								<span class="line-total" data-line-total><?php echo $line_total !== null ? esc_html( number_format( $line_total, 2 ) ) . ' CAD' : '—'; ?></span>
							</div>
							<div class="qty-wrap" aria-label="Quantity">
								<button type="button" class="gtcart-qty-btn" data-qty-minus aria-label="Decrease">−</button>
								<input class="qty" type="number" inputmode="numeric" min="1" step="1" value="<?php echo esc_attr( (string) $qty ); ?>" aria-label="Quantity">
								<button type="button" class="gtcart-qty-btn" data-qty-plus aria-label="Increase">+</button>
							</div>
							<button type="button" class="gtcart-remove" data-remove>Remove</button>
						</div>
					</article>
				<?php endforeach; ?>
			</div>

			<aside class="summary">
				<h2>Order summary</h2>
				<div class="sum-row">
					<span>Items</span>
					<span id="gtcart-count"><?php echo (int) count( $cart ); ?></span>
				</div>
				<div class="sum-row total">
					<span>Estimated total</span>
					<span id="gtcart-subtotal"><?php echo $priced_lines ? esc_html( number_format( $subtotal, 2 ) ) . ' CAD' : '—'; ?></span>
				</div>
				<p class="hint">Totals use the last Grand &amp; Toy stock-check price. Tax/shipping (if any) appear at checkout.</p>
				<div class="status" id="gtcart-status" aria-live="polite"></div>
				<div class="actions">
					<a class="btn" id="gtcart-checkout" href="<?php echo esc_url( $checkout ); ?>">Proceed to checkout →</a>
					<a class="btn light" href="<?php echo esc_url( $catalog ); ?>">← Continue shopping</a>
				</div>
			</aside>
		</div>
	<?php endif; ?>
</div>

<?php if ( $cart ) : ?>
<script>
(function(){
	const nonce = <?php echo wp_json_encode( $nonce ); ?>;
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const orderId = <?php echo (int) $order_id; ?>;
	const statusEl = document.getElementById('gtcart-status');
	const subtotalEl = document.getElementById('gtcart-subtotal');
	const countEl = document.getElementById('gtcart-count');
	const timers = new Map();

	function money(n){
		return (Math.round(n * 100) / 100).toFixed(2) + ' CAD';
	}

	function post(action, data){
		const body = new FormData();
		body.append('action', action);
		body.append('nonce', nonce);
		if (orderId) body.append('wc_order_id', String(orderId));
		Object.keys(data||{}).forEach(k => body.append(k, data[k]));
		return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body}).then(r=>r.json());
	}

	function setStatus(msg, isErr){
		statusEl.textContent = msg || '';
		statusEl.className = 'status' + (isErr ? ' err' : '');
	}

	function refreshTotals(){
		let sub = 0;
		let priced = 0;
		let count = 0;
		document.querySelectorAll('#gtcart-body .line').forEach(row => {
			count++;
			const unit = parseFloat(row.getAttribute('data-unit'));
			const qty = Math.max(1, parseInt(row.querySelector('.qty').value, 10) || 1);
			const totalEl = row.querySelector('[data-line-total]');
			if (!isNaN(unit)){
				const lt = unit * qty;
				totalEl.textContent = money(lt);
				sub += lt;
				priced++;
			} else {
				totalEl.textContent = '—';
			}
		});
		countEl.textContent = String(count);
		subtotalEl.textContent = priced ? money(sub) : '—';
	}

	function saveQty(row, qty){
		const key = row.getAttribute('data-key');
		setStatus('Saving…');
		return post('cc_gt_cart_update', { key, qty }).then(res => {
			if (!res || !res.success){
				setStatus((res && res.data && res.data.message) || 'Could not save quantity.', true);
				return;
			}
			if (qty <= 0){
				row.remove();
				if (!document.querySelector('#gtcart-body .line')){
					location.reload();
					return;
				}
			}
			refreshTotals();
			setStatus('Saved');
			setTimeout(() => { if (statusEl.textContent === 'Saved') setStatus(''); }, 1200);
		}).catch(() => setStatus('Could not save quantity.', true));
	}

	function queueSave(row){
		const key = row.getAttribute('data-key');
		clearTimeout(timers.get(key));
		const qty = Math.max(1, parseInt(row.querySelector('.qty').value, 10) || 1);
		row.querySelector('.qty').value = String(qty);
		refreshTotals();
		timers.set(key, setTimeout(() => saveQty(row, qty), 350));
	}

	document.getElementById('gtcart-body').addEventListener('click', function(e){
		const row = e.target.closest('.line');
		if (!row) return;

		if (e.target.closest('[data-qty-minus]')){
			const input = row.querySelector('.qty');
			input.value = String(Math.max(1, (parseInt(input.value, 10) || 1) - 1));
			queueSave(row);
		}
		if (e.target.closest('[data-qty-plus]')){
			const input = row.querySelector('.qty');
			input.value = String(Math.max(1, (parseInt(input.value, 10) || 1) + 1));
			queueSave(row);
		}
		if (e.target.closest('[data-remove]')){
			if (!confirm('Remove this item from the G&T cart?')) return;
			saveQty(row, 0);
		}
	});

	document.getElementById('gtcart-body').addEventListener('change', function(e){
		if (!e.target.classList.contains('qty')) return;
		queueSave(e.target.closest('.line'));
	});
	document.getElementById('gtcart-body').addEventListener('input', function(e){
		if (!e.target.classList.contains('qty')) return;
		refreshTotals();
	});
})();
</script>
<?php endif; ?>
<?php
get_footer();
