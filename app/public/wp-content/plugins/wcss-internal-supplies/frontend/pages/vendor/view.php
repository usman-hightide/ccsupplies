<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="vendors">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">Vendors</h1><p class="ccm-sub">Suppliers that fulfil store orders. Keep contact details complete so orders can be sent.</p></div>
    <div class="ccm-actions"><button type="button" class="ccm-btn primary" id="v-new">+ Add vendor</button></div>
  </div>
  <div class="ccm-grid3" id="v-cards"><div class="ccm-card ccm-empty">Loading vendors…</div></div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
