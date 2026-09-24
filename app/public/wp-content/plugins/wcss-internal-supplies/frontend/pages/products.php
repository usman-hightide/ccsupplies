<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>


  <div id="wcssm-flash" class="wcssm-flash" style="display:none;"></div>

  


  <div class="wcssm-header">
    <h2>Products</h2>

    <div class="actions">
      <input id="p-search" class="input" type="search" placeholder="Search name or SKU…" />
      <button id="p-refresh" class="btn btn-light">Search</button>
      <a
        id="p-export"
        class="btn btn-primary"
        href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&page=product_exporter' ) ); ?>"
        target="_blank" rel="noopener"
        >Export Products</a>
    </div>

    <a href="<?php echo esc_url( home_url('/manager/products/create') ); ?>" class="btn btn-primary">+ Create Product</a>
  </div>

  <div id="wcssm-products-grid" class="tablelike" style="margin-top:12px"></div>
</div>


<script>
jQuery(function($){
  // Page initializer is in manager.js (initProductsList)
  if (window.WCSSM && WCSSM.view==='products' && !WCSSM.action) {
    if (typeof window.initProductsList === 'function') window.initProductsList();
  }
});

</script>