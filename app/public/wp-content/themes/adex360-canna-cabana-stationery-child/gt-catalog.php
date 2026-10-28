<?php
/**
 * Template Name: Grand & Toy Catalog
 * Managers: stock check + G&T procurement cart.
 * Store employees: browse approved items + add to Woo store cart (Internal Requisition).
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	auth_redirect();
	exit;
}

if ( ! function_exists( 'cc_gt_user_can_browse' ) || ! cc_gt_user_can_browse() ) {
	wp_die( esc_html__( 'You need store or manager access to use the Grand & Toy catalog.', 'adex360' ), 403 );
}

$is_manager = function_exists( 'cc_gt_user_can_procure' ) && cc_gt_user_can_procure();
$is_store   = function_exists( 'cc_gt_user_is_store_employee' ) && cc_gt_user_is_store_employee() && ! $is_manager;
$order_id   = function_exists( 'cc_gt_get_request_order_id' ) ? cc_gt_get_request_order_id() : 0;
$cart_url   = $order_id
	? add_query_arg( 'order-id', $order_id, home_url( '/gt-cart/' ) )
	: home_url( '/gt-cart/' );
$woo_cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
$nonce      = wp_create_nonce( 'cc_gt_procure' );
$cart_count = ( $is_manager && function_exists( 'cc_gt_get_cart' ) ) ? count( cc_gt_get_cart() ) : 0;
$woo_count  = ( function_exists( 'WC' ) && WC()->cart ) ? (int) WC()->cart->get_cart_contents_count() : 0;
$order      = ( $order_id && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : false;
$store_id   = function_exists( 'cc_gt_current_store_id' ) ? cc_gt_current_store_id() : 0;

get_header();
?>
<style>
.gtc-wrap{max-width:1100px;margin:32px auto 64px;padding:0 20px;font-family:Georgia,"Times New Roman",serif;color:#111}
.gtc-hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:28px;flex-wrap:wrap}
.gtc-hero h1{margin:0 0 8px;font-size:2rem;letter-spacing:-.02em}
.gtc-hero p{margin:0;color:#444;max-width:36rem;line-height:1.45;font-family:system-ui,-apple-system,sans-serif;font-size:.95rem}
.gtc-badge{display:inline-block;background:#0b6e4f;color:#fff;font-family:system-ui,sans-serif;font-size:.7rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:4px 8px;border-radius:4px;margin-bottom:10px}
.gtc-actions{display:flex;gap:10px;flex-wrap:wrap}
.gtc-wrap .gtc-btn,.gtc-wrap a.gtc-btn,button.gtc-btn{display:inline-flex!important;align-items:center;justify-content:center;gap:8px;background:#0b6e4f!important;color:#fff!important;text-decoration:none!important;padding:10px 16px!important;border-radius:6px!important;font-family:system-ui,sans-serif!important;font-size:.9rem!important;font-weight:600!important;border:0!important;cursor:pointer;line-height:1.2!important;min-height:40px}
.gtc-wrap .gtc-btn.secondary,button.gtc-btn.secondary{background:#111!important;color:#fff!important}
.gtc-wrap .gtc-btn:disabled,button.gtc-btn:disabled{opacity:.5;cursor:not-allowed}
.gtc-panel{background:#f7faf8;border:1px solid #d5e5dc;border-radius:10px;padding:20px;margin-bottom:20px}
.gtc-panel h2{margin:0 0 12px;font-size:1.15rem}
.gtc-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.gtc-row input{flex:1;min-width:180px;padding:10px 12px;border:1px solid #c5d4cb;border-radius:6px;font-family:system-ui,sans-serif}
.gtc-hint{font-family:system-ui,sans-serif;font-size:.85rem;color:#555;margin:0}
.gtc-list{list-style:none;margin:16px 0 0;padding:0;display:grid;gap:10px}
.gtc-item{background:#fff;border:1px solid #d5e5dc;border-radius:8px;padding:14px 16px;display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center}
.gtc-item h3{margin:0 0 4px;font-size:1.05rem}
.gtc-meta{font-family:system-ui,sans-serif;font-size:.82rem;color:#555}
.gtc-stock{margin-top:8px;font-family:system-ui,sans-serif;font-size:.85rem;color:#0b6e4f}
.gtc-stock.err{color:#a61b1b}
.gtc-empty{font-family:system-ui,sans-serif;color:#666;padding:12px 0}
.gtc-note{font-family:system-ui,sans-serif;font-size:.8rem;color:#666;margin-top:20px}
@media(max-width:720px){.gtc-item{grid-template-columns:1fr}}
</style>

<div class="gtc-wrap">
	<div class="gtc-hero">
		<div>
			<span class="gtc-badge"><?php echo $is_store ? 'Store request · Grand &amp; Toy' : 'Grand &amp; Toy only'; ?></span>
			<h1><?php echo $is_store ? 'Request Grand &amp; Toy supplies' : 'Grand &amp; Toy catalog'; ?></h1>
			<p>
				<?php if ( $is_store ) : ?>
					Search approved supplies, optionally check stock, then <strong>add to your store cart</strong>.
					Checkout uses <strong>Internal Requisition</strong> — a manager fulfils the order (Amazon or Grand &amp; Toy).
					You cannot place a Grand &amp; Toy purchase order from here.
					<?php if ( $store_id ) : ?>
						Your store id: <strong>#<?php echo (int) $store_id; ?></strong>.
					<?php endif; ?>
				<?php else : ?>
					Search store products by name or SKU, check live stage stock on Grand &amp; Toy, then add to the <strong>G&amp;T cart</strong>.
					This path never uses Amazon cart or Amazon APIs.
					<?php if ( $order ) : ?>
						Linked Woo order: <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong>
					<?php endif; ?>
				<?php endif; ?>
			</p>
		</div>
		<div class="gtc-actions">
			<?php if ( $is_store ) : ?>
				<a class="gtc-btn secondary" href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">← My account</a>
				<a class="gtc-btn" href="<?php echo esc_url( $woo_cart_url ); ?>">
					View store cart
					<span id="gtc-cart-count"><?php echo $woo_count ? '(' . (int) $woo_count . ')' : ''; ?></span>
				</a>
			<?php else : ?>
				<a class="gtc-btn secondary" href="<?php echo esc_url( home_url( '/manager/orders' ) ); ?>">← Manager orders</a>
				<a class="gtc-btn" href="<?php echo esc_url( $cart_url ); ?>">
					View G&amp;T cart
					<span id="gtc-cart-count"><?php echo $cart_count ? '(' . (int) $cart_count . ')' : ''; ?></span>
				</a>
			<?php endif; ?>
		</div>
	</div>

	<div class="gtc-panel">
		<h2>1. Find a store product</h2>
		<div class="gtc-row">
			<input type="search" id="gtc-term" placeholder="Name, SKU, or G&amp;T part…" autocomplete="off">
			<button type="button" class="gtc-btn" id="gtc-search">Search</button>
		</div>
		<p class="gtc-hint">Amazon-only eligibility products are hidden here. G&amp;T part defaults to store SKU unless <code>wcss_gt_part_id</code> is set.</p>
		<ul class="gtc-list" id="gtc-results"></ul>
		<p class="gtc-empty" id="gtc-empty">Type at least 2 characters and search.</p>
	</div>

	<p class="gtc-note">
		<?php if ( $is_store ) : ?>
			Stock check is optional info from Grand &amp; Toy stage. Your request still goes through the normal store order → manager approval flow.
		<?php else : ?>
			Stage endpoint only. Stock checks call Grand &amp; Toy stage cXML — no purchase order is placed from this screen.
		<?php endif; ?>
	</p>
</div>

<script>
(function(){
	let nonce = <?php echo wp_json_encode( $nonce ); ?>;
	const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	const isStore = <?php echo $is_store ? 'true' : 'false'; ?>;
	const orderId = <?php echo (int) $order_id; ?>;
	const results = document.getElementById('gtc-results');
	const empty = document.getElementById('gtc-empty');
	const countEl = document.getElementById('gtc-cart-count');
	let nonceReady = null;

	function refreshNonce(){
		const body = new FormData();
		body.append('action', 'cc_gt_nonce');
		nonceReady = fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body })
			.then(r => r.json())
			.then(res => {
				if (res && res.success && res.data && res.data.nonce) {
					nonce = res.data.nonce;
				}
				return nonce;
			})
			.catch(() => nonce);
		return nonceReady;
	}
	// Always pull a live nonce so CDN/page-cache cannot break search.
	refreshNonce();

	function post(action, data){
		const body = new FormData();
		body.append('action', action);
		body.append('nonce', nonce);
		Object.keys(data || {}).forEach(k => body.append(k, data[k] == null ? '' : data[k]));
		return fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body }).then(async r => {
			const text = await r.text();
			try {
				return JSON.parse(text);
			} catch (e) {
				// WP returns "-1" / "0" when nonce/session is invalid.
				const err = new Error('bad_nonce');
				err.raw = text;
				throw err;
			}
		});
	}

	function postWithFreshNonce(action, data){
		return (nonceReady || refreshNonce()).then(() => post(action, data)).catch(err => {
			if (err && err.message === 'bad_nonce') {
				return refreshNonce().then(() => post(action, data));
			}
			throw err;
		});
	}

	function esc(s){
		return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
	}

	function render(products){
		results.innerHTML = '';
		if (!products || !products.length){
			empty.hidden = false;
			empty.textContent = 'No matching products (or all matches are Amazon-only).';
			return;
		}
		empty.hidden = true;
		products.forEach(p => {
			const li = document.createElement('li');
			li.className = 'gtc-item';
			const addLabel = isStore ? 'Add to store cart' : 'Add to G&amp;T cart';
			// Store can add without stock; managers must check stock first (price snapshot).
			const addDisabled = isStore ? '' : 'disabled';
			li.innerHTML =
				'<div>' +
					'<h3>' + esc(p.name) + '</h3>' +
					'<div class="gtc-meta">SKU ' + esc(p.sku||'—') + ' · G&amp;T part <strong>' + esc(p.gt_part||'—') + '</strong> · eligibility ' + esc(p.eligibility||'unset') + '</div>' +
					'<div class="gtc-stock" data-stock></div>' +
				'</div>' +
				'<div class="gtc-actions">' +
					'<button type="button" class="gtc-btn secondary" data-stock-btn ' + (!p.gt_part ? 'disabled' : '') + '>Check stock</button>' +
					'<button type="button" class="gtc-btn" data-add-btn ' + addDisabled + '>' + addLabel + '</button>' +
				'</div>';
			li._product = p;
			li._stock = null;
			results.appendChild(li);
		});
	}

	let searchTimer = null;
	let searchSeq = 0;

	function runSearch(){
		const term = document.getElementById('gtc-term').value.trim();
		if (term.length < 2){
			empty.hidden = false;
			empty.textContent = 'Type at least 2 characters…';
			results.innerHTML = '';
			return;
		}
		const seq = ++searchSeq;
		empty.hidden = false;
		empty.textContent = 'Searching…';
		postWithFreshNonce('cc_gt_search', { term }).then(res => {
			if (seq !== searchSeq) return;
			if (!res || !res.success){
				empty.textContent = (res && res.data && res.data.message) || 'Search failed. Hard-refresh the page (Cmd+Shift+R) and try again.';
				return;
			}
			render(res.data.products || []);
		}).catch(() => {
			if (seq !== searchSeq) return;
			empty.textContent = 'Search failed. Hard-refresh the page (Cmd+Shift+R) and try again.';
		});
	}

	document.getElementById('gtc-search').addEventListener('click', runSearch);

	document.getElementById('gtc-term').addEventListener('input', function(){
		clearTimeout(searchTimer);
		searchTimer = setTimeout(runSearch, 280);
	});

	document.getElementById('gtc-term').addEventListener('keydown', function(e){
		if (e.key === 'Enter'){
			e.preventDefault();
			clearTimeout(searchTimer);
			runSearch();
		}
	});

	results.addEventListener('click', function(e){
		const item = e.target.closest('.gtc-item');
		if (!item) return;
		const p = item._product;
		const stockEl = item.querySelector('[data-stock]');

		if (e.target.matches('[data-stock-btn]')){
			e.target.disabled = true;
			stockEl.className = 'gtc-stock';
			stockEl.textContent = 'Checking Grand & Toy stage…';
			postWithFreshNonce('cc_gt_stock', { part_id: p.gt_part }).then(res => {
				e.target.disabled = false;
				if (!res || !res.success){
					stockEl.className = 'gtc-stock err';
					stockEl.textContent = (res && res.data && (res.data.error || res.data.message)) || 'Stock check failed.';
					item._stock = null;
					if (!isStore) item.querySelector('[data-add-btn]').disabled = true;
					return;
				}
				const s = res.data;
				item._stock = s;
				stockEl.className = 'gtc-stock';
				stockEl.textContent = 'Price ' + (s.unit_price != null ? s.unit_price + ' ' + (s.currency||'CAD') : '—') +
					' · ' + (s.available || 'availability n/a') +
					(s.available_qty != null && s.available_qty !== '' ? ' · qty ' + s.available_qty : '') +
					(s.days ? ' · ' + s.days : '') +
					(s.facility_id ? ' · facility ' + s.facility_id : '');
				item.querySelector('[data-add-btn]').disabled = false;
			}).catch(() => {
				e.target.disabled = false;
				stockEl.className = 'gtc-stock err';
				stockEl.textContent = 'Stock check failed.';
				if (!isStore) item.querySelector('[data-add-btn]').disabled = true;
			});
		}

		if (e.target.matches('[data-add-btn]')){
			const s = item._stock || {};
			e.target.disabled = true;
			const action = isStore ? 'cc_gt_woo_cart_add' : 'cc_gt_cart_add';
			const payload = isStore
				? { product_id: p.id, part_id: p.gt_part || '', qty: 1 }
				: {
					product_id: p.id,
					part_id: p.gt_part,
					name: p.name,
					sku: p.sku || '',
					qty: 1,
					uom: s.uom || 'EA',
					unit_price: s.unit_price != null ? s.unit_price : '',
					facility_id: s.facility_id || '',
					available: s.available || '',
					available_qty: s.available_qty != null ? s.available_qty : '',
					days: s.days || '',
					wc_order_id: orderId || ''
				};
			postWithFreshNonce(action, payload).then(res => {
				e.target.disabled = false;
				if (!res || !res.success){
					alert((res && res.data && res.data.message) || 'Could not add to cart.');
					return;
				}
				const n = res.data.count || 0;
				countEl.textContent = n ? '(' + n + ')' : '';
				e.target.textContent = 'Added';
				setTimeout(() => {
					e.target.textContent = isStore ? 'Add to store cart' : 'Add to G&T cart';
				}, 1200);
			}).catch(() => { e.target.disabled = false; alert('Could not add to cart.'); });
		}
	});
})();
</script>
<?php
get_footer();
