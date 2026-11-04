<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="card">

  <div class="grid2">
    <div class="row">
      <label>Name</label>
      <input id="p-name" class="input" placeholder="Product name">
    </div>

    <div class="row">
      <label>SKU</label>
      <input id="p-sku" class="input" placeholder="SKU">
    </div>

    <div class="row">
      <label>Category</label>
      <select id="p-categories" class="input" multiple size="6"></select>
      <small class="ksub">Hold Ctrl/Cmd to select multiple.</small>
    </div>

    <div class="row">
      <label>Brand</label>
      <input id="p-brand" class="input" placeholder="Brand (free text)">
    </div>

    <!-- <div class="row">
      <label>Vendor</label>
      <input id="p-vendor" class="input" placeholder="Vendor (free text)">
    </div> -->

    <div class="row">
    <div>
      <label>Vendors</label>
      <div class="inline-flex gap-8">
        <select id="p-vendors" class="input" multiple size="6" style="min-width:280px"></select>
        <button type="button" class="btn btn-light" id="open-vendor-modal">+ New vendor</button>
      </div>
    </div>
      <small class="ksub">Hold Ctrl/Cmd to select multiple.</small>
    </div>


    <div class="row">
      <label>Product Images</label>
      <div class="image-picker">
        <button class="btn" id="p-pick-images" type="button">Select images</button>
        <div id="p-images-preview" class="thumbs"></div>
        <input type="hidden" id="p-images-ids" value="">
      </div>
    </div>

    <div class="row">
      <label>Inventory</label>
      <div class="grid2">
        <label><input type="checkbox" id="p-manage-stock"> Manage stock</label>
        <input id="p-stock" type="number" class="input" placeholder="Stock qty" disabled>
      </div>
      <select id="p-stock-status" class="input">
        <option value="instock">In stock</option>
        <option value="outofstock">Out of stock</option>
        <option value="onbackorder">On backorder</option>
      </select>
    </div>

    <div class="row">
      <label>Price</label>
      <input id="p-price" type="number" step="0.01" class="input" placeholder="0.00">
    </div>

    <div class="row" style="grid-column: 1 / -1;">
      <label>Short Description</label>
      <textarea id="p-short" class="input" rows="4" placeholder="Short description…"></textarea>
    </div>

    <div class="row">
      <label>Status</label>
      <select id="p-status" class="input">
        <option value="publish">Publish</option>
        <option value="draft">Draft</option>
        <option value="pending">Pending</option>
      </select>
    </div>
  </div>

  <!-- Create page will add its own "Create" button; Edit page adds "Save" -->
  <div id="create-actions" style="margin-top:8px; display:none">
    <button id="p-create" class="btn">Create</button>
    <div id="p-msg" class="ksub" style="margin-top:8px"></div>
  </div>
</div>