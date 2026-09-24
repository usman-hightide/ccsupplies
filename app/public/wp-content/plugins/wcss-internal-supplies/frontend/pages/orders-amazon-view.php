<?php if ( ! defined('ABSPATH') ) exit; ?>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <?php wp_head(); ?>
</head>

<div class="wcssm-wrap aov-page">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="wcssm-header aov-header">
    <div>
      <p class="aov-eyebrow">Manager · Orders</p>
      <h1>Amazon Order View</h1>
    </div>
    <a href="<?php echo esc_url( home_url('/manager/orders') ); ?>" class="btn btn-light">← Back to Orders</a>
  </div>

  <div id="amazon-order-detail" class="aov-loading">
    <div class="aov-skeleton">Loading Amazon order details…</div>
  </div>
</div>

<script>
jQuery(function($){
  if (!(window.WCSSM && WCSSM.view==='orders' && WCSSM.action==='amazon-view' && WCSSM.id)) {
    return;
  }

  const orderId = WCSSM.id;
  const $box = $('#amazon-order-detail');

  function esc(s){
    return (s || '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  }

  function money(v, currency){
    if (v === null || v === undefined || v === '') return '—';
    const c = currency || 'CAD';
    const n = Number(v);
    if (!isNaN(n)) {
      return c + ' ' + n.toFixed(2);
    }
    return c + ' ' + esc(v);
  }

  function statusBadge(status, success){
    const s = (status || '').toLowerCase();
    let cls = 'aov-badge aov-badge-muted';
    let label = status || 'Unknown';
    if (success === true || s === 'placed') {
      cls = 'aov-badge aov-badge-success';
      label = status || 'Placed';
    } else if (s.indexOf('fail') >= 0 || success === false) {
      cls = 'aov-badge aov-badge-danger';
    } else if (s.indexOf('cart') >= 0 || s.indexOf('checkout') >= 0) {
      cls = 'aov-badge aov-badge-warn';
    }
    return '<span class="' + cls + '">' + esc(label) + '</span>';
  }

  function field(label, value){
    if (!value) return '';
    return '<div class="aov-field"><span class="aov-label">' + esc(label) + '</span><span class="aov-value">' + esc(value) + '</span></div>';
  }

  $.ajax({
    url: (WCSSM.rest || '/wp-json/wcss/v1/') + 'orders/' + orderId + '/amazon',
    headers: { 'X-WP-Nonce': WCSSM.nonce },
    dataType: 'json'
  }).done(function(d){
    if (!d || !d.found || !d.amazon) {
      $box.removeClass('aov-loading').html(
        '<div class="aov-card aov-empty">' +
          '<div class="aov-empty-icon">📦</div>' +
          '<h2>No Amazon details yet</h2>' +
          '<p>Order <strong>#' + esc(d?.order?.number || orderId) + '</strong> does not have a saved Amazon cart/checkout snapshot.</p>' +
          '<p class="aov-empty-hint">' + esc(d?.message || 'Use Amazon Test Order, then open Cart/Checkout to save details.') + '</p>' +
          '<div class="aov-actions">' +
            '<a class="btn btn-primary" href="' + esc((WCSSM.home || '/') + 'amazon-catalog/?order-id=' + orderId) + '">Open Amazon Test Order</a>' +
            '<a class="btn" href="' + esc((WCSSM.manager_base || '/manager/') + 'orders/view/' + orderId) + '">View WooCommerce Order</a>' +
          '</div>' +
        '</div>'
      );
      return;
    }

    const a = d.amazon;
    const ship = a.shipping || {};
    const items = Array.isArray(a.cart_items) ? a.cart_items : [];
    const resp = a.amazon_response || {};
    const payload = a.order_payload || null;
    const placedOk = resp.success === true;
    const placedFail = resp.success === false;
    const currency = a.currency || (items[0] && items[0].currency) || d.order.currency || 'CAD';
    const wcTotal = (d.order.total_formatted)
      ? d.order.total_formatted
      : money(d.order.total, d.order.currency || 'CAD');

    let itemsTotal = Number(a.cart_subtotal);
    if (isNaN(itemsTotal) || !a.cart_subtotal) {
      itemsTotal = 0;
      items.forEach(function(it){
        const p = Number(it.price);
        const q = Number(it.quantity) || 0;
        if (!isNaN(p)) itemsTotal += p * q;
      });
    }

    let itemsHtml = '';
    if (!items.length) {
      itemsHtml = '<div class="aov-muted">No Amazon cart line items saved.</div>';
    } else {
      itemsHtml =
        '<div class="aov-table-wrap"><table class="aov-table">' +
          '<thead><tr><th>#</th><th>Product</th><th>ASIN</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr></thead><tbody>';
      items.forEach(function(it, idx){
        const unit = Number(it.price);
        const qty = Number(it.quantity) || 0;
        const line = (!isNaN(unit) ? unit * qty : '');
        itemsHtml +=
          '<tr>' +
            '<td class="aov-num">' + (idx + 1) + '</td>' +
            '<td>' +
              '<div class="aov-product-name">' + esc(it.title || 'Untitled product') + '</div>' +
              (it.buying_option_id ? ('<div class="aov-submeta">Buying option: <code>' + esc(it.buying_option_id) + '</code></div>') : '') +
              (it.cart_item_id ? ('<div class="aov-submeta">Cart item ID: <code>' + esc(it.cart_item_id) + '</code></div>') : '') +
            '</td>' +
            '<td><code class="aov-code">' + esc(it.asin || '—') + '</code></td>' +
            '<td class="aov-num">' + esc(it.quantity || 0) + '</td>' +
            '<td class="aov-num">' + money(it.price, it.currency || currency) + '</td>' +
            '<td class="aov-num aov-strong">' + (line !== '' ? money(line, it.currency || currency) : '—') + '</td>' +
          '</tr>';
      });
      itemsHtml += '</tbody></table></div>';
      if (itemsTotal > 0) {
        itemsHtml +=
          '<div class="aov-totals">' +
            '<span>Amazon items subtotal</span>' +
            '<strong>' + money(itemsTotal, currency) + '</strong>' +
          '</div>';
      }
    }

    const shipFields = [
      ['Full Name', ship.full_name],
      ['Email', ship.email],
      ['Phone', ship.phone],
      ['Company', ship.company_name],
      ['Address', ship.address_line1],
      ['Address Line 2', ship.address_line2],
      ['City', ship.city],
      ['Province', ship.state],
      ['Postal Code', ship.postal_code],
      ['Country', ship.country || 'CA'],
      ['Group ID', ship.group_identifier],
      ['PO Number', ship.purchase_order_number],
      ['Trial mode', (ship.trial_mode === true || ship.trial_mode === 1 || ship.trial_mode === '1') ? 'Yes' : (ship.trial_mode === false ? 'No' : '')],
    ].map(function(row){ return field(row[0], row[1]); }).join('');

    const hasShip = !!(ship.full_name || ship.email || ship.address_line1);
    const rawBlocks = [];
    if (resp && Object.keys(resp).length) {
      rawBlocks.push(
        '<div class="aov-raw-block">' +
          '<h4>Amazon API response</h4>' +
          (resp.message ? ('<p class="aov-api-msg">' + esc(resp.message) + '</p>') : '') +
          '<pre class="aov-pre">' + esc(JSON.stringify(resp, null, 2)) + '</pre>' +
        '</div>'
      );
    }
    if (payload && typeof payload === 'object') {
      rawBlocks.push(
        '<div class="aov-raw-block">' +
          '<h4>Order payload sent to Amazon</h4>' +
          '<pre class="aov-pre">' + esc(JSON.stringify(payload, null, 2)) + '</pre>' +
        '</div>'
      );
    }
    if (a.cart_raw) {
      rawBlocks.push(
        '<div class="aov-raw-block">' +
          '<h4>Amazon cart raw</h4>' +
          '<pre class="aov-pre">' + esc(JSON.stringify(a.cart_raw, null, 2)) + '</pre>' +
        '</div>'
      );
    }

    $box.removeClass('aov-loading').html(
      '<div class="aov-summary-card aov-card">' +
        '<div class="aov-summary-top">' +
          '<div>' +
            '<p class="aov-eyebrow">WooCommerce order</p>' +
            '<h2>Order #' + esc(d.order.number || orderId) + '</h2>' +
            '<p class="aov-sub">Updated ' + esc(a.updated_at || a.placed_at || a.created_at || '—') + '</p>' +
          '</div>' +
          '<div class="aov-summary-badges">' +
            statusBadge(a.status, resp.success) +
            (placedOk ? '<span class="aov-badge aov-badge-success">Place order: Yes</span>' : '') +
            (placedFail ? '<span class="aov-badge aov-badge-danger">Place order: No</span>' : '') +
          '</div>' +
        '</div>' +
        '<div class="aov-meta-grid">' +
          '<div class="aov-meta-item"><span>Source</span><strong>' + esc(a.source || '—') + '</strong></div>' +
          '<div class="aov-meta-item"><span>Request ID</span><strong><code>' + esc(a.amazon_request_id || resp.request_id || '—') + '</code></strong></div>' +
          '<div class="aov-meta-item"><span>Line items</span><strong>' + items.length + '</strong></div>' +
          '<div class="aov-meta-item"><span>WC total</span><strong>' + esc(wcTotal) + '</strong></div>' +
          '<div class="aov-meta-item"><span>Amazon subtotal</span><strong>' + (itemsTotal > 0 ? money(itemsTotal, currency) : '—') + '</strong></div>' +
          '<div class="aov-meta-item"><span>Cart ID</span><strong><code>' + esc(a.cart_id || '—') + '</code></strong></div>' +
          '<div class="aov-meta-item"><span>Region</span><strong>' + esc(a.region || 'CA') + '</strong></div>' +
          '<div class="aov-meta-item"><span>Currency</span><strong>' + esc(currency) + '</strong></div>' +
        '</div>' +
      '</div>' +

      '<div class="aov-grid-2">' +
        '<div class="aov-card">' +
          '<div class="aov-card-head"><h3>Shipping Information</h3></div>' +
          (hasShip
            ? ('<div class="aov-fields aov-fields-2col">' + shipFields + '</div>')
            : '<div class="aov-muted">No shipping form data saved yet.</div>') +
        '</div>' +

        '<div class="aov-card">' +
          '<div class="aov-card-head"><h3>Amazon Order Meta</h3></div>' +
          '<div class="aov-fields">' +
            field('Snapshot status', a.status) +
            field('Source', a.source) +
            field('Created', a.created_at) +
            field('Updated', a.updated_at) +
            field('Placed at', a.placed_at) +
            field('Amazon request ID', a.amazon_request_id || resp.request_id) +
            field('Amazon user email', a.user_email) +
            field('WC order ID', a.wc_order_id || orderId) +
            (resp.message ? field('API message', resp.message) : '') +
          '</div>' +
        '</div>' +
      '</div>' +

      '<div class="aov-card">' +
        '<div class="aov-card-head">' +
          '<h3>Amazon Cart / Line Items</h3>' +
          '<span class="aov-chip">' + items.length + ' item' + (items.length === 1 ? '' : 's') + '</span>' +
        '</div>' +
        itemsHtml +
      '</div>' +

      (rawBlocks.length ? (
        '<div class="aov-card">' +
          '<details class="aov-details" open>' +
            '<summary>Amazon technical details <span class="aov-chip">API / Payload</span></summary>' +
            rawBlocks.join('') +
          '</details>' +
        '</div>'
      ) : '') +

      '<div class="aov-actions">' +
        '<a class="btn btn-primary" href="' + esc((WCSSM.manager_base || '/manager/') + 'orders/view/' + orderId) + '">View WooCommerce Order</a>' +
        '<a class="btn" href="' + esc((WCSSM.home || '/') + 'amazon-catalog/?order-id=' + orderId) + '">Amazon Test Order</a>' +
        '<a class="btn btn-light" href="' + esc((WCSSM.manager_base || '/manager/') + 'orders/') + '">Back to Orders</a>' +
      '</div>'
    );
  }).fail(function(x){
    $box.removeClass('aov-loading').html(
      '<div class="aov-card aov-empty">' +
        '<h2>Could not load Amazon details</h2>' +
        '<p>' + esc(x.responseJSON?.message || x.statusText) + '</p>' +
      '</div>'
    );
  });
});
</script>

<style>
.aov-page .aov-header h1 { margin: 0; }
.aov-eyebrow {
  margin: 0 0 4px;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: #6b7280;
}
.aov-sub {
  margin: 4px 0 0;
  color: #6b7280;
  font-size: 13px;
}
.aov-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 18px 20px;
  box-shadow: 0 1px 2px rgba(16,24,40,.04);
  margin-bottom: 14px;
}
.aov-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}
.aov-card-head h3 {
  margin: 0;
  font-size: 16px;
}
.aov-summary-card { padding: 20px; }
.aov-summary-top {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  align-items: flex-start;
  margin-bottom: 16px;
}
.aov-summary-top h2 {
  margin: 0;
  font-size: 24px;
  letter-spacing: -.02em;
}
.aov-summary-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
}
.aov-badge {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.2;
}
.aov-badge-success { background: #ecfdf3; color: #067647; }
.aov-badge-danger { background: #fef3f2; color: #b42318; }
.aov-badge-warn { background: #fffaeb; color: #b54708; }
.aov-badge-muted { background: #f3f4f6; color: #4b5563; }
.aov-chip {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 999px;
  background: #f3f4f6;
  color: #4b5563;
  font-size: 12px;
  font-weight: 600;
}
.aov-meta-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}
.aov-fields-2col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 16px;
}
.aov-raw-block + .aov-raw-block { margin-top: 14px; }
.aov-raw-block h4 {
  margin: 0 0 8px;
  font-size: 13px;
  color: #374151;
}
@media (max-width: 900px) {
  .aov-fields-2col {
    grid-template-columns: 1fr;
  }
}
.aov-meta-item {
  background: #f8fafc;
  border: 1px solid #eef2f7;
  border-radius: 10px;
  padding: 10px 12px;
}
.aov-meta-item span {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: #6b7280;
  margin-bottom: 4px;
}
.aov-meta-item strong {
  font-size: 13px;
  word-break: break-word;
}
.aov-grid-2 {
  display: grid;
  grid-template-columns: 1.2fr .8fr;
  gap: 14px;
  margin-bottom: 14px;
}
.aov-grid-2 > .aov-card { margin-bottom: 0; }
.aov-fields {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
}
.aov-fields-compact {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid #eef2f7;
}
.aov-field {
  display: grid;
  grid-template-columns: 140px 1fr;
  gap: 12px;
  align-items: start;
}
.aov-label {
  color: #6b7280;
  font-size: 13px;
}
.aov-value {
  font-weight: 600;
  font-size: 13px;
  color: #111827;
  word-break: break-word;
}
.aov-ship-name {
  font-size: 18px;
  font-weight: 700;
  color: #111827;
}
.aov-ship-company {
  margin-top: 2px;
  color: #4b5563;
  font-weight: 600;
}
.aov-ship-lines {
  margin-top: 10px;
  color: #374151;
  font-size: 14px;
  line-height: 1.55;
}
.aov-table-wrap {
  overflow: auto;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
}
.aov-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 640px;
}
.aov-table th {
  background: #f8fafc;
  color: #6b7280;
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: .03em;
  text-align: left;
  padding: 10px 12px;
  border-bottom: 1px solid #e5e7eb;
}
.aov-table td {
  padding: 12px;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: top;
  font-size: 13px;
}
.aov-table tr:last-child td { border-bottom: 0; }
.aov-table tbody tr:hover { background: #fafbfc; }
.aov-product-name { font-weight: 650; color: #111827; }
.aov-submeta { margin-top: 4px; color: #6b7280; font-size: 12px; }
.aov-num { text-align: right; white-space: nowrap; }
.aov-strong { font-weight: 700; }
.aov-code, .aov-meta-item code, .aov-submeta code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12px;
  background: #f3f4f6;
  padding: 2px 6px;
  border-radius: 6px;
}
.aov-totals {
  display: flex;
  justify-content: flex-end;
  gap: 16px;
  align-items: center;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px dashed #e5e7eb;
  color: #4b5563;
}
.aov-totals strong {
  font-size: 16px;
  color: #111827;
}
.aov-details summary {
  cursor: pointer;
  font-weight: 700;
  list-style: none;
  display: flex;
  align-items: center;
  gap: 8px;
}
.aov-details summary::-webkit-details-marker { display: none; }
.aov-details[open] summary { margin-bottom: 12px; }
.aov-api-msg { color: #4b5563; margin: 0 0 10px; }
.aov-pre {
  margin: 0;
  white-space: pre-wrap;
  background: #0b1220;
  color: #e5e7eb;
  padding: 14px;
  border-radius: 10px;
  overflow: auto;
  font-size: 12px;
  line-height: 1.5;
}
.aov-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin: 4px 0 24px;
}
.aov-actions .btn {
  padding: 10px 14px;
  font-weight: 600;
  text-decoration: none;
}
.aov-actions .btn-primary {
  background: #111827;
  border-color: #111827;
  color: #fff;
}
.aov-empty {
  text-align: center;
  padding: 36px 24px;
}
.aov-empty-icon { font-size: 28px; margin-bottom: 8px; }
.aov-empty h2 { margin: 0 0 8px; }
.aov-empty-hint { color: #6b7280; }
.aov-muted { color: #6b7280; }
.aov-skeleton {
  background: #fff;
  border: 1px dashed #d1d5db;
  border-radius: 12px;
  padding: 24px;
  color: #6b7280;
}
@media (max-width: 900px) {
  .aov-meta-grid,
  .aov-grid-2 {
    grid-template-columns: 1fr;
  }
  .aov-summary-top {
    flex-direction: column;
  }
  .aov-field {
    grid-template-columns: 1fr;
    gap: 2px;
  }
}
</style>
