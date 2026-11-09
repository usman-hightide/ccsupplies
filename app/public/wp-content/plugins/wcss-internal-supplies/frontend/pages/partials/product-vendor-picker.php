<?php
/**
 * Vendor picker (product create + edit): one product → many vendors.
 * Vendors are managed only in the Vendors tab; rows are rendered by manager.js
 * (window.wcssVendorPicker) from /products/meta.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="ccm-vpick" id="p-vendor-picker">
  <div class="ccm-card-h ccm-vpick-head">
    <div>
      <h2 class="ccm-card-t">Vendors <span class="ccm-vpick-count" id="p-vendor-count"><b>0</b> selected</span></h2>
      <p class="ccm-card-s">Tick every vendor that sells this product. Stores only see the lowest price.</p>
    </div>
    <a class="ccm-pol-link" href="<?php echo esc_url( home_url( '/manager/vendorslist' ) ); ?>">Manage vendors →</a>
  </div>
  <div class="ccm-vpick-sum" id="p-price-sum" role="status" aria-live="polite" hidden></div>
  <div class="ccm-vpick-list" id="p-vendor-list" role="group" aria-label="Vendors">
    <div class="ccm-pol-empty">Loading vendors…</div>
  </div>
  <div class="ccm-pol-err" id="p-vendor-err" role="alert" hidden></div>
</div>
