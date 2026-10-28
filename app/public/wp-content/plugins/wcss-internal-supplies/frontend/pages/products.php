<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap wcssm-products">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>


  <div id="wcssm-flash" class="wcssm-flash" style="display:none;"></div>

  


  <div class="wcssm-header">
    <h2>Products</h2>

    <div class="actions">
      <div class="wcssm-products-search" role="search">
        <input id="p-search" class="input" type="search" placeholder="Search name or SKU…" aria-label="Search products" />
        <select id="p-vendor" class="input wcssm-vendor-filter" aria-label="Filter by vendor">
          <option value="">All vendors</option>
          <option value="amazon">Amazon</option>
          <option value="grand-toy">Grand &amp; Toy</option>
        </select>
        <button id="p-refresh" class="btn btn-light" type="button">Search</button>
      </div>
      <a
        id="p-export"
        class="btn btn-primary"
        href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=product_exporter' ) ); ?>"
        target="_blank" rel="noopener"
        >Export Products</a>
    </div>

    <a href="<?php echo esc_url( home_url('/manager/products/create') ); ?>" class="btn btn-primary">+ Create Product</a>
  </div>

  <div id="wcssm-products-grid" class="tablelike"></div>
</div>


<script>
jQuery(function($){
  // Page initializer is in manager.js (initProductsList)
  if (window.WCSSM && WCSSM.view==='products' && !WCSSM.action) {
    if (typeof window.initProductsList === 'function') window.initProductsList();
  }
});

</script>