<?php if ( ! defined('ABSPATH') ) exit; ?>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <?php wp_head(); ?>
</head>

<div class="wcssm-wrap">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="wcssm-header">
    <h1>Order Details</h1>
    <a href="<?php echo esc_url( home_url('/manager/orders') ); ?>" class="btn btn-light">← Back to Orders</a>
  </div>

  <div id="order-detail">
    <p>Loading order…</p>
  </div>
</div>








<script>
jQuery(function($){
  if (window.WCSSM && WCSSM.view==='orders' && WCSSM.action==='view' && WCSSM.id) {
    if (typeof window.initOrderView === 'function') window.initOrderView(WCSSM.id);
  }
});
</script>


<style>
/* status pill by slug */
.badge { display:inline-block; padding:.2rem .5rem; border-radius:999px; font-size:.85rem; font-weight:600; }
.badge[data-slug="awaiting-approval"] { background:#fff4e5; color:#8a5800; }
.badge[data-slug="approved"]          { background:#eaf7ef; color:#0f6d2a; }
.badge[data-slug="rejected"]          { background:#fde9ea; color:#8a1020; }

.badge-success { background: #E6F7EC; color: #1E7F3E; }
.badge-danger  { background: #FDECEC; color: #B91C1C; }
.badge-warn    { background: #FEF7E6; color: #92400E; }
.badge-info    { background: #E6F2FE; color: #1D4ED8; }
.badge-muted   { background: #F3F4F6; color: #6B7280; }

/* Shared detail cards */
.ov-card {
  border: 1px solid #ebebef;
  border-radius: 14px;
  padding: 16px 18px;
  background: #fff;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
}
.ov-card > h2,
.ov-card > h3 {
  margin: 0 0 14px;
  font-size: 18px;
  font-weight: 800;
  color: #111;
}
.ov-ledger {
  margin-top: 18px;
}
.ov-ledger .kv { display:flex; justify-content:space-between; margin:.25rem 0; }
/* Compact ledger: 3 columns like Order calculation */
.ov-ledger .ov-dl {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0 24px;
  width: 100%;
}
.ov-ledger .ov-dl__row {
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px 12px;
  align-items: baseline;
  padding: 8px 0;
}
.ov-ledger .ov-dl__row dd {
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
  justify-self: end;
}
@media (max-width: 900px) {
  .ov-ledger .ov-dl {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 600px) {
  .ov-ledger .ov-dl {
    grid-template-columns: 1fr;
  }
}

/* Two-column label/value rows */
.ov-dl {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0;
  margin: 0;
}
.ov-dl__row {
  display: grid;
  grid-template-columns: minmax(120px, 180px) minmax(0, 1fr);
  gap: 12px 18px;
  align-items: start;
  padding: 10px 0;
  border-bottom: 1px solid #f0f0f4;
}
.ov-dl__row:last-child {
  border-bottom: 0;
  padding-bottom: 0;
}
.ov-dl__row:first-child {
  padding-top: 0;
}
.ov-dl__row dt {
  margin: 0;
  font-size: 13px;
  font-weight: 700;
  color: #666;
}
.ov-dl__row dd {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  color: #111;
  word-break: break-word;
}

.order-store-wrap {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  align-items: start;
}
.ov-side-col {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}
.ov-side-col .ov-card {
  margin: 0;
}
.ov-address-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  margin-top: 16px;
}
.ov-addr,
.ov-note {
  margin: 0;
  font-size: 14px;
  line-height: 1.55;
  color: #222;
}
.ov-muted {
  color: #999;
  font-weight: 500;
}
.ov-vendor-badge {
  display: inline-flex;
  align-items: center;
  padding: 3px 8px;
  border-radius: 999px;
  background: #f3eefc;
  color: #4b2f8a;
  font-size: 12px;
  font-weight: 700;
}
.ov-vendor-id {
  display: block;
  margin-top: 4px;
  color: #666;
  font-size: 11px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

@media (max-width: 900px) {
  .order-store-wrap,
  .ov-address-grid {
    grid-template-columns: 1fr;
  }
  .ov-dl__row {
    grid-template-columns: minmax(110px, 140px) minmax(0, 1fr);
  }
}

/* Items table */
.ov-items-card {
  margin-top: 18px;
}
.ov-items-card > h3 {
  margin: 0 0 10px;
  font-size: 18px;
  font-weight: 800;
  color: #111;
}
.ov-table-wrap {
  overflow: hidden;
  border: 1px solid #ebebef;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
}
.ov-items-table {
  width: 100%;
  margin: 0;
  border: 0;
  border-collapse: collapse;
}
.ov-items-table thead th {
  padding: 14px 16px;
  border: 0;
  border-bottom: 1px solid #e4e4ea;
  background: #111;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #fff;
  text-align: left;
}
.ov-items-table tbody td {
  padding: 14px 16px;
  border: 0;
  border-bottom: 1px solid #f0f0f4;
  background: #fff;
  vertical-align: middle;
  font-size: 14px;
  color: #111;
}
.ov-items-table tbody tr:last-child td {
  border-bottom: 0;
}
.ov-items-table tbody tr:hover td {
  background: #fcfcfd;
}
.table .sku {
  font-size: 12px;
  color: #888;
}
.ta-r { text-align: right; }

/* Update status — prominent actions */
.ov-status-card {
  margin-top: 22px;
  padding: 20px 22px;
  border: 1px solid #ebebef;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
}
.ov-status-card > h3 {
  margin: 0 0 6px;
  font-size: 18px;
  font-weight: 800;
  color: #111;
}
.ov-status-hint {
  margin: 0 0 16px;
  max-width: 54ch;
  color: #666;
  font-size: 14px;
  line-height: 1.45;
}
.ov-status-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: stretch;
}
.ov-status-actions .btn-approve,
.ov-status-actions .btn-reject {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 180px;
  min-height: 48px;
  padding: 12px 28px;
  border: 0;
  border-radius: 12px;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: 0.01em;
  cursor: pointer;
  box-shadow: none;
  transition: background-color 0.15s ease, transform 0.12s ease, box-shadow 0.15s ease;
}
.ov-status-actions .btn-approve {
  background: #111;
  color: #fff;
}
.ov-status-actions .btn-approve:hover,
.ov-status-actions .btn-approve:focus {
  background: #1f7a3a;
  color: #fff;
  outline: none;
  box-shadow: 0 0 0 3px rgba(31, 122, 58, 0.22);
}
.ov-status-actions .btn-reject {
  background: #e1251c;
  color: #fff;
}
.ov-status-actions .btn-reject:hover,
.ov-status-actions .btn-reject:focus {
  background: #c41b14;
  color: #fff;
  outline: none;
  box-shadow: 0 0 0 3px rgba(225, 37, 28, 0.22);
}
.ov-status-actions .btn-approve:disabled,
.ov-status-actions .btn-reject:disabled {
  opacity: 0.65;
  cursor: wait;
  transform: none;
  box-shadow: none;
}
#order-msg .flash-success,
#order-msg .flash-error,
#order-msg .flash-info {
  margin-top: 14px;
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
}
#order-msg .flash-success { background: #eaf7ef; color: #0f6d2a; }
#order-msg .flash-error   { background: #fde9ea; color: #8a1020; }
#order-msg .flash-info    { background: #f3f4f6; color: #374151; }
.ov-vendor-fulfill {
  margin-top: 20px;
  padding: 20px 20px 18px;
  border: 1px solid #e6e6e6;
  border-radius: 14px;
  background: linear-gradient(180deg, #fbfbfb 0%, #f5f5f5 100%);
}
.ov-vendor-fulfill__eyebrow {
  margin: 0 0 6px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #1f7a3a;
}
.ov-vendor-fulfill h4 {
  margin: 0 0 8px;
  font-size: 18px;
  font-weight: 700;
  letter-spacing: -0.01em;
  color: #111;
  line-height: 1.25;
}
.ov-vendor-fulfill__lead {
  margin: 0 0 14px;
  max-width: 54rem;
  font-size: 14px;
  line-height: 1.5;
  color: #444;
}
.ov-store-vendor {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 10px;
  margin: 0 0 16px;
  padding: 8px 12px;
  border-radius: 999px;
  background: #fff;
  border: 1px solid #e2e2e2;
  font-size: 13px;
  line-height: 1.3;
}
.ov-store-vendor.is-empty {
  border-style: dashed;
  color: #666;
}
.ov-store-vendor__label {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #777;
}
.ov-store-vendor__value {
  font-weight: 700;
  color: #111;
}
.ov-fulfill-actions {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
  align-items: stretch;
  margin-top: 0;
  max-width: 560px;
}
.ov-fulfill-actions .btn-fulfill {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 4px;
  min-width: 0;
  min-height: 72px;
  padding: 14px 18px;
  border: 1px solid #d8d8d8;
  border-radius: 12px;
  background: #fff;
  color: #111;
  font-size: 14px;
  font-weight: 700;
  line-height: 1.25;
  text-align: left;
  cursor: pointer;
  box-shadow: 0 1px 0 rgba(17, 17, 17, 0.04);
  transition: background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}
.ov-fulfill-actions .btn-fulfill__name {
  font-size: 15px;
  font-weight: 700;
}
.ov-fulfill-actions .btn-fulfill__hint {
  font-size: 12px;
  font-weight: 500;
  color: #666;
}
.ov-fulfill-actions .btn-fulfill.is-preferred {
  background: #111;
  border-color: #111;
  color: #fff;
  box-shadow: 0 8px 18px rgba(17, 17, 17, 0.14);
}
.ov-fulfill-actions .btn-fulfill.is-preferred .btn-fulfill__hint {
  color: rgba(255, 255, 255, 0.78);
}
.ov-fulfill-actions .btn-fulfill:hover,
.ov-fulfill-actions .btn-fulfill:focus {
  background: #111;
  border-color: #111;
  color: #fff;
  outline: none;
  box-shadow: 0 0 0 3px rgba(17, 17, 17, 0.14);
}
.ov-fulfill-actions .btn-fulfill:hover .btn-fulfill__hint,
.ov-fulfill-actions .btn-fulfill:focus .btn-fulfill__hint {
  color: rgba(255, 255, 255, 0.78);
}
#order-msg {
  margin-top: 12px;
}
.ov-vendor-orders {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  margin: 18px 0;
}
.ov-vendor-orders .ov-card h3 {
  margin-bottom: 12px;
}
/* Compact item table inside the three narrow vendor cards: fit the card, wrap long names. */
.ov-vendor-orders .ov-items-table {
  table-layout: fixed;
  width: 100%;
  font-size: 12px;
}
.ov-vendor-orders .ov-items-table th,
.ov-vendor-orders .ov-items-table td {
  padding: 8px 6px;
  white-space: normal;
  overflow-wrap: anywhere;
}
.ov-vendor-orders .ov-items-table th:nth-child(1) { width: 44%; }
.ov-vendor-orders .ov-items-table th:nth-child(2) { width: 26%; }
.ov-vendor-orders .ov-items-table th:nth-child(3) { width: 12%; overflow-wrap: normal; }
.ov-vendor-order.is-empty p,
.ov-auto-submit .ov-muted,
.ov-vendor-detail.is-empty p {
  margin: 0;
  font-size: 14px;
  line-height: 1.5;
}
.ov-calc-card,
.ov-api-response,
.ov-vendor-details-block {
  margin-top: 18px;
}
/* Compact totals: 3 columns, amounts right-aligned */
.ov-calc-card .ov-dl {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0 24px;
  max-width: none;
  width: 100%;
}
.ov-calc-card .ov-dl__row {
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px 12px;
  align-items: baseline;
  padding: 8px 0;
}
.ov-calc-card .ov-dl__row dd {
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
  justify-self: end;
}
.ov-calc-card .ov-table-wrap {
  max-width: none;
  width: 100%;
}
@media (max-width: 900px) {
  .ov-calc-card .ov-dl {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 600px) {
  .ov-calc-card .ov-dl {
    grid-template-columns: 1fr;
  }
}
.ov-section-title {
  margin: 0 0 6px;
  font-size: 18px;
  font-weight: 800;
  color: #111;
}
.ov-vendor-details-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  margin-top: 12px;
}
.ov-api-response {
  padding: 0;
  overflow: hidden;
}
.ov-api-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 20px 14px;
  border-bottom: 1px solid #f0f0f4;
}
.ov-api-head h3 {
  margin: 0 0 4px;
  font-size: 18px;
  font-weight: 800;
  color: #111;
}
.ov-api-lead {
  margin: 0;
  max-width: 56ch;
  font-size: 13px;
  line-height: 1.5;
  color: #667085;
}
.ov-api-head__actions {
  flex-shrink: 0;
  display: flex;
  gap: 8px;
}
.ov-api-btn {
  appearance: none !important;
  -webkit-appearance: none !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  height: 36px !important;
  padding: 0 12px !important;
  border: 1px solid #d0d5dd !important;
  border-radius: 8px !important;
  background: #fff !important;
  color: #344054 !important;
  font-size: 13px !important;
  font-weight: 600 !important;
  line-height: 1 !important;
  text-decoration: none !important;
  box-shadow: none !important;
  opacity: 1 !important;
  transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease !important;
}
.ov-api-btn:hover,
.ov-api-btn:focus {
  background: #f9fafb !important;
  border-color: #98a2b3 !important;
  color: #111 !important;
  opacity: 1 !important;
  outline: none !important;
}
.ov-api-btn:focus-visible {
  box-shadow: 0 0 0 3px rgba(52, 64, 84, 0.16) !important;
}
.ov-api-btn:active {
  background: #f2f4f7 !important;
  border-color: #667085 !important;
}
/* !important display:flex above would otherwise keep [hidden] buttons visible */
.ov-api-btn[hidden],
#ov-copy-api-json[hidden] {
  display: none !important;
}
.ov-api-metrics {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0;
  border-bottom: 1px solid #f0f0f4;
}
.ov-api-metric {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px 20px;
  border-right: 1px solid #f0f0f4;
  min-width: 0;
}
.ov-api-metric:last-child {
  border-right: 0;
}
.ov-api-metric__label {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #98a2b3;
}
.ov-api-metric__value {
  font-size: 15px;
  font-weight: 700;
  color: #111;
  line-height: 1.3;
}
.ov-api-pill {
  display: inline-flex;
  align-items: center;
  width: fit-content;
  max-width: 100%;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.3;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ov-api-pill.is-success {
  background: #eaf7ef;
  color: #0f6d2a;
}
.ov-api-pill.is-warn {
  background: #fff4e5;
  color: #8a5800;
}
.ov-api-pill.is-danger {
  background: #fde9ea;
  color: #8a1020;
}
.ov-api-pill.is-info {
  background: #e6f2fe;
  color: #1d4ed8;
}
.ov-api-pill.is-muted {
  background: #f2f4f7;
  color: #667085;
}
.ov-api-note {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 0;
  padding: 12px 20px;
  background: #fafafa;
  border-bottom: 1px solid #f0f0f4;
  font-size: 13px;
  line-height: 1.45;
  color: #344054;
}
.ov-api-note strong {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #98a2b3;
}
.ov-api-panel {
  padding: 14px 16px 16px;
  background: #fcfcfd;
}
.ov-api-panel__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 36px;
}
.ov-api-toggle {
  appearance: none;
  -webkit-appearance: none;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  height: 36px;
  padding: 0 12px;
  border: 1px solid #d0d5dd;
  border-radius: 8px;
  background: #fff;
  color: #344054;
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  line-height: 1;
  cursor: pointer;
  box-shadow: none;
  opacity: 1;
  transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}
.ov-api-toggle:hover {
  background: #f9fafb;
  border-color: #98a2b3;
  color: #111;
  opacity: 1;
}
.ov-api-toggle:focus {
  outline: none;
}
.ov-api-toggle:focus-visible {
  border-color: #667085;
  box-shadow: 0 0 0 3px rgba(52, 64, 84, 0.16);
}
.ov-api-toggle:active {
  background: #f2f4f7;
  border-color: #667085;
}
.ov-api-toggle[aria-expanded="true"] {
  background: #f2f4f7;
  border-color: #98a2b3;
  color: #111;
}
.ov-api-toggle[aria-expanded="true"]:hover {
  background: #eef0f3;
  border-color: #667085;
}
.ov-api-toggle__chev {
  display: inline-block;
  width: 0;
  height: 0;
  border-left: 5px solid currentColor;
  border-top: 4px solid transparent;
  border-bottom: 4px solid transparent;
  opacity: 0.7;
  transition: transform 0.15s ease;
}
.ov-api-panel.is-open .ov-api-toggle__chev,
.ov-api-toggle[aria-expanded="true"] .ov-api-toggle__chev {
  transform: rotate(90deg);
}
.ov-api-json {
  margin: 10px 0 0;
  padding: 14px 16px;
  max-height: 420px;
  overflow: auto;
  border: 1px solid #1e293b;
  border-radius: 10px;
  background: #0f172a;
  color: #e2e8f0;
  font-size: 12px;
  line-height: 1.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  white-space: pre-wrap;
  word-break: break-word;
}
.ov-api-json.is-collapsed,
.ov-api-json[hidden] {
  display: none !important;
}
@media (max-width: 900px) {
  .ov-api-metrics {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .ov-api-metric:nth-child(2) {
    border-right: 0;
  }
  .ov-api-metric:nth-child(1),
  .ov-api-metric:nth-child(2) {
    border-bottom: 1px solid #f0f0f4;
  }
}
@media (max-width: 600px) {
  .ov-api-head {
    flex-direction: column;
  }
  .ov-api-metrics {
    grid-template-columns: 1fr;
  }
  .ov-api-metric {
    border-right: 0;
    border-bottom: 1px solid #f0f0f4;
  }
  .ov-api-metric:last-child {
    border-bottom: 0;
  }
}
@media (max-width: 1100px) {
  .ov-vendor-orders,
  .ov-vendor-details-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 640px) {
  .ov-status-actions .btn-approve,
  .ov-status-actions .btn-reject {
    width: 100%;
    min-width: 0;
  }
  .ov-vendor-fulfill {
    padding: 16px;
  }
  .ov-fulfill-actions {
    grid-template-columns: 1fr;
    max-width: none;
  }
}
</style>