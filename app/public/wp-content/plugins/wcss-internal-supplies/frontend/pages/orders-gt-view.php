<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<head>
  <meta charset="<?php bloginfo( 'charset' ); ?>">
  <?php wp_head(); ?>
</head>

<div class="wcssm-wrap aov-page">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="wcssm-header aov-header">
    <div>
      <p class="aov-eyebrow">Manager · Orders · Grand &amp; Toy</p>
      <h1>Grand &amp; Toy Order View</h1>
    </div>
    <a href="<?php echo esc_url( home_url( '/manager/orders' ) ); ?>" class="btn btn-light">← Back to Orders</a>
  </div>

  <div id="gt-order-detail" class="aov-loading">
    <div class="aov-skeleton">Loading Grand &amp; Toy order details…</div>
  </div>
</div>

<script>
jQuery(function($){
  if (!(window.WCSSM && WCSSM.view==='orders' && WCSSM.action==='gt-view' && WCSSM.id)) {
    return;
  }

  const orderId = WCSSM.id;
  const $box = $('#gt-order-detail');
  const home = (WCSSM.home || '/').replace(/\/+$/, '/');
  const managerBase = (WCSSM.manager_base || '/manager/').replace(/\/+$/, '/');

  function esc(s){
    return (s || '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  }

  function money(v){
    if (v === null || v === undefined || v === '') return '—';
    const n = Number(v);
    if (!isNaN(n)) return 'CAD ' + n.toFixed(2);
    return 'CAD ' + esc(v);
  }

  function statusBadge(status, ok){
    const s = (status || '').toLowerCase();
    let cls = 'aov-badge aov-badge-muted';
    let label = status || 'Unknown';
    if (ok === true || s === 'submitted') {
      cls = 'aov-badge aov-badge-success';
      label = status || 'Submitted';
    } else if (s === 'failed' || ok === false) {
      cls = 'aov-badge aov-badge-danger';
    } else if (s.indexOf('cart') >= 0 || s.indexOf('checkout') >= 0) {
      cls = 'aov-badge aov-badge-warn';
    }
    return '<span class="' + cls + '">' + esc(label) + '</span>';
  }

  function field(label, value){
    if (value === null || value === undefined || value === '') return '';
    return '<div class="aov-field"><span class="aov-label">' + esc(label) + '</span><span class="aov-value">' + esc(value) + '</span></div>';
  }

  $.ajax({
    url: (WCSSM.rest || '/wp-json/wcss/v1/') + 'orders/' + orderId + '/gt',
    headers: { 'X-WP-Nonce': WCSSM.nonce },
    dataType: 'json'
  }).done(function(d){
    if (!d || !d.found || !d.gt) {
      $box.removeClass('aov-loading').html(
        '<div class="aov-card aov-empty">' +
          '<div class="aov-empty-icon">📦</div>' +
          '<h2>No Grand &amp; Toy details yet</h2>' +
          '<p>Order <strong>#' + esc(d?.order?.number || orderId) + '</strong> does not have a saved G&amp;T cart/checkout snapshot.</p>' +
          '<p class="aov-empty-hint">' + esc(d?.message || 'Use G&T Test Order, then Catalog → Cart → Checkout.') + '</p>' +
          '<div class="aov-actions">' +
            '<a class="btn btn-primary" href="' + esc(home + 'gt-catalog/?order-id=' + orderId) + '">Open G&amp;T Test Order</a>' +
            '<a class="btn" href="' + esc(managerBase + 'orders/view/' + orderId) + '">View WooCommerce Order</a>' +
          '</div>' +
        '</div>'
      );
      return;
    }

    const g = d.gt;
    const items = Array.isArray(g.cart_items) ? g.cart_items : [];
    const ship = g.ship_to || {};
    const res = g.gt_result || {};
    const ok = res.ok === true;
    const fail = res.ok === false;
    const currency = 'CAD';

    let itemsTotal = 0;
    items.forEach(function(it){
      const p = Number(it.unit_price);
      const q = Number(it.qty) || 0;
      if (!isNaN(p)) itemsTotal += p * q;
    });

    let itemsHtml = '';
    if (!items.length) {
      itemsHtml = '<div class="aov-muted">No G&amp;T cart line items saved.</div>';
    } else {
      itemsHtml =
        '<div class="aov-table-wrap"><table class="aov-table">' +
          '<thead><tr><th>#</th><th>Product</th><th>Part</th><th>Qty</th><th>Unit Price</th><th>Facility</th><th>Line Total</th></tr></thead><tbody>';
      items.forEach(function(it, idx){
        const unit = Number(it.unit_price);
        const qty = Number(it.qty) || 0;
        const line = (!isNaN(unit) ? unit * qty : '');
        itemsHtml +=
          '<tr>' +
            '<td class="aov-num">' + (idx + 1) + '</td>' +
            '<td>' +
              '<div class="aov-product-name">' + esc(it.name || 'Untitled') + '</div>' +
              (it.sku ? ('<div class="aov-submeta">SKU: <code>' + esc(it.sku) + '</code></div>') : '') +
              (it.available ? ('<div class="aov-submeta">Stock: ' + esc(it.available) + (it.days ? ' · ' + esc(it.days) : '') + '</div>') : '') +
            '</td>' +
            '<td><code class="aov-code">' + esc(it.part_id || '—') + '</code></td>' +
            '<td class="aov-num">' + esc(it.qty || 0) + '</td>' +
            '<td class="aov-num">' + money(it.unit_price) + '</td>' +
            '<td class="aov-num">' + esc(it.facility_id || '—') + '</td>' +
            '<td class="aov-num aov-strong">' + (line !== '' ? money(line) : '—') + '</td>' +
          '</tr>';
      });
      itemsHtml += '</tbody></table></div>';
      if (itemsTotal > 0) {
        itemsHtml +=
          '<div class="aov-totals-stack">' +
            '<div class="aov-totals aov-totals-grand"><span>G&amp;T items subtotal</span><strong>' +
            money(itemsTotal) + '</strong></div>' +
          '</div>';
      }
    }

    const shipFields = [
      ['Name / company', ship.name],
      ['Deliver to', ship.deliver_to],
      ['Streets', Array.isArray(ship.streets) ? ship.streets.join(', ') : ''],
      ['City', ship.city],
      ['Province', ship.state],
      ['Postal code', ship.postal_code],
      ['Country', ship.country || 'CA'],
    ].map(function(row){ return field(row[0], row[1]); }).join('');
    const hasShip = !!(ship.name || ship.deliver_to || ship.city || (ship.streets && ship.streets.length));

    const errHtml = (Array.isArray(res.errors) && res.errors.length)
      ? '<p class="aov-api-msg" style="color:#a61b1b">' + esc(res.errors.join('; ')) + '</p>'
      : '';

    const rawBlocks = [];
    if (res && Object.keys(res).length) {
      rawBlocks.push(
        '<div class="aov-raw-block">' +
          '<h4>Grand &amp; Toy stage response</h4>' +
          errHtml +
          '<pre class="aov-pre">' + esc(JSON.stringify(res, null, 2)) + '</pre>' +
        '</div>'
      );
    }

    const wcTotal = (d.order.total_formatted)
      ? d.order.total_formatted
      : money(d.order.total);

    $box.removeClass('aov-loading').html(
      '<div class="aov-summary-card aov-card">' +
        '<div class="aov-summary-top">' +
          '<div>' +
            '<p class="aov-eyebrow">WooCommerce order</p>' +
            '<h2>Order #' + esc(d.order.number || orderId) + '</h2>' +
            '<p class="aov-sub">Updated ' + esc(g.updated_at || g.submitted_at || g.created_at || '—') + '</p>' +
          '</div>' +
          '<div class="aov-summary-badges">' +
            statusBadge(g.status, res.ok) +
            (ok ? '<span class="aov-badge aov-badge-success">Stage PO: Yes</span>' : '') +
            (fail ? '<span class="aov-badge aov-badge-danger">Stage PO: No</span>' : '') +
            (g.stage_only ? '<span class="aov-badge aov-badge-warn">Stage only</span>' : '') +
          '</div>' +
        '</div>' +
        '<div class="aov-meta-grid">' +
          '<div class="aov-meta-item"><span>Source</span><strong>' + esc(g.source || '—') + '</strong></div>' +
          '<div class="aov-meta-item"><span>PO ID</span><strong><code>' + esc(g.po_id || '—') + '</code></strong></div>' +
          '<div class="aov-meta-item"><span>Line items</span><strong>' + items.length + '</strong></div>' +
          '<div class="aov-meta-item"><span>WC total</span><strong>' + esc(wcTotal) + '</strong></div>' +
          '<div class="aov-meta-item"><span>G&amp;T subtotal</span><strong>' + (itemsTotal > 0 ? money(itemsTotal) : '—') + '</strong></div>' +
          '<div class="aov-meta-item"><span>SenderIdentity</span><strong>' + esc(g.sender || '—') + '</strong></div>' +
          '<div class="aov-meta-item"><span>Backorder</span><strong>' + esc(g.backorder || '—') + '</strong></div>' +
          '<div class="aov-meta-item"><span>HTTP</span><strong>' + esc(res.http_code || '—') + '</strong></div>' +
          '<div class="aov-meta-item"><span>Currency</span><strong>' + esc(currency) + '</strong></div>' +
        '</div>' +
      '</div>' +

      '<div class="aov-grid-2">' +
        '<div class="aov-card">' +
          '<div class="aov-card-head"><h3>Shipping Information</h3></div>' +
          (hasShip
            ? ('<div class="aov-fields aov-fields-2col">' + shipFields + '</div>')
            : '<div class="aov-muted">No shipping form data saved yet (saved after checkout).</div>') +
        '</div>' +
        '<div class="aov-card">' +
          '<div class="aov-card-head"><h3>Grand &amp; Toy Order Meta</h3></div>' +
          '<div class="aov-fields">' +
            field('Snapshot status', g.status) +
            field('Source', g.source) +
            field('Created', g.created_at) +
            field('Updated', g.updated_at) +
            field('Submitted at', g.submitted_at) +
            field('PO ID', g.po_id) +
            field('SenderIdentity', g.sender) +
            field('Backorder method', g.backorder) +
            field('WC order ID', g.wc_order_id || orderId) +
          '</div>' +
        '</div>' +
      '</div>' +

      '<div class="aov-card">' +
        '<div class="aov-card-head">' +
          '<h3>G&amp;T Cart / Line Items</h3>' +
          '<span class="aov-chip">' + items.length + ' item' + (items.length === 1 ? '' : 's') + '</span>' +
        '</div>' +
        itemsHtml +
      '</div>' +

      (rawBlocks.length ? (
        '<div class="aov-card">' +
          '<details class="aov-details" open>' +
            '<summary>Grand &amp; Toy technical details <span class="aov-chip">Stage API</span></summary>' +
            rawBlocks.join('') +
          '</details>' +
        '</div>'
      ) : '') +

      '<div class="aov-actions">' +
        '<a class="btn btn-primary" href="' + esc(managerBase + 'orders/view/' + orderId) + '">View WooCommerce Order</a>' +
        '<a class="btn" href="' + esc(home + 'gt-catalog/?order-id=' + orderId) + '">G&amp;T Test Order</a>' +
        '<a class="btn btn-light" href="' + esc(managerBase + 'orders/') + '">Back to Orders</a>' +
      '</div>'
    );
  }).fail(function(x){
    $box.removeClass('aov-loading').html(
      '<div class="aov-card aov-empty">' +
        '<h2>Could not load G&amp;T details</h2>' +
        '<p>' + esc(x.responseJSON?.message || x.statusText) + '</p>' +
      '</div>'
    );
  });
});
</script>
<?php include WCSS_DIR . 'frontend/pages/partials/orders-vendor-view-styles.php'; ?>
<?php wp_footer(); ?>
