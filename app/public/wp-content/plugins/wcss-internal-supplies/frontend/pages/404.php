<?php if ( ! defined('ABSPATH') ) exit;
?>
<head>
  <link rel="stylesheet" href="<?php echo WCSS_URL . 'frontend/assets/css/manager.css'; ?>"/>

</head>
<div class="wcssm-wrap">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>
  <div class="wcssm-header" style="text-align:center;"><h1>Not found </h1></div>
  <div class="panel">
    <p>This page doesn’t exist in the manager portal.</p>
    <p><a class="btn" href="<?php echo esc_url( home_url('/manager') ); ?>">← Back to Dashboard</a></p>
  </div>
</div>

