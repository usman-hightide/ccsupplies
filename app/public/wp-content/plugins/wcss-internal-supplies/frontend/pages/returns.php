<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="returns">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">Returns</h1><p class="ccm-sub">Return requests from stores. Approve with instructions for the store, arrange the return with the supplier, then close it.</p></div>
  </div>
  <div class="ccm-tablewrap">
    <div class="ccm-tabs" style="padding:0 12px" role="tablist" aria-label="Filter by status" id="rt-tabs"></div>
    <div class="ccm-toolbar">
      <label class="ccm-field grow">Search
        <input class="ccm-input" type="search" placeholder="Return, order #, store or item…" id="rt-q">
      </label>
    </div>
    <table class="ccm-table">
      <thead><tr><th>Return</th><th>Order</th><th>Store</th><th>Items</th><th>Reason</th><th>Requested</th><th>Status</th><th class="num">Actions</th></tr></thead>
      <tbody id="rt-rows"><tr><td colspan="8" class="ccm-empty">Loading return requests…</td></tr></tbody>
    </table>
    <div class="ccm-empty" id="rt-empty" hidden><strong>No return requests here</strong>Stores request returns from their order in My Account.</div>
    <div class="ccm-listfoot"><span id="rt-foot"></span><span class="ccm-pager" id="rt-pager"></span></div>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
