<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="wcssm-header">
    <h1>Vendors</h1>
    <div class="actions">
      <button id="o-refresh" class="btn">Refresh</button>
      <button id="v-create-vendor" class="btn">+ Create Vendor</button>
    </div>
  </div>

  <div id="wcssm-flash" class="wcssm-flash" style="display:none;"></div>

  <div id="wcssm-vendors-grid"></div>
</div>

<!-- Vendor create model -->
<div id="vendor-modal" class="wcssm-modal" hidden>
  <div class="wcssm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="vendor-modal-title">
    <button type="button" class="wcssm-modal__close" id="vendor-modal-close" aria-label="Close">×</button>
    <h3 id="vendor-modal-title">Create Vendor</h3>

    <div class="grid-2">
      <div>
        <label>Vendor name *</label>
        <input id="v-name" class="input" required>
      </div>
      <div>
        <label>Email</label>
        <input id="v-email" class="input" type="email" placeholder="name@example.com">
      </div>
    </div>

    <div class="grid-2">
      <div>
        <label>Phone</label>
        <input id="v-phone" class="input" placeholder="+1 555 123 4567">
      </div>
      <div>
        <label>Address</label>
        <input id="v-address" class="input" placeholder="Street, City, State">
      </div>
    </div>


    <div class="actions">
      <button type="button" class="btn btn-primary" id="vendor-save">Save vendor</button>
      <span id="vendor-msg" class="muted"></span>
    </div>
  </div>
  <div class="wcssm-modal__backdrop"></div>
</div>


<script>
jQuery(function($){
  if (window.WCSSM && WCSSM.view==='vendorslist' && !WCSSM.action) {
    if (typeof window.initVendorsList === 'function') window.initVendorsList();
  }
});
</script>