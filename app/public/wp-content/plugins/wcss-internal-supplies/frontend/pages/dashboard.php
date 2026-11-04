<?php if ( ! defined('ABSPATH') ) exit;
$ccm_user  = wp_get_current_user();
$ccm_first = $ccm_user->first_name ?: strtok( $ccm_user->display_name ?: $ccm_user->user_login, ' ' );
?>
<div class="wcssm-wrap wcssm-dashboard ccm-content" data-ccm-screen="dashboard">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div>
      <h1 class="ccm-h1"><span id="dash-greeting">Hello</span>, <?php echo esc_html( $ccm_first ); ?></h1>
      <p class="ccm-sub">Here’s how ordering is going across your stores. Numbers update as orders come in or are approved.</p>
    </div>
    <div class="ccm-actions">
      <div class="ccm-seg" role="group" aria-label="Reporting period">
        <button type="button" data-period="d7">7 days</button>
        <button type="button" data-period="d30" class="on">30 days</button>
        <button type="button" data-period="month">This month</button>
        <button type="button" data-period="m6">6 months</button>
        <button type="button" data-period="custom">Custom</button>
      </div>
      <div class="ccm-dash-dates" id="dash-dates" hidden>
        <input class="ccm-input" type="date" id="dash-from" aria-label="From date" title="From" autocomplete="off">
        <span class="ccm-dash-dates-sep" aria-hidden="true">–</span>
        <input class="ccm-input" type="date" id="dash-to" aria-label="To date" title="To" autocomplete="off">
        <button type="button" class="ccm-btn primary" id="dash-dates-apply">Apply</button>
      </div>
      <a class="ccm-btn" href="<?php echo esc_url( home_url( '/manager/reports' ) ); ?>">Reports</a>
      <a class="ccm-btn primary" href="<?php echo esc_url( home_url( '/manager/reports/export/?format=xlsx&type=vendors&range=this_month' ) ); ?>" title="This month’s vendor spend report as an Excel file">Export Excel</a>
    </div>
  </div>

  <div class="ccm-alert" role="status" id="dash-alert" hidden>
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8A5300" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
    <div style="flex:1 1 240px"><div class="ccm-alert-t" id="dash-alert-t"></div><div class="ccm-small" style="color:#6B4100">Stores can’t receive supplies until you approve or reject them.</div></div>
    <a class="ccm-btn primary sm" href="<?php echo esc_url( home_url( '/manager/orders?status=awaiting-approval' ) ); ?>">Review now</a>
  </div>

  <div class="ccm-kpis">
    <div class="ccm-card ccm-kpi">
      <div class="ccm-kpi-top"><span class="ccm-kpi-l">Orders placed</span><span class="ccm-kpi-i" style="background:#E8F0FE;color:#1F4FA8"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/></svg></span></div>
      <div class="ccm-kpi-v" id="k-orders">–</div>
      <div class="ccm-kpi-d"><span id="k-orders-delta" class="flat"></span> <span id="k-vs"></span></div>
    </div>
    <div class="ccm-card ccm-kpi">
      <div class="ccm-kpi-top"><span class="ccm-kpi-l">Awaiting your approval</span><span class="ccm-kpi-i" style="background:#FFF4E0;color:#8A5300"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span></div>
      <div class="ccm-kpi-v" id="k-pending">–</div>
      <div class="ccm-kpi-d" id="k-pending-sub"></div>
    </div>
    <div class="ccm-card ccm-kpi">
      <div class="ccm-kpi-top"><span class="ccm-kpi-l">Approved spend</span><span class="ccm-kpi-i" style="background:#E7F5EC;color:#1B6B3A"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 2v20"/><path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span></div>
      <div class="ccm-kpi-v" id="k-spend">–</div>
      <div class="ccm-kpi-d" id="k-spend-sub"></div>
    </div>
    <div class="ccm-card ccm-kpi">
      <div class="ccm-kpi-top"><span class="ccm-kpi-l">Rejected</span><span class="ccm-kpi-i" style="background:#FDECEA;color:#A8261C"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg></span></div>
      <div class="ccm-kpi-v" id="k-rejected">–</div>
      <div class="ccm-kpi-d" id="k-rejected-sub"></div>
    </div>
  </div>

  <section class="ccm-card ccm-vsplit" id="dash-split" aria-live="polite"><div class="ccm-empty">Loading this month’s vendor split…</div></section>

  <div class="ccm-grid2">
    <section class="ccm-card">
      <div class="ccm-card-h">
        <div><h2 class="ccm-card-t">Orders per month</h2><p class="ccm-card-s" id="chart-sub">Last 6 months</p></div>
        <a class="ccm-link" href="<?php echo esc_url( home_url( '/manager/orders' ) ); ?>">See all orders →</a>
      </div>
      <div class="ccm-chart" role="img" id="dash-chart"></div>
      <div class="ccm-xlabels" aria-hidden="true" id="dash-xlabels"></div>
    </section>

    <section class="ccm-card">
      <div class="ccm-card-h"><div><h2 class="ccm-card-t">Store budgets</h2><p class="ccm-card-s">Monthly budget used by each store this month</p></div><a class="ccm-link" href="<?php echo esc_url( home_url( '/manager/stores' ) ); ?>">All stores →</a></div>
      <div class="ccm-minis" style="margin-bottom:12px">
        <div><div class="ccm-mini-v" id="st-total">–</div><div class="ccm-mini-l">Stores</div></div>
        <div><div class="ccm-mini-v" id="st-active">–</div><div class="ccm-mini-l">Ordered this month</div></div>
        <div><div class="ccm-mini-v" id="st-budget">–</div><div class="ccm-mini-l">Budget, listed stores</div></div>
      </div>
      <div id="dash-budgets"></div>
    </section>
  </div>

  <div class="ccm-grid2">
    <section class="ccm-card">
      <div class="ccm-card-h"><div><h2 class="ccm-card-t">Ordering this month</h2><p class="ccm-card-s">Stores that have placed an order, and the ones that haven’t yet</p></div><a class="ccm-link" href="<?php echo esc_url( home_url( '/manager/reports?type=coverage&range=this_month&coverage=not_ordered' ) ); ?>">Full list →</a></div>
      <div id="dash-coverage"><div class="ccm-empty">Loading…</div></div>
    </section>

    <section class="ccm-card">
      <div class="ccm-card-h"><div><h2 class="ccm-card-t">Live activity</h2><p class="ccm-card-s">Every order, approval and catalog change, newest first</p></div></div>
      <div id="dash-feed"></div>
    </section>
  </div>

  <?php $wcss_is_admin = current_user_can( 'manage_options' ); ?>
  <div class="<?php echo $wcss_is_admin ? 'ccm-grid2' : ''; ?>">
    <section class="ccm-card" style="padding:0">
      <div class="ccm-card-h" style="padding:20px 20px 0"><div><h2 class="ccm-card-t">Recent orders</h2><p class="ccm-card-s">Approve or reject without leaving the dashboard</p></div><a class="ccm-link" href="<?php echo esc_url( home_url( '/manager/orders' ) ); ?>">Open orders →</a></div>
      <div class="ccm-tablewrap flush">
        <table class="ccm-table">
          <thead><tr><th>Order</th><th>Requested by</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
          <tbody id="dash-recent"></tbody>
        </table>
      </div>
    </section>

    <?php if ( $wcss_is_admin ) : ?>
    <section class="ccm-card" id="dash-attention-card">
      <div class="ccm-card-h"><div><h2 class="ccm-card-t">Needs your attention</h2><p class="ccm-card-s">Issues that block orders or make reports wrong</p></div></div>
      <div id="dash-attention"></div>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>

<script>
jQuery(function($){
  if (window.WCSSM && WCSSM.view==='dashboard') {
    if (typeof window.initDashboard === 'function') window.initDashboard();
  }
});
</script>
