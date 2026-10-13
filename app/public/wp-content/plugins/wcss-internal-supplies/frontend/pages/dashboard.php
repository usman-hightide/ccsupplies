<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap wcssm-dashboard">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="wcssm-header">
    <h1>Dashboard</h1>
    <div id="wcssm-dashboard" class="wcssm-header-actions">
      <button id="dash-refresh" class="btn btn-light" type="button">Refresh</button>
      <button id="dash-export-csv" class="btn btn-light" type="button">Export CSV</button>
      <button id="dash-export-pdf" class="btn btn-light" type="button">Export PDF</button>
    </div>
  </div>

  <div id="wcssm-flash" class="flash" style="display:none"></div>

  <div class="cards cards-4" id="dash-cards">
    <div class="card card-pending">
      <div class="card-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
      </div>
      <div class="card-body">
        <h3>Pending</h3>
        <div class="num" id="m-pending">–</div>
      </div>
    </div>
    <div class="card card-approved">
      <div class="card-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/></svg>
      </div>
      <div class="card-body">
        <h3>Approved</h3>
        <div class="num" id="m-approved">–</div>
      </div>
    </div>
    <div class="card card-rejected">
      <div class="card-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg>
      </div>
      <div class="card-body">
        <h3>Rejected</h3>
        <div class="num" id="m-rejected">–</div>
      </div>
    </div>
    <div class="card card-cost">
      <div class="card-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5c.5-1 1.5-1.5 2.5-1.5s2 .7 2 2-1 2-2.5 2.5-2.5.8-2.5 2.5 1 2 2.5 2 2-.5 2.5-1.5"/></svg>
      </div>
      <div class="card-body">
        <h3>Orders cost (this month)</h3>
        <div class="num" id="m-revenue">–</div>
      </div>
    </div>
  </div>

  <div class="grid-2">
    <div class="panel">
      <h3>
        <span class="panel-icon is-green" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V10l7-5 7 5v11M9 21v-6h6v6"/></svg>
        </span>
        Stores
      </h3>
      <p><strong>Total:</strong> <span id="st-total">–</span></p>
      <p><strong>Active this month:</strong> <span id="st-active">–</span></p>
    </div>

    <div class="panel">
      <h3>
        <span class="panel-icon is-green" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="3"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a3 3 0 0 1 0 5.75"/></svg>
        </span>
        Top Vendors (this month)
      </h3>
      <table class="table" id="v-table">
        <thead><tr><th id="dash-topvendors">Vendor</th><th>Orders</th><th>Revenue</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <h3>
      <span class="panel-icon is-red" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19V5M4 19h16M8 16l3-5 3 3 4-7"/></svg>
      </span>
      Orders Trend (last 6 months)
    </h3>
    <div id="trend" class="bars"></div>
  </div>

  <div class="panel">
    <h3>
      <span class="panel-icon is-green" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>
      </span>
      Store Budgets (active this month)
    </h3>
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
