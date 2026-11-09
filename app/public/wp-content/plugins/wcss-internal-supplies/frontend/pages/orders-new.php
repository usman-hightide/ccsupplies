<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="wcssm-wrap wcssm-orders ccm-content" data-ccm-screen="orders-new">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div><h1 class="ccm-h1">New order</h1><p class="ccm-sub">Place an order for any store with any active vendor.</p></div>
    <div class="ccm-actions"><a class="ccm-btn" href="<?php echo esc_url( home_url( '/manager/orders' ) ); ?>">Back to orders</a></div>
  </div>

  <div class="ccm-tablewrap ccm-no-pick">
    <div class="ccm-toolbar">
      <label class="ccm-field grow">Select store
        <select class="ccm-select" id="no-store"><option value="">Loading stores…</option></select>
      </label>
      <label class="ccm-field grow">Select vendor
        <select class="ccm-select" id="no-vendor"><option value="">Loading vendors…</option></select>
      </label>
    </div>
    <p class="ccm-no-warn" id="no-store-warn" hidden>This store has an incomplete address. Complete it in Stores before ordering.</p>
  </div>

  <div class="ccm-no-grid" id="no-work" hidden>
    <section class="ccm-tablewrap ccm-no-catalog" aria-label="Catalog">
      <div class="ccm-toolbar">
        <label class="ccm-field grow">Search catalog
          <input class="ccm-input" type="search" id="no-q" placeholder="Product name or SKU" autocomplete="off">
        </label>
      </div>
      <div id="no-results"></div>
    </section>
    <aside class="ccm-card ccm-no-cart" aria-label="Cart">
      <div class="ccm-card-h"><h2 class="ccm-card-t">Cart</h2><span class="ccm-muted" id="no-count"></span></div>
      <div id="no-lines"></div>
      <dl class="ccm-no-sum">
        <div><dt>Items</dt><dd id="no-items">0</dd></div>
        <div class="is-total"><dt>Subtotal</dt><dd id="no-sub">$0.00</dd></div>
      </dl>
      <p class="ccm-no-note" id="no-note"></p>
      <div class="ccm-no-policy" id="no-policy" hidden></div>
      <button type="button" class="ccm-btn primary ccm-no-go" id="no-go" disabled>Continue to checkout</button>
    </aside>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
