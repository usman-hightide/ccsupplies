<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="users">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">Users</h1><p class="ccm-sub">Store employees who can place orders. Each user orders for one store.</p></div>
    <div class="ccm-actions"><button type="button" class="ccm-btn primary" id="u-new">+ Add store employee</button></div>
  </div>
  <div class="ccm-tablewrap">
    <div class="ccm-toolbar">
      <label class="ccm-field grow">Search
        <input class="ccm-input" type="search" placeholder="Name, email or store…" id="u-q">
      </label>
    </div>
    <table class="ccm-table">
      <thead><tr><th>Employee</th><th>Email</th><th>Assigned store</th><th>Role</th><th>Status</th><th class="num">Actions</th></tr></thead>
      <tbody id="u-rows"><tr><td colspan="6" class="ccm-empty">Loading users…</td></tr></tbody>
    </table>
    <div class="ccm-empty" id="u-empty" hidden><strong>No users found</strong>Try another name, email or store.</div>
    <div class="ccm-listfoot"><span id="u-foot"></span><span>Page 1</span></div>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
