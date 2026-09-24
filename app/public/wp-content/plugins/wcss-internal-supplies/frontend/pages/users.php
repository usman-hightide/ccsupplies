<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="wcssm-header">
    <h1>Users</h1>
    <div>
      <button id="u-refresh" class="btn btn-light">Refresh</button>
      <button id="u-new" class="btn btn-primary">+ Create Store Employee</button>
    </div>
  </div>

  <div id="wcssm-flash" class="flash" style="display:none"></div>

  <div class="panel">
    <div class="row head">
      <div>ID</div>
      <div>Name</div>
      <div>Email</div>
      <div> Assigned Store</div>
      <!-- <div>Actions</div> -->
    </div>
    <div id="wcssm-users-grid"></div>
  </div>
</div>

<!-- Create User Modal -->
<div id="user-modal" class="modal" style="display:none">
  <div class="modal-box">
    <h3>Create Store Employee</h3>
    <div class="grid-2">
      <div><label>First name *</label><input id="um-first" class="input" required></div>
      <div><label>Last name</label><input id="um-last" class="input"></div>
    </div>
    <div><label>Email *</label><input id="um-email" class="input" type="email" required></div>
    <div class="actions">
      <button type="button" class="btn" id="um-cancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="um-save">Create</button>
    </div>
  </div>
</div>

<!-- Assign Store Modal -->
<div id="assign-modal" class="modal" style="display:none">
  <div class="modal-box">
    <h3>Assign Store</h3>
    <p class="muted" id="am-userline"></p>
    <label>Store</label>
    <select id="am-store" class="input"></select>
    <div class="actions">
      <button type="button" class="btn" id="am-cancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="am-save">Save</button>
    </div>
  </div>
</div>

<script>
jQuery(function($){
  if (window.WCSSM && WCSSM.view==='users') {
    if (typeof window.initUsersList === 'function') window.initUsersList();
  }
});
</script>