<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wcssm-wrap ccm-content ccm-pf-page">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>
  <?php $pf_mode = 'edit'; include WCSS_DIR . 'frontend/pages/partials/product-form.php'; ?>
</div>
<script>
jQuery(function ($) {
  if (window.WCSSM && WCSSM.view === 'products' && WCSSM.action === 'edit' && WCSSM.id) {
    if (typeof window.initProductEdit === 'function') window.initProductEdit(WCSSM.id);
  }
});
</script>
