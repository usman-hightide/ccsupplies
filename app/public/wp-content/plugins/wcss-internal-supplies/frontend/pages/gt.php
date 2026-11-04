<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="gt">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">Grand &amp; Toy</h1><p class="ccm-sub">Products supplied by Grand &amp; Toy and whether their prices are up to date.</p></div>
    <div class="ccm-actions"><button type="button" class="ccm-btn primary" id="gt-sync">Sync prices now</button></div>
  </div>
  <div class="ccm-minis">
    <div class="ccm-card"><div class="ccm-mini-v" id="gt-count">–</div><div class="ccm-mini-l">Grand &amp; Toy products</div></div>
    <div class="ccm-card"><div class="ccm-mini-v" id="gt-synced">–</div><div class="ccm-mini-l">Prices synced</div></div>
    <div class="ccm-card"><div class="ccm-mini-v" id="gt-last">–</div><div class="ccm-mini-l">Last price sync</div></div>
  </div>
  <div class="ccm-tablewrap">
    <table class="ccm-table">
      <thead><tr><th>Product</th><th>SKU</th><th class="num">Website price</th><th class="num">Grand &amp; Toy price</th><th>Price status</th><th class="num">Actions</th></tr></thead>
      <tbody id="gt-rows"><tr><td colspan="6" class="ccm-empty">Loading products…</td></tr></tbody>
    </table>
    <div class="ccm-listfoot"><span id="gt-foot"></span><span class="ccm-pager" id="gt-pager"></span></div>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
