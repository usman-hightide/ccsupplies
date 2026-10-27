<?php
/**
 * Template Name: Vendor Compare
 *
 * Read-only Amazon vs Grand & Toy comparison. Manual vendor selection only.
 * Does not place purchase orders. UI aligned with Amazon Catalog dashboard theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if (
	! is_user_logged_in()
	|| ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) )
) {
	auth_redirect();
	exit;
}

$store_products = array();
if ( function_exists( 'wc_get_products' ) ) {
	$ids = wc_get_products(
		array(
			'status'  => 'publish',
			'limit'   => -1,
			'return'  => 'ids',
			'orderby' => 'title',
			'order'   => 'ASC',
		)
	);
	foreach ( $ids as $pid ) {
		$p = wc_get_product( $pid );
		if ( ! $p ) {
			continue;
		}
		$asin = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) get_post_meta( $pid, 'amazon_asin', true ) ) );
		$store_products[] = array(
			'id'     => (int) $pid,
			'name'   => $p->get_name(),
			'sku'    => (string) $p->get_sku(),
			'asin'   => $asin,
			'search' => strtolower( $p->get_name() . ' ' . $p->get_sku() . ' ' . $asin ),
		);
	}
}

$nonce       = wp_create_nonce( 'cc_vendor_compare' );
$ajax_url    = admin_url( 'admin-ajax.php' );
$preselect   = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
$catalog_url = home_url( '/amazon-catalog/' );
$manager_url = home_url( '/manager/products' );

get_header();
?>
<style>
.vc-compare-page,
.vc-compare-page * {
	box-sizing: border-box;
}
.vc-compare-page {
	max-width: 1180px;
	margin: 0 auto;
	padding: 24px 16px 72px;
	color: #161616;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}
.vc-compare-page .vc-header {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: 20px;
	align-items: flex-start;
	padding: 28px 24px;
	margin: 0 0 28px;
	border-radius: 14px;
	background: #000;
	color: #fff;
}
.vc-compare-page .vc-header-copy {
	max-width: 640px;
}
.vc-compare-page .vc-badge {
	display: inline-flex;
	align-items: center;
	min-height: 28px;
	margin: 0 0 12px;
	padding: 4px 10px;
	border-radius: 999px;
	background: #e1251c;
	color: #fff;
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
}
.vc-compare-page .vc-header h1 {
	margin: 0 0 10px !important;
	color: #fff !important;
	font-size: 28px !important;
	line-height: 1.2 !important;
	font-weight: 700 !important;
}
.vc-compare-page .vc-header p {
	margin: 0 !important;
	color: rgba(255,255,255,.82);
	font-size: 15px;
	line-height: 1.5;
}
.vc-compare-page .vc-header-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	align-items: center;
}
.vc-compare-page .vc-link-btn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-height: 42px;
	padding: 0 16px;
	border-radius: 6px;
	background: #e1251c !important;
	color: #fff !important;
	text-decoration: none !important;
	font-size: 14px;
	font-weight: 700;
}
.vc-compare-page .vc-link-btn:hover {
	background: #c61d16 !important;
	color: #fff !important;
}
.vc-compare-page .vc-link-btn.secondary {
	background: #333 !important;
}
.vc-compare-page .vc-link-btn.secondary:hover {
	background: #111 !important;
}
.vc-compare-page .vc-panel {
	margin-bottom: 28px;
	padding: 0 0 22px;
	border: 1px solid #ececec;
	border-radius: 14px;
	background: #fff;
	box-shadow: 0 10px 28px rgba(0,0,0,.06);
	overflow: hidden;
}
.vc-compare-page .vc-panel-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	padding: 16px 22px;
	background: #000;
}
.vc-compare-page .vc-panel-head h2 {
	margin: 0 !important;
	color: #fff !important;
	font-size: 20px !important;
	line-height: 1.3 !important;
}
.vc-compare-page .vc-panel-total {
	display: inline-flex;
	align-items: center;
	min-height: 28px;
	padding: 4px 10px;
	border-radius: 999px;
	background: #e1251c;
	color: #fff;
	font-size: 13px;
	font-weight: 700;
	white-space: nowrap;
}
.vc-compare-page .vc-panel > p,
.vc-compare-page .vc-hint {
	margin: 18px 22px 14px !important;
	color: #3a3a3a;
	font-size: 15px;
}
.vc-compare-page .vc-toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: center;
	margin: 0 22px 0;
}
.vc-compare-page .vc-input {
	flex: 1 1 280px;
	min-height: 48px;
	padding: 12px 14px;
	border: 1px solid #d5d5d5;
	border-radius: 8px;
	background: #fff;
	font-size: 15px;
}
.vc-compare-page .vc-input:focus {
	outline: 2px solid #e1251c;
	border-color: #e1251c;
}
.vc-compare-page .vc-check {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	color: #555;
	font-size: 13px;
	font-weight: 600;
}
.vc-compare-page .vc-button {
	display: inline-flex !important;
	align-items: center;
	justify-content: center;
	box-sizing: border-box !important;
	min-height: 42px !important;
	height: 42px !important;
	padding: 0 16px !important;
	border: 0 !important;
	border-radius: 6px !important;
	background: #ff9900 !important;
	color: #111 !important;
	cursor: pointer;
	font-size: 14px !important;
	font-weight: 700 !important;
	line-height: 1 !important;
	white-space: nowrap !important;
}
.vc-compare-page .vc-button:hover {
	background: #e68a00 !important;
}
.vc-compare-page .vc-button:disabled {
	opacity: 0.45;
	cursor: not-allowed;
}
.vc-compare-page .vc-button.secondary {
	background: #333 !important;
	color: #fff !important;
}
.vc-compare-page .vc-button.secondary:hover {
	background: #111 !important;
}
.vc-compare-page .vc-count {
	margin: 10px 22px 0 !important;
	color: #555;
	font-size: 13px;
	font-weight: 600;
}
.vc-compare-page .vc-list {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin: 14px 22px 0;
	max-height: 320px;
	overflow: auto;
}
.vc-compare-page .vc-item {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto;
	gap: 8px 16px;
	align-items: center;
	padding: 12px;
	border: 1px solid #eee;
	border-radius: 8px;
	background: #fafafa;
	cursor: pointer;
}
.vc-compare-page .vc-item[hidden] {
	display: none !important;
}
.vc-compare-page .vc-item:hover,
.vc-compare-page .vc-item.is-selected {
	border-color: #e1251c;
	background: #fff7f6;
}
.vc-compare-page .vc-item strong {
	display: block;
	font-size: 15px;
}
.vc-compare-page .vc-meta {
	display: block;
	margin-top: 4px;
	color: #666;
	font-size: 13px;
}
.vc-compare-page .vc-selected-pill {
	display: none;
	padding: 4px 10px;
	border-radius: 999px;
	background: #e1251c;
	color: #fff;
	font-size: 12px;
	font-weight: 700;
}
.vc-compare-page .vc-item.is-selected .vc-selected-pill {
	display: inline-flex;
}
.vc-compare-page .vc-product-label {
	margin: 0 22px 14px !important;
	padding: 10px 12px;
	border-radius: 8px;
	background: #f7f7f7;
	border: 1px solid #eee;
	font-size: 14px;
	font-weight: 600;
}
.vc-compare-page .vc-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
	margin: 0 22px;
}
@media (max-width: 860px) {
	.vc-compare-page .vc-grid { grid-template-columns: 1fr; }
}
.vc-compare-page .vc-card {
	border: 1px solid #ddd;
	border-radius: 10px;
	background: #fff;
	overflow: hidden;
	min-height: 220px;
}
.vc-compare-page .vc-card-head {
	padding: 12px 14px;
	font-size: 15px;
	font-weight: 700;
}
.vc-compare-page .vc-card-head.amazon {
	background: #fff7e8;
}
.vc-compare-page .vc-card-head.gt {
	background: #f1f6ff;
}
.vc-compare-page .vc-card-body {
	padding: 14px;
}
.vc-compare-page .vc-status {
	display: inline-block;
	padding: 3px 8px;
	border-radius: 5px;
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	margin-bottom: 10px;
}
.vc-compare-page .vc-status-ok { background: #e8f8ee; color: #146c2e; }
.vc-compare-page .vc-status-error { background: #fff6f6; color: #b42318; }
.vc-compare-page .vc-status-unavailable { background: #f1f1f1; color: #555; }
.vc-compare-page .vc-dl {
	margin: 0;
	display: grid;
	grid-template-columns: 110px 1fr;
	gap: 8px 12px;
	font-size: 14px;
}
.vc-compare-page .vc-dl dt { margin: 0; color: #666; }
.vc-compare-page .vc-dl dd { margin: 0; font-weight: 700; }
.vc-compare-page .vc-fixture {
	background: #fff7e8;
	border: 1px solid #ffd699;
	border-radius: 8px;
	padding: 8px 10px;
	margin: 0 0 10px;
	font-size: 13px;
	color: #7a4a00;
}
.vc-compare-page .vc-error {
	margin-top: 10px;
	color: #b42318;
	font-size: 13px;
}
.vc-compare-page .vc-choice-wrap {
	margin: 8px 22px 0;
}
.vc-compare-page .vc-choice {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 12px;
	margin: 12px 0 16px;
}
@media (max-width: 860px) {
	.vc-compare-page .vc-choice { grid-template-columns: 1fr; }
}
.vc-compare-page .vc-choice label {
	display: flex;
	gap: 10px;
	align-items: flex-start;
	padding: 12px;
	border: 1px solid #eee;
	border-radius: 8px;
	background: #fafafa;
	cursor: pointer;
	font-size: 14px;
	font-weight: 600;
}
.vc-compare-page .vc-choice label:has(input:checked) {
	border-color: #e1251c;
	background: #fff7f6;
}
.vc-compare-page .vc-msg {
	margin: 12px 22px 0 !important;
	font-size: 14px;
	font-weight: 600;
}
.vc-compare-page .vc-msg.ok { color: #146c2e; }
.vc-compare-page .vc-msg.err { color: #b42318; }
.vc-compare-page .vc-notes {
	margin: 14px 22px 0;
	padding-left: 18px;
	color: #555;
	font-size: 13px;
}
.vc-compare-page .vc-save-row {
	margin: 0 22px;
}
</style>

<div
	class="vc-compare-page"
	id="vc-page"
	data-ajax="<?php echo esc_url( $ajax_url ); ?>"
	data-nonce="<?php echo esc_attr( $nonce ); ?>"
	data-preselect="<?php echo esc_attr( (string) $preselect ); ?>"
>
	<header class="vc-header">
		<div class="vc-header-copy">
			<span class="vc-badge">Read-only · Manual select · No auto-buy</span>
			<h1>Vendor compare</h1>
			<p>
				Compare Amazon and Grand &amp; Toy for one store product, then choose a vendor manually.
				Missing Amazon ASIN does not remove the product. This screen never places a purchase order.
			</p>
		</div>
		<div class="vc-header-actions">
			<a class="vc-link-btn" href="<?php echo esc_url( $catalog_url ); ?>">Amazon catalog</a>
			<a class="vc-link-btn secondary" href="<?php echo esc_url( $manager_url ); ?>">Manager products</a>
		</div>
	</header>

	<section class="vc-panel">
		<div class="vc-panel-head">
			<h2>1. Choose a store product</h2>
			<span class="vc-panel-total"><?php echo esc_html( (string) count( $store_products ) ); ?> published products</span>
		</div>
		<p>Type a name, SKU, or ASIN. Select a product, then compare vendors.</p>
		<div class="vc-toolbar">
			<input type="search" class="vc-input" id="vc-search" placeholder="Search by product name or SKU" autocomplete="off">
			<label class="vc-check">
				<input type="checkbox" id="vc-fixture">
				G&amp;T fixture (dev only)
			</label>
			<button type="button" class="vc-button" id="vc-run" disabled>Compare vendors</button>
		</div>
		<p class="vc-count" id="vc-count">Type at least 2 letters to search the catalog.</p>
		<div class="vc-list" id="vc-list">
			<?php foreach ( $store_products as $sp ) : ?>
				<div
					class="vc-item"
					hidden
					data-id="<?php echo esc_attr( (string) $sp['id'] ); ?>"
					data-search="<?php echo esc_attr( $sp['search'] ); ?>"
				>
					<div>
						<strong><?php echo esc_html( $sp['name'] ); ?></strong>
						<span class="vc-meta">
							<?php if ( $sp['sku'] !== '' ) : ?>SKU <?php echo esc_html( $sp['sku'] ); ?> · <?php endif; ?>
							<?php echo $sp['asin'] !== '' ? 'ASIN ' . esc_html( $sp['asin'] ) : 'ASIN not linked'; ?>
						</span>
					</div>
					<span class="vc-selected-pill">Selected</span>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<section class="vc-panel" id="vc-results" hidden>
		<div class="vc-panel-head">
			<h2>2. Side-by-side comparison</h2>
		</div>
		<p class="vc-product-label" id="vc-product-label"></p>
		<div class="vc-grid">
			<article class="vc-card" id="vc-amazon-card">
				<div class="vc-card-head amazon">Amazon</div>
				<div class="vc-card-body" id="vc-amazon-body"></div>
			</article>
			<article class="vc-card" id="vc-gt-card">
				<div class="vc-card-head gt">Grand &amp; Toy</div>
				<div class="vc-card-body" id="vc-gt-body"></div>
			</article>
		</div>

		<div class="vc-panel-head" style="margin-top:22px; border-radius:0;">
			<h2>3. Manual vendor selection</h2>
		</div>
		<div class="vc-choice-wrap">
			<p class="vc-hint" style="margin:14px 0 !important;">
				Ops keeps the choice. Saving does <strong>not</strong> submit an Amazon cart or Grand &amp; Toy purchase order.
			</p>
			<div class="vc-choice" id="vc-choice">
				<label><input type="radio" name="vc_choice" value="amazon"> Prefer Amazon</label>
				<label><input type="radio" name="vc_choice" value="gt"> Prefer Grand &amp; Toy</label>
				<label><input type="radio" name="vc_choice" value="none"> Clear / undecided</label>
			</div>
		</div>
		<div class="vc-save-row">
			<button type="button" class="vc-button secondary" id="vc-save" disabled>Save selection</button>
		</div>
		<p class="vc-msg" id="vc-msg" hidden></p>
		<ul class="vc-notes" id="vc-notes"></ul>
	</section>
</div>

<script>
(function () {
	var root = document.getElementById('vc-page');
	if (!root) return;
	var ajax = root.getAttribute('data-ajax');
	var nonce = root.getAttribute('data-nonce');
	var preselect = parseInt(root.getAttribute('data-preselect') || '0', 10);
	var search = document.getElementById('vc-search');
	var list = document.getElementById('vc-list');
	var count = document.getElementById('vc-count');
	var runBtn = document.getElementById('vc-run');
	var fixture = document.getElementById('vc-fixture');
	var results = document.getElementById('vc-results');
	var saveBtn = document.getElementById('vc-save');
	var msg = document.getElementById('vc-msg');
	var selectedId = 0;
	var items = list ? list.querySelectorAll('.vc-item') : [];

	function showMsg(text, ok) {
		msg.hidden = false;
		msg.textContent = text;
		msg.className = 'vc-msg ' + (ok ? 'ok' : 'err');
	}

	function filterList() {
		var q = (search.value || '').trim().toLowerCase();
		var n = 0;
		items.forEach(function (el) {
			var match = q.length >= 2 && (el.getAttribute('data-search') || '').indexOf(q) !== -1;
			el.hidden = !match;
			if (match) n++;
		});
		if (q.length < 2) {
			count.textContent = 'Type at least 2 letters to search the catalog.';
		} else {
			count.textContent = n + ' product' + (n === 1 ? '' : 's') + ' match.';
		}
	}

	function selectItem(el) {
		items.forEach(function (i) { i.classList.remove('is-selected'); });
		el.classList.add('is-selected');
		selectedId = parseInt(el.getAttribute('data-id'), 10) || 0;
		runBtn.disabled = !selectedId;
	}

	function money(row) {
		if (row.price === null || row.price === undefined || row.price === '') return '—';
		var cur = row.currency || 'CAD';
		return cur + ' ' + Number(row.price).toFixed(2);
	}

	function statusClass(status) {
		if (status === 'ok') return 'vc-status vc-status-ok';
		if (status === 'error') return 'vc-status vc-status-error';
		return 'vc-status vc-status-unavailable';
	}

	function renderVendor(target, row) {
		var html = '<span class="' + statusClass(row.status) + '">' + (row.status || 'unavailable') + '</span>';
		if (row.fixture) {
			html += '<div class="vc-fixture">DEV FIXTURE — not live Grand &amp; Toy data. Do not purchase from this row.</div>';
		}
		html += '<dl class="vc-dl">';
		html += '<dt>Identifier</dt><dd>' + escapeHtml(row.product_identifier || '—') + '</dd>';
		html += '<dt>Price</dt><dd>' + escapeHtml(money(row)) + '</dd>';
		html += '<dt>Availability</dt><dd>' + escapeHtml(
			row.availability_quantity !== null && row.availability_quantity !== undefined
				? String(row.availability_quantity)
				: (row.availability_text || '—')
		) + '</dd>';
		html += '<dt>ETA / days</dt><dd>' + escapeHtml(row.estimated_delivery || '—') + '</dd>';
		html += '<dt>Shipping</dt><dd>' + (
			row.shipping_cost === null || row.shipping_cost === undefined || row.shipping_cost === ''
				? '—'
				: escapeHtml(String(row.shipping_cost))
		) + '</dd>';
		html += '<dt>Equivalence</dt><dd>' + escapeHtml(row.equivalence_status || 'unknown') + '</dd>';
		html += '<dt>Retrieved</dt><dd>' + escapeHtml(row.retrieved_at || '—') + '</dd>';
		html += '</dl>';
		if (row.title) {
			html += '<p class="vc-hint" style="margin:12px 0 0 !important;">' + escapeHtml(row.title) + '</p>';
		}
		if (row.error) {
			html += '<p class="vc-error">' + escapeHtml(row.error) + '</p>';
		}
		target.innerHTML = html;
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', nonce);
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	search.addEventListener('input', filterList);
	list.addEventListener('click', function (e) {
		var el = e.target.closest('.vc-item');
		if (!el || el.hidden) return;
		selectItem(el);
	});

	runBtn.addEventListener('click', function () {
		if (!selectedId) return;
		runBtn.disabled = true;
		runBtn.textContent = 'Comparing…';
		msg.hidden = true;
		post('cc_vendor_compare_run', {
			product_id: String(selectedId),
			use_gt_fixture: fixture.checked ? '1' : ''
		}).then(function (json) {
			runBtn.disabled = false;
			runBtn.textContent = 'Compare vendors';
			if (!json || !json.success) {
				showMsg((json && json.data && json.data.message) || 'Compare failed.', false);
				return;
			}
			var data = json.data;
			results.hidden = false;
			document.getElementById('vc-product-label').textContent =
				data.product.name + ' · SKU ' + (data.product.sku || '—') +
				' · eligibility ' + (data.product.eligibility || 'unset');
			renderVendor(document.getElementById('vc-amazon-body'), data.pair.amazon);
			renderVendor(document.getElementById('vc-gt-body'), data.pair.grand_and_toy);
			var choice = data.product.manual_choice || 'none';
			document.querySelectorAll('input[name="vc_choice"]').forEach(function (r) {
				r.checked = (r.value === choice) || (choice === '' && r.value === 'none');
			});
			saveBtn.disabled = false;
			var notes = document.getElementById('vc-notes');
			notes.innerHTML = (data.notes || []).map(function (n) {
				return '<li>' + escapeHtml(n) + '</li>';
			}).join('');
			results.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}).catch(function () {
			runBtn.disabled = false;
			runBtn.textContent = 'Compare vendors';
			showMsg('Network error while comparing.', false);
		});
	});

	saveBtn.addEventListener('click', function () {
		var picked = document.querySelector('input[name="vc_choice"]:checked');
		if (!selectedId || !picked) return;
		saveBtn.disabled = true;
		post('cc_vendor_compare_save_choice', {
			product_id: String(selectedId),
			choice: picked.value
		}).then(function (json) {
			saveBtn.disabled = false;
			if (!json || !json.success) {
				showMsg((json && json.data && json.data.message) || 'Save failed.', false);
				return;
			}
			showMsg(json.data.message || 'Saved.', true);
		}).catch(function () {
			saveBtn.disabled = false;
			showMsg('Network error while saving.', false);
		});
	});

	if (preselect > 0) {
		var match = list.querySelector('.vc-item[data-id="' + preselect + '"]');
		if (match) {
			match.hidden = false;
			selectItem(match);
			search.value = match.querySelector('strong').textContent.slice(0, 24);
			filterList();
			match.hidden = false;
			selectItem(match);
		}
	}
})();
</script>
<?php
get_footer();
