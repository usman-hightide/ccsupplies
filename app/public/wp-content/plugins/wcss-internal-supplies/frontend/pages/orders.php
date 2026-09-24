<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="wcssm-header">
    <h1>Orders</h1>
    <div class="actions">
      <button id="o-refresh" class="btn">Refresh</button>
    </div>
  </div>

  <div class="filters-bar">
    <div class="filters">
      <label>
        Status
        <select id="o-status" class="input">
          <option value="">All</option>
          <option value="awaiting-approval">Awaiting Approval</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="pending">Pending</option>
          <option value="processing">Processing</option>
          <option value="completed">Completed</option>
          <option value="on-hold">On hold</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </label>

      <label>
        From
        <input type="date" id="o-from" class="input">
      </label>

      <label>
        To
        <input type="date" id="o-to" class="input">
      </label>

      <button id="o-apply" class="btn btn-primary">Apply</button>
      <button id="o-clear" class="btn btn-light">Clear</button>
    </div>
  </div>

  <div id="wcssm-flash" class="wcssm-flash" style="display:none;"></div>

  <div id="wcssm-orders-grid"></div>
</div>

<script>
jQuery(function($){
  if (window.WCSSM && WCSSM.view==='orders' && !WCSSM.action) {
    if (typeof window.initOrdersList === 'function') window.initOrdersList();
  }
});
</script>