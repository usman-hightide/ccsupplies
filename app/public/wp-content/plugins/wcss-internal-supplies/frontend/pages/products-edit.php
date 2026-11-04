<?php if ( ! defined('ABSPATH') ) exit; ?>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <?php wp_head(); ?>   <!-- REQUIRED for wp_localize_script and enqueued assets -->
</head>

<div class="wcssm-wrap">
<?php include WCSS_DIR . 'frontend/pages/partials/manager-nav.php'; ?>

  <div class="wcssm-header">
    <h1>Edit Product</h1>
    <a href="<?php echo esc_url( home_url('/manager/products') ); ?>" class="btn btn-light">← Back to Products</a>
  </div>

  <form id="wcssm-product-form" onsubmit="return false;">
    <div class="grid-2">
      <div>
        <label>Name *</label>
        <input id="p-name" class="input" type="text" required>
      </div>
      <div>
        <label>SKU</label>
        <input id="p-sku" class="input" type="text">
      </div>
    </div>

    <div class="grid-2">
      <div>
        <label>Price *</label>
        <input id="p-price" class="input" type="number" step="0.01" min="0" required>
      </div>
      <div>
        <label>Status</label>
        <select id="p-status" class="input">
          <option value="publish">Publish</option>
          <option value="draft">Draft</option>
          <option value="pending">Pending</option>
        </select>
      </div>
    </div>

    <div>
      <label>Short Description</label>
      <textarea id="p-short" class="input" rows="4"></textarea>
    </div>

    <div class="grid-2">
      <div>
        <label>Categories</label>
        <select id="p-categories" class="input" multiple size="6" style="min-width:280px"></select>
      </div>
      <!-- <div>
        <label>Vendors</label>
        <select id="p-vendors" class="input" multiple size="6" style="min-width:280px"></select>
      </div> -->
      <div>
        <label>Vendors</label>
        <div class="inline-flex gap-8">
          <select id="p-vendors" class="input" multiple size="6" style="min-width:280px"></select>
          <button type="button" class="btn btn-light" id="open-vendor-modal">+ New vendor</button>
        </div>
      </div>

    </div>

    <div>
      <label>Images</label>
      <div class="media-row">
        <button type="button" id="p-pick-images" class="btn">Choose Images</button>
        <input type="hidden" id="p-images-ids" value="">
        <div id="p-images-preview" class="thumbs"></div>
      </div>
      <small>First image is featured; others are gallery.</small>
    </div>

    <fieldset class="boxed">
      <legend>Inventory</legend>
      <div class="grid-3">
        <label class="checkbox">
          <input id="p-manage-stock" type="checkbox"> Manage stock?
        </label>
        <div>
          <label>Stock Qty</label>
          <input id="p-stock" class="input" type="number" step="1" min="0" value="0">
        </div>
        <div>
          <label>Stock Status</label>
          <select id="p-stock-status" class="input">
            <option value="instock">In stock</option>
            <option value="outofstock">Out of stock</option>
            <option value="onbackorder">On backorder</option>
          </select>
        </div>
      </div>
    </fieldset>

    <div class="actions">
      <button id="p-save" class="btn btn-primary" type="button">Save Changes</button>
      <span id="p-msg" class="muted"></span>
    </div>
  </form>
</div>



<div id="vendor-modal" class="wcssm-modal" hidden>
  <div class="wcssm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="vendor-modal-title">
    <button type="button" class="wcssm-modal__close" id="vendor-modal-close" aria-label="Close">×</button>
    <h3 id="vendor-modal-title">Create Vendor</h3>

    <div class="grid-2">
      <div>
        <label>Vendor name *</label>
        <input id="v-name" class="input" required>
      </div>
      <div>
        <label>Email</label>
        <input id="v-email" class="input" type="email" placeholder="name@example.com">
      </div>
    </div>

    <div class="grid-2">
      <div>
        <label>Phone</label>
        <input id="v-phone" class="input" placeholder="+1 555 123 4567" required>
      </div>
      <div>
        <label>Address</label>
        <input id="v-address" class="input" placeholder="Street, City, State">
      </div>
    </div>

    <div class="actions">
      <button type="button" class="btn btn-primary" id="vendor-save">Save vendor</button>
      <span id="vendor-msg" class="muted"></span>
    </div>
  </div>
  <div class="wcssm-modal__backdrop"></div>
</div>



<script>
  
jQuery(function($){
  if (window.WCSSM && WCSSM.view==='products' && WCSSM.action==='edit' && WCSSM.id) {
    if (typeof window.initProductEdit === 'function') window.initProductEdit(WCSSM.id);
  }
});

</script>


