<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<!-- ============ ORDER DRAWER ============ -->
<div id="ccm-drawer" hidden>
  <button type="button" class="ccm-overlay" aria-label="Close order details" data-ccm-close></button>
  <aside class="ccm-drawer" role="dialog" aria-modal="true" aria-label="Order details">
    <div class="ccm-dhead">
      <div><div class="ccm-muted ccm-small">Order</div><div style="font-size:22px;font-weight:700" id="dr-id"></div><div style="margin-top:8px"><span id="dr-status"></span></div></div>
      <button type="button" class="ccm-btn icon" aria-label="Close" data-ccm-close><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </div>
    <div class="ccm-dbody">
      <div id="dr-policy" hidden></div>
      <dl class="ccm-dl">
        <dt>Placed</dt><dd id="dr-placed"></dd>
        <dt>Requested by</dt><dd id="dr-customer"></dd>
        <dt>Order total</dt><dd style="font-size:18px;font-weight:700" id="dr-total"></dd>
        <dt>Expected delivery</dt><dd id="dr-edd"></dd>
        <dt>Vendor ref.</dt><dd id="dr-ref"></dd>
      </dl>
      <div class="ccm-field">Vendor
        <div class="ccm-select ccm-readonly" id="dr-vendor"></div>
      </div>
      <div>
        <div class="ccm-card-t" style="margin-bottom:12px">History</div>
        <div class="ccm-tl" id="dr-history"></div>
      </div>
      <a class="ccm-btn ccm-btn--block" id="dr-full" href="#">Open Full Order Detail Page
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
      </a>
    </div>
    <div class="ccm-dfoot" id="dr-foot"></div>
  </aside>
</div>

<!-- ============ CREATE / EDIT MODAL ============ -->
<div id="ccm-modal" hidden>
  <button type="button" class="ccm-overlay" aria-label="Cancel" data-ccm-close></button>
  <form class="ccm-modal" role="dialog" aria-modal="true" aria-labelledby="md-title" novalidate>
    <div><h2 class="ccm-h1" style="font-size:20px" id="md-title"></h2><p class="ccm-sub" id="md-sub"></p></div>
    <div class="ccm-formgrid" id="md-fields"></div>
    <div class="ccm-err" role="alert" id="md-err" hidden></div>
    <div class="ccm-actions" style="justify-content:flex-end"><button type="button" class="ccm-btn" data-ccm-close>Cancel</button><button type="submit" class="ccm-btn primary" id="md-save"></button></div>
  </form>
</div>

<!-- ============ CONFIRM ============ -->
<div id="ccm-confirm" hidden>
  <button type="button" class="ccm-overlay" aria-label="Cancel" data-ccm-close></button>
  <div class="ccm-modal" role="alertdialog" aria-modal="true" aria-labelledby="cf-title">
    <div><h2 class="ccm-h1" style="font-size:20px" id="cf-title"></h2><p class="ccm-sub" id="cf-sub"></p></div>
    <div class="ccm-actions" style="justify-content:flex-end"><button type="button" class="ccm-btn" data-ccm-close>Cancel</button><button type="button" class="ccm-btn brand" id="cf-ok">Delete</button></div>
  </div>
</div>

<!-- ============ INFO (wide, read-only content) ============ -->
<div id="ccm-info" hidden>
  <button type="button" class="ccm-overlay" aria-label="Close" data-ccm-close></button>
  <div class="ccm-modal ccm-modal--wide" role="dialog" aria-modal="true" aria-labelledby="if-title">
    <div class="ccm-info-head">
      <div><h2 class="ccm-h1" style="font-size:20px" id="if-title"></h2><p class="ccm-sub" id="if-sub"></p></div>
      <button type="button" class="ccm-btn icon" aria-label="Close" data-ccm-close><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </div>
    <div class="ccm-info-body" id="if-body"></div>
    <div class="ccm-actions" style="justify-content:flex-end" id="if-foot"></div>
  </div>
</div>

<div class="ccm-toast" role="status" id="ccm-toast" hidden><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#7FD49B" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg><span></span></div>
