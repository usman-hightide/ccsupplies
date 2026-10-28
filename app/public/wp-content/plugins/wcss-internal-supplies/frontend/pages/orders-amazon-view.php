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

    const shipAmt = (a.shipping_amount !== undefined && a.shipping_amount !== null && a.shipping_amount !== '')
      ? Number(a.shipping_amount)
      : 0;
    const shipDisc = (a.shipping_discount !== undefined && a.shipping_discount !== null && a.shipping_discount !== '')
      ? Number(a.shipping_discount)
      : 0;
    const otherDisc = (a.discount_amount !== undefined && a.discount_amount !== null && a.discount_amount !== '')
      ? Number(a.discount_amount)
      : 0;
    const taxAmt = (a.tax_amount !== undefined && a.tax_amount !== null && a.tax_amount !== '')
      ? Number(a.tax_amount)
      : 0;
    const orderTotal = (itemsTotal || 0)
      + (isNaN(shipAmt) ? 0 : shipAmt)
      + (isNaN(shipDisc) ? 0 : shipDisc)
      + (isNaN(otherDisc) ? 0 : otherDisc)
      + (isNaN(taxAmt) ? 0 : taxAmt);

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

      let totalsHtml = '';
      if (itemsTotal > 0 || shipAmt || shipDisc || otherDisc || taxAmt || orderTotal) {
        totalsHtml += '<div class="aov-totals-stack">';
        if (itemsTotal > 0) {
          totalsHtml +=
            '<div class="aov-totals"><span>Amazon items subtotal</span><strong>' +
            money(itemsTotal, currency) + '</strong></div>';
        }
        totalsHtml +=
          '<div class="aov-totals"><span>Shipping</span><strong>' +
          money(isNaN(shipAmt) ? 0 : shipAmt, currency) + '</strong></div>';
        if (!isNaN(shipDisc) && shipDisc !== 0) {
          totalsHtml +=
            '<div class="aov-totals"><span>Shipping discount</span><strong>' +
            money(shipDisc, currency) + '</strong></div>';
        }
        if (!isNaN(otherDisc) && otherDisc !== 0) {
          totalsHtml +=
            '<div class="aov-totals"><span>Discount</span><strong>' +
            money(otherDisc, currency) + '</strong></div>';
        }
        totalsHtml +=
          '<div class="aov-totals"><span>Tax</span><strong>' +
          money(isNaN(taxAmt) ? 0 : taxAmt, currency) + '</strong></div>';
        totalsHtml +=
          '<div class="aov-totals aov-totals-grand"><span>Amazon order total</span><strong>' +
          money(orderTotal, currency) + '</strong></div>';
        totalsHtml += '</div>';
      }
      itemsHtml += totalsHtml;
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
          '<div class="aov-meta-item"><span>Shipping</span><strong>' + money(isNaN(shipAmt) ? 0 : shipAmt, currency) + '</strong></div>' +
          ((!isNaN(shipDisc) && shipDisc !== 0)
            ? '<div class="aov-meta-item"><span>Shipping discount</span><strong>' + money(shipDisc, currency) + '</strong></div>'
            : '') +
          '<div class="aov-meta-item"><span>Tax</span><strong>' + money(isNaN(taxAmt) ? 0 : taxAmt, currency) + '</strong></div>' +
          '<div class="aov-meta-item"><span>Amazon total</span><strong>' + money(orderTotal, currency) + '</strong></div>' +
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
<?php include WCSS_DIR . 'frontend/pages/partials/orders-vendor-view-styles.php'; ?>
