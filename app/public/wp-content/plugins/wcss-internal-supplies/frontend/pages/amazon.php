<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="amazon">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>
  <?php if ( ! WCSS_Vendor_Status::key_is_active( 'amazon' ) ) : ?>
    <div class="ccm-card ccm-empty"><strong>Amazon is inactive</strong>Activate it in the Vendors tab to use this catalog.</div>
  </div>
  <?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
  <?php return; endif; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">Amazon catalog</h1><p class="ccm-sub">Products supplied by Amazon and whether their prices are up to date.</p></div>
    <div class="ccm-actions"><button type="button" class="ccm-btn primary" id="az-sync">Sync prices now</button></div>
  </div>
  <div class="ccm-minis">
    <div class="ccm-card"><div class="ccm-mini-v" id="az-count">–</div><div class="ccm-mini-l">Amazon products</div></div>
    <div class="ccm-card"><div class="ccm-mini-v" id="az-synced">–</div><div class="ccm-mini-l">Prices synced</div></div>
    <div class="ccm-card"><div class="ccm-mini-v" id="az-last">–</div><div class="ccm-mini-l">Last price sync</div></div>
  </div>
  <div class="ccm-tablewrap">
    <table class="ccm-table">
      <thead><tr><th>Product</th><th>SKU</th><th class="num">Amazon price</th><th>Price status</th><th class="num">Actions</th></tr></thead>
      <tbody id="az-rows"><tr><td colspan="5" class="ccm-empty">Loading products…</td></tr></tbody>
    </table>
    <div class="ccm-listfoot"><span id="az-foot"></span><span class="ccm-pager" id="az-pager"></span></div>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
