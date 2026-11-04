<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="reports" data-export="<?php echo esc_url( home_url( '/manager/reports/export/' ) ); ?>">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">Reports</h1><p class="ccm-sub">Answer spend questions without Excel: pick a report, filter it, then export.</p></div>
    <div class="ccm-actions">
      <button type="button" class="ccm-btn" id="r-savepreset">Save as preset</button>
      <a class="ccm-btn" id="r-print" href="#" target="_blank" rel="noopener">Print / PDF</a>
      <a class="ccm-btn" id="r-csv" href="#">CSV</a>
      <a class="ccm-btn primary" id="r-xlsx" href="#">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
        Export Excel
      </a>
    </div>
  </div>

  <div class="ccm-presets" aria-label="Presets">
    <span class="ccm-presets-l">Presets</span>
    <div class="ccm-presets-list" id="r-presets"><span class="ccm-muted ccm-small">Loading…</span></div>
  </div>

  <div class="ccm-card ccm-rfilters">
    <div class="ccm-tabs ccm-rtabs" role="tablist" aria-label="Report" id="r-types"></div>
    <div class="ccm-rgrid">
      <label class="ccm-field">Period
        <select class="ccm-select" id="r-range"></select>
      </label>
      <label class="ccm-field" data-show="custom">From
        <input class="ccm-input" type="date" id="r-from">
      </label>
      <label class="ccm-field" data-show="custom">To
        <input class="ccm-input" type="date" id="r-to">
      </label>
      <label class="ccm-field">Status
        <select class="ccm-select" id="r-status">
          <option value="approved">Approved (spend)</option>
          <option value="awaiting">Awaiting approval</option>
          <option value="rejected">Rejected</option>
          <option value="all">All statuses</option>
        </select>
      </label>
      <label class="ccm-field">Vendor
        <select class="ccm-select" id="r-vendor"><option value="">All vendors</option></select>
      </label>
      <label class="ccm-field">Store
        <select class="ccm-select" id="r-store"><option value="">All stores</option></select>
      </label>
      <label class="ccm-field">Province
        <select class="ccm-select" id="r-province"><option value="">All provinces</option></select>
      </label>
      <label class="ccm-field">Product
        <select class="ccm-select" id="r-product"><option value="">All products</option></select>
      </label>
      <label class="ccm-field">User
        <select class="ccm-select" id="r-user"><option value="">All users</option></select>
      </label>
      <label class="ccm-field" data-show="reason">Exception reason
        <select class="ccm-select" id="r-reason"><option value="">Any reason</option></select>
      </label>
      <label class="ccm-field" data-show="coverage">Show
        <select class="ccm-select" id="r-coverage">
          <option value="all">All stores</option>
          <option value="not_ordered">Only stores that didn’t order</option>
          <option value="ordered">Only stores that ordered</option>
        </select>
      </label>
    </div>
    <div class="ccm-rfoot">
      <span class="ccm-muted ccm-small" id="r-period"></span>
      <button type="button" class="ccm-linkbtn" id="r-reset">Reset filters</button>
    </div>
  </div>

  <div class="ccm-kpis ccm-rkpis" id="r-kpis" aria-live="polite"></div>

  <div class="ccm-card ccm-vsplit" id="r-split"></div>

  <div class="ccm-tablewrap ccm-rresult">
    <div class="ccm-rhead">
      <div><h2 class="ccm-card-t" id="r-title">Report</h2><p class="ccm-card-s" id="r-desc"></p></div>
      <div class="ccm-chips" id="r-chips"></div>
    </div>
    <div class="ccm-rchart" id="r-chart" hidden></div>
    <div class="ccm-rtable">
      <table class="ccm-table" id="r-table">
        <thead><tr id="r-thead"></tr></thead>
        <tbody id="r-rows"><tr><td class="ccm-empty">Loading report…</td></tr></tbody>
        <tfoot id="r-tfoot"></tfoot>
      </table>
    </div>
    <div class="ccm-listfoot"><span id="r-count"></span><span class="ccm-pager" id="r-pager"></span><span class="ccm-muted ccm-small">Spend = approved orders, item totals before tax and shipping. Click a column to sort.</span></div>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
