<?php if ( ! defined('ABSPATH') ) exit;
$order_id = absint( get_query_var( 'wcss_id' ) );
?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="orderView" data-id="<?php echo (int) $order_id; ?>">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <nav class="ccm-crumbs" aria-label="Breadcrumb">
    <a href="<?php echo esc_url( home_url( '/manager' ) ); ?>">Dashboard</a>
    <span aria-hidden="true">›</span>
    <a href="<?php echo esc_url( home_url( '/manager/orders' ) ); ?>">Orders</a>
    <span aria-hidden="true">›</span>
    <span aria-current="page">Order #<?php echo (int) $order_id; ?></span>
  </nav>

  <div class="ccm-phead ccm-od-head">
    <div>
      <h1 class="ccm-h1"><span>Order #<?php echo (int) $order_id; ?></span> <span id="od-status"></span></h1>
      <p class="ccm-sub" id="od-sub">Loading order…</p>
    </div>
  </div>

  <section class="ccm-od-action" id="od-action" hidden></section>

  <div class="ccm-od">
    <div class="ccm-od-main">
      <section class="ccm-card ccm-od-card">
        <div class="ccm-card-h"><h2 class="ccm-card-t">Items</h2><span class="ccm-count" id="od-count"></span></div>
        <div class="ccm-od-items" id="od-items"><div class="ccm-empty">Loading…</div></div>
        <dl class="ccm-od-totals" id="od-totals"></dl>
      </section>

      <section class="ccm-card ccm-od-card">
        <div class="ccm-card-h"><h2 class="ccm-card-t">History</h2></div>
        <div class="ccm-tl" id="od-history"></div>
      </section>

      <section class="ccm-card ccm-od-card ccm-od-tech" id="od-tech" hidden></section>
    </div>

    <aside class="ccm-od-side">
      <section class="ccm-card ccm-od-card">
        <div class="ccm-card-h"><h2 class="ccm-card-t">Store</h2></div>
        <dl class="ccm-od-dl" id="od-store"></dl>
        <div class="ccm-od-addr" id="od-addr"></div>
      </section>
      <section class="ccm-card ccm-od-card">
        <div class="ccm-card-h"><h2 class="ccm-card-t">This store’s month</h2><span class="ccm-count" id="od-month"></span></div>
        <div id="od-usage"></div>
      </section>
    </aside>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
