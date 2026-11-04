<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap wcssm-products ccm-content" data-ccm-screen="products">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">Products</h1><p class="ccm-sub">Everything stores can order, with each supplier’s price side by side.</p></div>
    <div class="ccm-actions"><button type="button" class="ccm-btn" data-ccm-export>Export</button><a class="ccm-btn primary" href="<?php echo esc_url( home_url( '/manager/products/create' ) ); ?>">+ Add product</a></div>
  </div>
  <div class="ccm-tablewrap">
    <div class="ccm-toolbar">
      <label class="ccm-field grow">Search
        <input class="ccm-input" type="search" placeholder="Product name or SKU…" id="p-q">
      </label>
      <label class="ccm-field fixed">Vendor
        <select class="ccm-select" id="pl-vendor">
          <option value="all">All vendors</option>
          <option value="none">No vendor</option>
        </select>
      </label>
      <label class="ccm-field fixed">Show
        <select class="ccm-select" id="p-filter">
          <option value="all">All products</option>
          <option value="issues">Needs fixing</option>
          <option value="compare">Has price comparison</option>
        </select>
      </label>
    </div>
    <table class="ccm-table">
      <thead><tr><th>Product</th><th>SKU</th><th>Vendor</th><th class="num">Website</th><th class="num">Amazon</th><th class="num">Grand &amp; Toy</th><th>Notes</th><th class="num">Actions</th></tr></thead>
      <tbody id="p-rows"><tr><td colspan="8" class="ccm-empty">Loading products…</td></tr></tbody>
    </table>
    <div class="ccm-empty" id="p-empty" hidden><strong>No products found</strong>Try another name, SKU or vendor.</div>
    <div class="ccm-listfoot"><span id="p-foot"></span><span class="ccm-muted ccm-small">Green = lowest price available</span><span class="ccm-pager" id="p-pager"></span></div>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
