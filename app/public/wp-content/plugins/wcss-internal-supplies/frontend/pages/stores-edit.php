<?php if ( ! defined('ABSPATH') ) exit;
$store_id = absint( get_query_var( 'wcss_id' ) );
?>
<div class="wcssm-wrap ccm-content" data-ccm-screen="storeEdit" data-id="<?php echo (int) $store_id; ?>">
  <?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="ccm-phead">
    <div>
      <a class="ccm-se-back" href="<?php echo esc_url( home_url( '/manager/stores' ) ); ?>">← All stores</a>
      <h1 class="ccm-h1" id="se-title">Edit store</h1>
      <p class="ccm-sub" id="se-sub">Loading store…</p>
    </div>
  </div>

  <div class="ccm-se">
    <form class="ccm-pol ccm-se-main" id="se-form" novalidate>
      <section class="ccm-pol-card">
        <header class="ccm-pol-ch"><span class="ccm-pol-num" aria-hidden="true">1</span><div><h3>Store details</h3><p>Name and code as they appear in Supply Manager and on orders.</p></div></header>
        <div class="ccm-pol-grid">
          <label class="ccm-pol-field"><span>Store name <em class="ccm-req">*</em></span><input class="ccm-input" id="se-name" type="text" autocomplete="off"></label>
          <label class="ccm-pol-field"><span>Store code <em class="ccm-req">*</em></span><input class="ccm-input" id="se-code" type="text" autocomplete="off"></label>
        </div>
      </section>

      <section class="ccm-pol-card">
        <header class="ccm-pol-ch"><span class="ccm-pol-num" aria-hidden="true">2</span><div><h3>Ship-to address <em class="ccm-req">*</em></h3><p>Fills checkout automatically. Store employees cannot change these fields.</p></div></header>
        <div class="ccm-pol-grid">
          <label class="ccm-pol-field"><span>City <em class="ccm-req">*</em></span><input class="ccm-input" id="se-city" type="text" required></label>
          <label class="ccm-pol-field"><span>Province <em class="ccm-req">*</em></span><input class="ccm-input" id="se-state" type="text" list="se-provs" placeholder="e.g. Ontario" required><datalist id="se-provs"></datalist></label>
        </div>
        <label class="ccm-pol-field"><span>Street address <em class="ccm-req">*</em></span><textarea class="ccm-input ccm-se-ta" id="se-address" rows="2" required placeholder="House number and street"></textarea></label>
        <div class="ccm-pol-grid">
          <label class="ccm-pol-field"><span>Postal code <em class="ccm-req">*</em></span><input class="ccm-input" id="se-postcode" type="text" required placeholder="e.g. T3K 2M4" autocomplete="postal-code"></label>
          <label class="ccm-pol-field"><span>Country</span><input class="ccm-input is-locked" id="se-country" type="text" value="CA" readonly title="Canadian stores ship within Canada"></label>
        </div>
        <div class="ccm-pol-grid">
          <label class="ccm-pol-field"><span>Phone number <em class="ccm-req">*</em></span><input class="ccm-input" id="se-phone" type="tel" required></label>
          <label class="ccm-pol-field"><span>Open hours <em class="ccm-req">*</em></span><input class="ccm-input" id="se-hours" type="text" placeholder="e.g. Mon–Fri 9 AM–6 PM" required></label>
        </div>
      </section>

      <section class="ccm-pol-card">
        <header class="ccm-pol-ch"><span class="ccm-pol-num" aria-hidden="true">3</span><div><h3>Store employee <em class="ccm-req">*</em></h3><p>The one person who places orders for this store.</p></div></header>
        <label class="ccm-pol-field"><span>Assigned employee</span><select class="ccm-select" id="se-user"></select></label>
      </section>

      <section class="ccm-pol-card">
        <header class="ccm-pol-ch"><span class="ccm-pol-num" aria-hidden="true">4</span><div><h3>Monthly limits</h3><p>How much this store can spend and how many orders it can place each month.</p></div></header>
        <label class="ccm-pol-switch" for="se-limits-default"><input type="checkbox" id="se-limits-default"><span class="ccm-pol-track" aria-hidden="true"></span>
          <span class="ccm-pol-switch-t"><b>Use the default limits</b><small id="se-limits-default-h">Follow the ordering policy.</small></span></label>
        <div class="ccm-pol-grid" id="se-limits">
          <label class="ccm-pol-field"><span>Monthly budget</span><span class="ccm-pol-money"><i>CA$</i><input class="ccm-input" type="number" min="0" step="0.01" id="se-budget"></span></label>
          <label class="ccm-pol-field"><span>Orders per month</span><input class="ccm-input" type="number" min="0" step="1" id="se-quota"></label>
        </div>
      </section>

      <section class="ccm-pol-card">
        <header class="ccm-pol-ch"><span class="ccm-pol-num" aria-hidden="true">5</span><div><h3>Ordering window</h3><p>The dates this store can order without approval.</p></div></header>
        <label class="ccm-pol-switch" for="se-win-default"><input type="checkbox" id="se-win-default"><span class="ccm-pol-track" aria-hidden="true"></span>
          <span class="ccm-pol-switch-t"><b>Use the default window</b><small id="se-win-default-h">Follow the ordering policy.</small></span></label>
        <div id="se-win" class="ccm-se-win">
          <div class="ccm-pol-grid">
            <label class="ccm-pol-field"><span>Opens on</span><input class="ccm-input" type="date" id="se-from"></label>
            <label class="ccm-pol-field"><span>Closes on</span><input class="ccm-input" type="date" id="se-to"></label>
          </div>
          <label class="ccm-pol-switch" for="se-repeat"><input type="checkbox" id="se-repeat"><span class="ccm-pol-track" aria-hidden="true"></span>
            <span class="ccm-pol-switch-t"><b>Repeat every month</b><small>Use the same days each month — no need to update the dates.</small></span></label>
        </div>
        <div class="ccm-pol-sum" id="se-win-sum" role="status"></div>
      </section>

      <section class="ccm-pol-card">
        <header class="ccm-pol-ch"><span class="ccm-pol-num" aria-hidden="true">6</span><div><h3>If an order breaks a rule</h3><p>Over budget, over the order limit, or outside the ordering window.</p></div></header>
        <div class="ccm-pol-choices ccm-se-choices" role="radiogroup" aria-label="If an order breaks a rule">
          <label class="ccm-pol-opt"><input type="radio" name="se-mode" value=""><span class="ccm-pol-radio" aria-hidden="true"></span>
            <span class="ccm-pol-opt-t"><b>Follow the policy</b><small id="se-mode-default-h">Use the same rule as every other store.</small></span></label>
          <label class="ccm-pol-opt"><input type="radio" name="se-mode" value="review"><span class="ccm-pol-radio" aria-hidden="true"></span>
            <span class="ccm-pol-opt-t"><b>Send for approval</b><small>The store can still order. Supply Ops approves or rejects it before anything goes to the supplier.</small></span></label>
          <label class="ccm-pol-opt"><input type="radio" name="se-mode" value="block"><span class="ccm-pol-radio" aria-hidden="true"></span>
            <span class="ccm-pol-opt-t"><b>Block at checkout</b><small>The store can’t place the order. Checkout shows the reason.</small></span></label>
        </div>
      </section>

      <div class="ccm-pol-err" role="alert" id="se-err" hidden></div>
    </form>

    <aside class="ccm-se-side" aria-label="How this store orders">
      <div class="ccm-se-sum">
        <div class="ccm-se-sum-h"><span>How this store orders</span><small>Updates as you edit</small></div>
        <dl class="ccm-se-dl" id="se-sum"></dl>
        <div class="ccm-se-usage" id="se-usage"></div>
      </div>
    </aside>
  </div>

  <div class="ccm-se-bar" id="se-bar">
    <span class="ccm-se-bar-t" id="se-bar-t">No changes yet</span>
    <a class="ccm-btn ccm-pol-cancel" href="<?php echo esc_url( home_url( '/manager/stores' ) ); ?>">Cancel</a>
    <button type="button" class="ccm-btn ccm-pol-save" id="se-save" disabled>Save changes</button>
  </div>
</div>
<?php include WCSS_DIR . 'frontend/pages/partials/ccm-overlays.php'; ?>
