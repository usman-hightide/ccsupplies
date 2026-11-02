<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="stores">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">Stores</h1><p class="ccm-sub">Each store’s monthly order quota, budget and the employee who orders for it.</p></div>
    <div class="ccm-actions"><button type="button" class="ccm-btn" data-ccm-export>Export</button><a class="ccm-btn primary" href="<?php echo esc_url( home_url( '/manager/stores/create' ) ); ?>">+ Add store</a></div>
  </div>
  <div class="ccm-minis">
    <div class="ccm-card"><div class="ccm-mini-v" id="s-total">–</div><div class="ccm-mini-l">Total stores</div></div>
    <div class="ccm-card"><div class="ccm-mini-v" id="s-ordered">–</div><div class="ccm-mini-l">Ordered this month</div></div>
    <div class="ccm-card"><div class="ccm-mini-v" id="s-issues">–</div><div class="ccm-mini-l">Stores with data issues</div></div>
    <div class="ccm-card"><div class="ccm-mini-v" id="s-provs">–</div><div class="ccm-mini-l">Provinces</div></div>
  </div>
  <div class="ccm-tablewrap">
    <div class="ccm-toolbar">
      <label class="ccm-field grow">Search
        <input class="ccm-input" type="search" placeholder="Store name, code, city or user…" id="s-q">
      </label>
      <label class="ccm-field fixed">Province
        <select class="ccm-select" id="s-prov">
          <option value="all">All provinces</option>
        </select>
      </label>
      <label class="ccm-field fixed">Show
        <select class="ccm-select" id="s-filter">
          <option value="all">All stores</option>
          <option value="issues">With data issues</option>
        </select>
      </label>
    </div>
    <table class="ccm-table">
      <thead><tr><th>Store</th><th>Location</th><th class="num">Quota / mo</th><th class="num">Budget / mo</th><th style="min-width:160px">Used this month</th><th>Assigned user</th><th>Issues</th><th class="num">Actions</th></tr></thead>
      <tbody id="s-rows"><tr><td colspan="8" class="ccm-empty">Loading stores…</td></tr></tbody>
    </table>
    <div class="ccm-empty" id="s-empty" hidden><strong>No stores found</strong>Try another name, code or city.</div>
    <div class="ccm-listfoot"><span id="s-foot"></span><span>Page 1</span></div>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
