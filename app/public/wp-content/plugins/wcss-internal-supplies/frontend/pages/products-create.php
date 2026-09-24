<?php if ( ! defined('ABSPATH') ) exit; ?>

<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <?php wp_head(); ?>   <!-- REQUIRED for wp_localize_script and enqueued assets -->
</head>

<div class="wcssm-wrap">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>



  <div class="wcssm-header">
  <h2>Create Product</h2>
    <a href="<?php echo esc_url( home_url('/manager/products') ); ?>" class="btn btn-light">← Back to Products</a>
  </div>


  <?php include WCSS_DIR . 'frontend/pages/partials/product-create-form.php'; ?>
</div>




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
  if (window.WCSSM && WCSSM.view==='products' && WCSSM.action==='create') {
    if (typeof window.initProductCreate === 'function') window.initProductCreate();
  }
});
</script>