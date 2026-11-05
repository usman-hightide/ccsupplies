<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="storeCreate">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div>
      <a class="ccm-se-back" href="<?php echo esc_url( home_url( '/manager/stores' ) ); ?>">← All stores</a>
      <h1 class="ccm-h1">Add store</h1>
      <p class="ccm-sub">Add the store and its full ship-to address. Checkout locks this address for store employees.</p>
    </div>
  </div>

  <div class="ccm-se">
    <form class="ccm-pol ccm-se-main" id="sc-form" novalidate>
      <section class="ccm-pol-card">
        <header class="ccm-pol-ch">
          <span class="ccm-pol-num" aria-hidden="true">1</span>
          <div>
            <h3>Store details</h3>
            <p>Name and code as they appear in Supply Manager and on orders.</p>
          </div>
        </header>
        <div class="ccm-pol-grid">
          <label class="ccm-pol-field"><span>Store name <em class="ccm-req">*</em></span><input class="ccm-input" id="s-name" type="text" autocomplete="off" required></label>
          <label class="ccm-pol-field"><span>Store code <em class="ccm-req">*</em></span><input class="ccm-input" id="s-code" type="text" autocomplete="off" required></label>
        </div>
      </section>

      <section class="ccm-pol-card">
        <header class="ccm-pol-ch">
          <span class="ccm-pol-num" aria-hidden="true">2</span>
          <div>
            <h3>Ship-to address <em class="ccm-req">*</em></h3>
            <p>Used as the read-only address at checkout. Store employees cannot edit these fields.</p>
          </div>
        </header>
        <div class="ccm-pol-grid">
          <label class="ccm-pol-field"><span>City <em class="ccm-req">*</em></span><input class="ccm-input" id="s-city" type="text" required></label>
          <label class="ccm-pol-field"><span>Province <em class="ccm-req">*</em></span><input class="ccm-input" id="s-state" type="text" list="sc-provs" placeholder="e.g. Ontario" required><datalist id="sc-provs">
            <option value="Alberta"><option value="British Columbia"><option value="Manitoba"><option value="New Brunswick"><option value="Newfoundland and Labrador"><option value="Nova Scotia"><option value="Ontario"><option value="Prince Edward Island"><option value="Quebec"><option value="Saskatchewan"><option value="Northwest Territories"><option value="Nunavut"><option value="Yukon">
          </datalist></label>
        </div>
        <label class="ccm-pol-field"><span>Street address <em class="ccm-req">*</em></span><textarea class="ccm-input ccm-se-ta" id="s-address" rows="2" required placeholder="House number and street"></textarea></label>
        <div class="ccm-pol-grid">
          <label class="ccm-pol-field"><span>Postal code <em class="ccm-req">*</em></span><input class="ccm-input" id="s-postcode" type="text" required placeholder="e.g. T3K 2M4" autocomplete="postal-code"></label>
          <label class="ccm-pol-field"><span>Country</span><input class="ccm-input is-locked" id="s-country" type="text" value="CA" readonly title="Canadian stores ship within Canada"></label>
        </div>
        <div class="ccm-pol-grid">
          <label class="ccm-pol-field"><span>Phone number <em class="ccm-req">*</em></span><input class="ccm-input" id="s-phone" type="tel" required></label>
          <label class="ccm-pol-field"><span>Open hours <em class="ccm-req">*</em></span><input class="ccm-input" id="s-hours" type="text" required placeholder="e.g. Mon–Fri 9 AM–6 PM"></label>
        </div>
      </section>

      <section class="ccm-pol-card">
        <header class="ccm-pol-ch">
          <span class="ccm-pol-num" aria-hidden="true">3</span>
          <div>
            <h3>Store employee <em class="ccm-req">*</em></h3>
            <p>The one person who places orders for this store.</p>
          </div>
        </header>
        <label class="ccm-pol-field"><span>Assigned employee</span><select class="ccm-select" id="s-user" required></select></label>
      </section>

      <section class="ccm-pol-card">
        <header class="ccm-pol-ch">
          <span class="ccm-pol-num" aria-hidden="true">4</span>
          <div>
            <h3>Monthly limits</h3>
            <p>Optional overrides. Leave blank to use the ordering policy defaults after create.</p>
          </div>
        </header>
        <div class="ccm-pol-grid">
          <label class="ccm-pol-field"><span>Monthly budget</span><span class="ccm-pol-money"><i>CA$</i><input class="ccm-input" id="s-budget" type="number" min="0" step="0.01" placeholder="Policy default"></span></label>
          <label class="ccm-pol-field"><span>Orders per month</span><input class="ccm-input" id="s-quota" type="number" min="0" step="1" placeholder="Policy default"></label>
        </div>
      </section>

      <div class="ccm-pol-err" role="alert" id="s-err" hidden></div>
    </form>
  </div>

  <div class="ccm-se-bar is-dirty" id="sc-bar">
    <span class="ccm-se-bar-t" id="s-msg">Fill in the required fields, then create the store</span>
    <a class="ccm-btn ccm-pol-cancel" href="<?php echo esc_url( home_url( '/manager/stores' ) ); ?>">Cancel</a>
    <button type="button" class="ccm-btn ccm-pol-save" id="s-save">Create store</button>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>

<script>
jQuery(function ($) {
  if (window.WCSSM && WCSSM.view === 'stores' && WCSSM.action === 'create') {
    if (typeof window.initStoreCreate === 'function') window.initStoreCreate();
  }
});
</script>
