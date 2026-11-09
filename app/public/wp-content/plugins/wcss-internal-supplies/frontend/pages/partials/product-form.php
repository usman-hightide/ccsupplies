<?php
/**
 * Product create / edit form (Supply Manager design).
 * Set $pf_mode = 'create' | 'edit' before including. Element IDs are read by manager.js.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
$pf_mode = ( isset( $pf_mode ) && $pf_mode === 'create' ) ? 'create' : 'edit';
$pf_list = home_url( '/manager/products' );
?>
<nav class="ccm-crumbs" aria-label="Breadcrumb">
  <a href="<?php echo esc_url( home_url( '/manager' ) ); ?>">Dashboard</a>
  <span aria-hidden="true">›</span>
  <a href="<?php echo esc_url( $pf_list ); ?>">Products</a>
  <span aria-hidden="true">›</span>
  <span aria-current="page"><?php echo $pf_mode === 'create' ? 'New product' : 'Edit product'; ?></span>
</nav>

<div class="ccm-phead ccm-pf-head">
  <div>
    <h1 class="ccm-h1" id="pf-title"><?php echo $pf_mode === 'create' ? 'New product' : 'Edit product'; ?></h1>
    <p class="ccm-sub" id="pf-sub"><?php echo $pf_mode === 'create' ? 'Add a product to the store catalog and choose who supplies it.' : 'Loading product…'; ?></p>
  </div>
  <div class="ccm-actions">
    <?php if ( $pf_mode === 'edit' ) : ?>
    <button type="button" class="ccm-btn" id="p-refresh-prices" title="Fetch Amazon and Grand &amp; Toy prices for this product now">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
      <span>Refresh prices</span>
    </button>
    <?php endif; ?>
    <a class="ccm-btn" href="<?php echo esc_url( $pf_list ); ?>">Cancel</a>
  </div>
</div>

<form id="wcssm-product-form" class="ccm-pf" onsubmit="return false;" novalidate>
  <div class="ccm-pf-grid">

    <div class="ccm-pf-main">
      <section class="ccm-card">
        <div class="ccm-card-h"><div><h2 class="ccm-card-t">Product details</h2><p class="ccm-card-s">What stores see in the catalog.</p></div></div>
        <div class="ccm-formgrid">
          <label class="ccm-field ccm-pf-full">Name *
            <input id="p-name" class="ccm-input" type="text" required placeholder="e.g. Thermal roll 80mm">
          </label>
          <label class="ccm-field">SKU
            <input id="p-sku" class="ccm-input" type="text" placeholder="Supplier part / SKU">
          </label>
          <label class="ccm-field">Manual vendor price (CA$)
            <input id="p-price" class="ccm-input ccm-pf-num" type="number" step="0.01" min="0" placeholder="Not needed for Amazon / G&amp;T">
          </label>
          <?php if ( $pf_mode === 'create' ) : ?>
          <label class="ccm-field ccm-pf-full">Brand
            <input id="p-brand" class="ccm-input" type="text" placeholder="Optional">
          </label>
          <?php endif; ?>
          <label class="ccm-field ccm-pf-full">Short description
            <textarea id="p-short" class="ccm-textarea" rows="4" placeholder="A short line shown on the product card"></textarea>
          </label>
        </div>
      </section>

      <section class="ccm-card">
        <?php include WCSS_DIR . 'frontend/pages/partials/product-vendor-picker.php'; ?>
      </section>

      <section class="ccm-card">
        <div class="ccm-card-h">
          <div><h2 class="ccm-card-t">Images</h2><p class="ccm-card-s">The first image is the main photo; the rest form the gallery.</p></div>
          <button type="button" id="p-pick-images" class="ccm-btn sm">Choose images</button>
        </div>
        <input type="hidden" id="p-images-ids" value="">
        <div id="p-images-preview" class="ccm-pf-thumbs"></div>
      </section>
    </div>

    <aside class="ccm-pf-side">
      <section class="ccm-card">
        <div class="ccm-card-h"><h2 class="ccm-card-t">Status</h2></div>
        <label class="ccm-field">Visibility
          <select id="p-status" class="ccm-select">
            <option value="publish">Published — visible to stores</option>
            <option value="draft">Draft — hidden</option>
            <option value="pending">Pending review — hidden</option>
          </select>
        </label>
      </section>

      <section class="ccm-card">
        <div class="ccm-card-h"><div><h2 class="ccm-card-t">Categories</h2><p class="ccm-card-s">Products in an Ink category are ordered separately and always need Supply Ops approval.</p></div><span class="ccm-vpick-count" id="p-cat-count"><b>0</b> selected</span></div>
        <div class="ccm-pf-cats" id="p-categories" role="group" aria-label="Categories">
          <div class="ccm-pol-empty">Loading categories…</div>
        </div>
      </section>

      <section class="ccm-card">
        <div class="ccm-card-h"><h2 class="ccm-card-t">Inventory</h2></div>
        <label class="ccm-pf-switch">
          <input id="p-manage-stock" type="checkbox">
          <span><b>Track stock quantity</b><small>Off = always available to order.</small></span>
        </label>
        <div class="ccm-pf-stack">
          <label class="ccm-field">Stock quantity
            <input id="p-stock" class="ccm-input ccm-pf-num" type="number" step="1" min="0" value="0">
          </label>
          <label class="ccm-field">Stock status
            <select id="p-stock-status" class="ccm-select">
              <option value="instock">In stock</option>
              <option value="outofstock">Out of stock</option>
              <option value="onbackorder">On backorder</option>
            </select>
          </label>
        </div>
      </section>
    </aside>
  </div>

  <div class="ccm-pf-bar">
    <span id="p-msg" class="ccm-pf-msg" role="status" aria-live="polite"></span>
    <a class="ccm-btn" href="<?php echo esc_url( $pf_list ); ?>">Cancel</a>
    <?php if ( $pf_mode === 'create' ) : ?>
      <button id="p-create" class="ccm-btn brand" type="button">Create product</button>
    <?php else : ?>
      <button id="p-save" class="ccm-btn brand" type="button">Save changes</button>
    <?php endif; ?>
  </div>
</form>
