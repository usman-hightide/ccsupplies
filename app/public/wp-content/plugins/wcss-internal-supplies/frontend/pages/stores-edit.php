<?php if ( ! defined('ABSPATH') ) exit; ?>


<div class="wcssm-wrap">
<?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>
  <div class="wcssm-header">
    <h1>Edit Store</h1>
    <a class="btn btn-light" href="<?php echo esc_url( home_url('/manager/stores') ); ?>">← Back</a>
  </div>

  <div id="s-msg" class="flash" style="display:none"></div>

  <div class="panel form-panel">
    <div class="grid-2">
      <div>
        <label>Store Name *</label>
        <input id="s-name" class="input" type="text" required>
      </div>
      <div>
        <label>Store Code *</label>
        <input id="s-code" class="input" type="text" required>
      </div>
    </div>

    <div class="grid-2">
      <div>
        <label>City</label>
        <input id="s-city" class="input" type="text">
      </div>
      <div>
        <label>State/Province</label>
        <input id="s-state" class="input" type="text">
      </div>
    </div>

    <div>
      <label>Address *</label>
      <textarea id="s-address" class="input" rows="3" required></textarea>
    </div>

    <div class="grid-2">
      <div>
        <label>Phone Number *</label>
        <input id="s-phone" class="input" type="tel" required>
      </div>
      <div>
        <label>Open Hours *</label>
        <input id="s-hours" class="input" placeholder="e.g. Mon–Fri 9AM–6PM" required>
      </div>
    </div>

    <div class="grid-2">
      <div>
        <label>Monthly Quota</label>
        <input id="s-quota" class="input" type="number" min="0">
      </div>
      <div>
        <label>Monthly Budget</label>
        <input id="s-budget" class="input" type="number" step="0.01" min="0">
      </div>
    </div>

    <div>
      <label>Assign Store Employee *</label>
      <select id="s-user" class="input" required></select>
    </div>

    <div class="actions">
      <button class="btn btn-primary" id="s-save">Save Changes</button>
      <a href="/manager/stores" class="btn btn-light">Cancel</a>
    </div>
  </div>
</div>


</div>

<script>
jQuery(function($){
  if (window.WCSSM && WCSSM.view==='stores' && WCSSM.action==='edit' && WCSSM.id) {
    if (typeof window.initStoreEdit === 'function') window.initStoreEdit(WCSSM.id);
  }
});
</script>