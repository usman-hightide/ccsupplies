<?php if ( ! defined('ABSPATH') ) exit; ?>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <?php wp_head(); ?>   <!-- REQUIRED for wp_localize_script and enqueued assets -->

</head>





<div class="wcssm-wrap">
<?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

<div id="wcssm-flash" class="wcssm-flash" style="display:none;"></div>

  <div class="wcssm-header">
    <h1>Stores</h1>
    <a class="btn btn-primary" href="<?php echo esc_url( home_url('/manager/stores/create') ); ?>">+ Create Store</a>
  </div>

  <div class="toolbar">
    <input id="s-search" class="input" placeholder="Search stores…">
    <button id="s-refresh" class="btn" type="button">Search</button>
  </div>

  <div id="wcssm-stores-grid">Loading…</div>
</div>

<script>
jQuery(function($){
  if (window.WCSSM && WCSSM.view==='stores' && !WCSSM.action) {
    if (typeof window.initStoresList === 'function') window.initStoresList();
  }
});
</script>