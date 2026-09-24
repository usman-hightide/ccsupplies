<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>


  <div class="wcssm-header">
  <h1>Dashboard</h1>
  <div id="wcssm-dashboard">
    <button id="dash-refresh" class="btn btn-light">Refresh</button>
    <button id="dash-export-csv" class="btn btn-light">Export CSV</button>
    <button id="dash-export-pdf" class="btn btn-light">Export PDF</button>
  </div>
</div>

  <div id="wcssm-flash" class="flash" style="display:none"></div>

  <div class="cards cards-4" id="dash-cards">
    <div class="card"><h3>Pending</h3><div class="num" id="m-pending">–</div></div>
    <div class="card"><h3>Approved</h3><div class="num" id="m-approved">–</div></div>
    <div class="card"><h3>Rejected</h3><div class="num" id="m-rejected">–</div></div>
    <div class="card"><h3>Orders cost (this month)</h3><div class="num" id="m-revenue">–</div></div>
  </div>

  <div class="grid-2">
    <div class="panel">
      <h3>Stores</h3>
      <p><strong>Total:</strong> <span id="st-total">–</span></p>
      <p><strong>Active this month:</strong> <span id="st-active">–</span></p>
    </div>

    <div class="panel">
      <h3>Top Vendors (this month)</h3>
      <table class="table" id="v-table">
        <thead><tr><th id="dash-topvendors">Vendor</th><th>Orders</th><th>Revenue</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <h3>Orders Trend (last 6 months)</h3>
    <div id="trend" class="bars"></div>
  </div>

  <div class="panel">
    <h3>Store Budgets (active this month)</h3>
    <table class="table" id="ledger-tbl">
      <thead><tr><th>Store Name</th><th>Store ID</th><th>Orders</th><th>Quota</th><th>Spend</th><th>Budget</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>
</div>



<script>
jQuery(function($){
  if (window.WCSSM && WCSSM.view==='dashboard') {
    if (typeof window.initDashboard === 'function') window.initDashboard();
  }
});
</script>