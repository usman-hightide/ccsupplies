<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="amazon">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">Amazon catalog</h1><p class="ccm-sub">Find items on Amazon and link them to products so stores see the best price.</p></div>
  </div>
  <section class="ccm-card">
    <form class="ccm-toolbar" style="padding:0;border:0" id="az-form">
      <label class="ccm-field grow">Search Amazon
        <input class="ccm-input" type="search" placeholder="Keyword, brand or ASIN — e.g. toner C230H20" id="az-q">
      </label>
      <button type="submit" class="ccm-btn primary">Search</button>
    </form>
    <div style="margin-top:16px" id="az-results" hidden></div>
  </section>
  <section class="ccm-card" style="padding:0">
    <div class="ccm-card-h" style="padding:20px 20px 0"><div><h2 class="ccm-card-t">Products linked to Amazon</h2><p class="ccm-card-s">Compare Amazon’s price with the website price before approving orders</p></div></div>
    <div class="ccm-tablewrap flush">
      <table class="ccm-table">
        <thead><tr><th>Product</th><th>SKU</th><th class="num">Website</th><th class="num">Amazon</th><th class="num">Difference</th></tr></thead>
        <tbody id="az-rows"><tr><td colspan="5" class="ccm-empty">Loading products…</td></tr></tbody>
      </table>
    </div>
    <div class="ccm-listfoot"><span id="az-foot"></span><span id="az-month">Amazon orders this month: –</span></div>
  </section>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
