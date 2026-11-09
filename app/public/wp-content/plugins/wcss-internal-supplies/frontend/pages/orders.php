<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap wcssm-orders ccm-content" data-ccm-screen="orders">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">Orders</h1><p class="ccm-sub">Review supply requests from stores. Approving an order sends it to the vendor.</p></div>
    <div class="ccm-actions"><button type="button" class="ccm-btn" data-ccm-export>Export CSV</button><a class="ccm-btn primary" href="<?php echo esc_url( home_url( '/manager/orders/new' ) ); ?>">New order</a></div>
  </div>
  <div class="ccm-tablewrap">
    <div class="ccm-tabs" style="padding:0 12px" role="tablist" aria-label="Filter by status" id="o-tabs"></div>
    <div class="ccm-toolbar">
      <label class="ccm-field grow">Search
        <input class="ccm-input" type="search" placeholder="Order #, email…" id="o-q">
      </label>
      <label class="ccm-field fixed">Type
        <select class="ccm-select" id="o-type">
          <option value="all">All types</option>
          <option value="stationery">Stationery</option>
          <option value="ink">Ink</option>
        </select>
      </label>
      <label class="ccm-field fixed">Vendor
        <select class="ccm-select" id="o-vendor">
          <option value="all">All vendors</option>
          <option value="none">Not assigned</option>
        </select>
      </label>
      <label class="ccm-field fixed">Month
        <select class="ccm-select" id="o-month">
          <option value="all">All months</option>
        </select>
      </label>
      <label class="ccm-field fixed">Ordering week
        <select class="ccm-select" id="o-week">
          <option value="all">All weeks</option>
        </select>
      </label>
      <button type="button" class="ccm-btn" id="o-clear">Clear filters</button>
    </div>
    <table class="ccm-table">
      <thead><tr><th>Order</th><th>Date</th><th>Requested by</th><th>Vendor</th><th class="num">Total</th><th>Status</th><th class="num">Actions</th></tr></thead>
      <tbody id="o-rows"><tr><td colspan="7" class="ccm-empty">Loading orders…</td></tr></tbody>
    </table>
    <div class="ccm-empty" id="o-empty" hidden><strong>No orders match these filters</strong>Clear the filters to see every order.</div>
    <div class="ccm-listfoot"><span id="o-foot"></span><span class="ccm-pager" id="o-pager"></span></div>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
