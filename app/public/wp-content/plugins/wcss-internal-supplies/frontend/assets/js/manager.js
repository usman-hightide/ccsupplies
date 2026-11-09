//  starting product CRUD JS
(function ($) {
  // one media frame per page to avoid duplicate handlers
  var _mediaFrame = null;

  function managerBase() {
    return (window.WCSSM && (WCSSM.home || WCSSM.manager_base)) || "/manager/";
  }

  /* ---------- Vendor picker: one product → many vendors (checkbox list) ---------- */
  var VP = (window.wcssVendorPicker = (function () {
    var vendors = [];
    var prices = { amazon: null, gt: null };
    var rows = {};      // live price rows from the server: { amazon: {status, price, error, ago}, gt: {...} }
    var cheapest = null; // { price, vendor } — what stores see
    var LIVE = {
      amazon: { dot: "is-amz", idLabel: "Amazon ASIN", idName: "amazon_asin", ph: "e.g. B074RFMST7" },
      gt: { dot: "is-gt", idLabel: "Grand & Toy part ID", idName: "gt_part_id", ph: "Leave blank to use the SKU" },
    };

    function txt(s) { return $("<textarea>").html(s == null ? "" : String(s)).text(); }
    function esc(s) {
      return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
      });
    }
    function $list() { return $("#p-vendor-list"); }
    function money(n) { return "$" + Number(n).toFixed(2); }

    function sub(v) {
      if (!v.key) return { t: "Manual vendor", tone: "" };
      var r = rows[v.key];
      if (r && r.status === "ok" && r.price != null) return { t: money(r.price) + " · checked " + (r.ago || "recently"), tone: "ok" };
      if (r && r.excluded) return { t: "Not priced — tick to include", tone: "" };
      if (r && r.status === "missing_id") return { t: "No ID yet — add it below and save", tone: "warn" };
      if (r && r.status === "not_found") return { t: "Not found at this vendor" + (r.ago ? " · " + r.ago : ""), tone: "warn" };
      if (r && (r.status === "error" || r.status === "unavailable")) {
        // Vendor API errors are for the dev team; Supply Managers get a plain status.
        if (window.WCSSM && WCSSM.is_admin) return { t: "Couldn’t fetch price" + (r.error ? " — " + r.error : ""), tone: "err" };
        return { t: "Price unavailable right now. We’ll try again automatically.", tone: "warn" };
      }
      var p = prices[v.key];
      return p != null ? { t: "Last price " + money(p), tone: "ok" } : { t: "Not checked yet — save or refresh prices", tone: "" };
    }

    function summary() {
      var $s = $("#p-price-sum");
      if (!$s.length) return;
      if (!cheapest || cheapest.price == null) { $s.prop("hidden", true).empty(); return; }
      var who = cheapest.vendor === "amazon" ? "Amazon" : cheapest.vendor === "gt" ? "Grand & Toy" : "the manual vendor";
      $s.html("Stores see <b>" + money(cheapest.price) + "</b> <span>— lowest price, from " + esc(who) + "</span>").prop("hidden", false);
    }

    /** Refresh price lines only (checkbox state untouched). */
    function setPrices(p) {
      rows = p.vendor_prices || {};
      cheapest = p.cheapest || null;
      prices = { amazon: p.amazon_price != null ? +p.amazon_price : null, gt: p.gt_price != null ? +p.gt_price : null };
      $list().find(".ccm-vpick-row").each(function () {
        var v = { key: $(this).data("key") || "" };
        var s2 = sub(v);
        $(this).find(".ccm-vpick-sub").attr("class", "ccm-vpick-sub" + (s2.tone ? " is-" + s2.tone : "")).text(s2.t);
        $(this).toggleClass("is-winner", !!(cheapest && v.key && cheapest.vendor === v.key));
      });
      summary();
    }

    function rowHtml(v, checked) {
      var live = LIVE[v.key];
      var h =
        "<div class='ccm-vpick-row" + (live ? " is-live" : "") + "' data-id='" + v.id + "' data-key='" + esc(v.key || "") + "'>" +
        "<label class='ccm-vpick-opt'>" +
        "<input type='checkbox' value='" + v.id + "'" + (checked ? " checked" : "") + ">" +
        "<span class='ccm-vdot " + (live ? live.dot : "is-oth") + "' aria-hidden='true'></span>" +
        "<span class='ccm-pol-store-n'><b>" + esc(txt(v.name)) + "<em class='ccm-vpick-win'>Lowest</em></b><small class='ccm-vpick-sub'>" + esc(sub(v).t) + "</small></span>" +
        "<span class='ccm-vpick-tag" + (live ? " is-live" : "") + "'>" + (live ? "Live price" : "Manual") + "</span>" +
        "</label>";
      if (live) {
        h +=
          "<div class='ccm-vpick-id'" + (checked ? "" : " hidden") + ">" +
          "<label for='p-vid-" + v.key + "'>" + esc(live.idLabel) + "</label>" +
          "<input class='input' type='text' id='p-vid-" + v.key + "' data-vid='" + live.idName + "' placeholder='" + esc(live.ph) + "' autocomplete='off'>" +
          "</div>";
      }
      return h + "</div>";
    }

    function sorted(list) {
      var rank = { amazon: 0, gt: 1 };
      return list.slice().sort(function (a, b) {
        var ra = a.key in rank ? rank[a.key] : 2, rb = b.key in rank ? rank[b.key] : 2;
        return ra - rb || txt(a.name).localeCompare(txt(b.name));
      });
    }

    function count() {
      var n = $list().find("input[type=checkbox]:checked").length;
      $("#p-vendor-count").html("<b>" + n + "</b> selected");
    }
    function err(msg) { $("#p-vendor-err").text(msg || "").prop("hidden", !msg); }

    function render(list) {
      vendors = (list || []).map(function (v) { return { id: +v.id, name: v.name, key: v.key || "" }; });
      var keep = ids();
      if (!vendors.length) {
        $list().html("<div class='ccm-pol-empty'><b>No vendors yet</b>Add one in the Vendors tab.</div>");
      } else {
        $list().html(sorted(vendors).map(function (v) { return rowHtml(v, keep.indexOf(v.id) > -1); }).join(""));
      }
      count();
    }

    function ids() {
      return $list().find("input[type=checkbox]:checked").map(function () { return +this.value; }).get();
    }

    /** Prefill from the product DTO. */
    function set(p) {
      var on = (p.assigned_vendor_ids || p.vendor_id || []).map(Number);
      render(vendors);
      $list().find("input[type=checkbox]").each(function () {
        this.checked = on.indexOf(+this.value) > -1;
        $(this).closest(".ccm-vpick-row").find(".ccm-vpick-id").prop("hidden", !this.checked);
      });
      $("#p-vid-amazon").val(p.amazon_asin || "");
      $("#p-vid-gt").val(p.gt_part_id || "");
      setPrices(p);
      count();
    }

    /** Add a just-created vendor and tick it. */
    function add(v) {
      if (!v || !v.id) return;
      if (!vendors.some(function (x) { return x.id === +v.id; })) {
        vendors.push({ id: +v.id, name: v.name, key: v.key || "" });
      }
      var on = ids().concat([+v.id]);
      var vals = { amazon: $("#p-vid-amazon").val(), gt: $("#p-vid-gt").val() };
      render(vendors);
      $list().find("input[type=checkbox]").each(function () {
        this.checked = on.indexOf(+this.value) > -1;
        $(this).closest(".ccm-vpick-row").find(".ccm-vpick-id").prop("hidden", !this.checked);
      });
      $("#p-vid-amazon").val(vals.amazon || "");
      $("#p-vid-gt").val(vals.gt || "");
      count();
    }

    /** Fields for the save payload. */
    function payload() {
      var out = { vendor_ids: ids() };
      if ($("#p-vid-amazon").length) out.amazon_asin = $.trim($("#p-vid-amazon").val() || "");
      if ($("#p-vid-gt").length) out.gt_part_id = $.trim($("#p-vid-gt").val() || "");
      return out;
    }

    /** Every product must ship from a vendor; live vendors need an ID, manual vendors a price. */
    function validate(sku, price) {
      if (!$list().length) return true;
      if (!ids().length) { err("Select at least one vendor. Every product must be supplied by a vendor."); return false; }
      var $amz = $list().find(".ccm-vpick-row[data-key=amazon] input[type=checkbox]");
      if ($amz.is(":checked") && !$.trim($("#p-vid-amazon").val() || "")) {
        err("Amazon is ticked — enter the Amazon ASIN so the price can be fetched.");
        $("#p-vid-amazon").trigger("focus");
        return false;
      }
      var $gt = $list().find(".ccm-vpick-row[data-key=gt] input[type=checkbox]");
      if ($gt.is(":checked") && !$.trim($("#p-vid-gt").val() || "") && !$.trim(sku || "")) {
        err("Grand & Toy is ticked — enter the G&T part ID (or a SKU) so the price can be fetched.");
        $("#p-vid-gt").trigger("focus");
        return false;
      }
      var manual = $list().find(".ccm-vpick-row:not(.is-live) input[type=checkbox]:checked").length > 0;
      if (manual && !(parseFloat(price) > 0)) {
        err("A manual vendor is ticked — enter its price, because it has no live price feed.");
        $("#p-price").trigger("focus");
        return false;
      }
      err("");
      return true;
    }

    $(document).on("change", "#p-vendor-list input[type=checkbox]", function () {
      var $id = $(this).closest(".ccm-vpick-row").find(".ccm-vpick-id");
      $id.prop("hidden", !this.checked);
      if (this.checked) $id.find("input").trigger("focus");
      err("");
      count();
    });

    return { render: render, set: set, setPrices: setPrices, add: add, ids: ids, payload: payload, validate: validate };
  })());

  /* Helpers */

  /** One plain line about the price fetch that just ran. */
  function priceSyncText(p) {
    const sync = p.price_sync || {};
    if (!sync.ran) return "Prices will update on the next hourly sync.";
    const rows = p.vendor_prices || {};
    const parts = [];
    [["amazon", "Amazon"], ["gt", "Grand & Toy"]].forEach(function (k) {
      const r = rows[k[0]];
      if (!r || r.excluded) return; // vendor not ticked for this product
      parts.push(k[1] + ": " + (r.status === "ok" && r.price != null ? "$" + Number(r.price).toFixed(2) : "price unavailable"));
    });
    const c = p.cheapest && p.cheapest.price != null ? " Stores now see $" + Number(p.cheapest.price).toFixed(2) + "." : "";
    return (parts.length ? "Prices updated — " + parts.join(" · ") + "." : "Prices checked.") + c;
  }

  function thumbTile(url) {
    return "<div class='ccm-pf-thumb'><img src='" + String(url || "").replace(/'/g, "%27") + "' alt=''></div>";
  }
  function emptyThumbs() {
    const $p = $("#p-images-preview");
    $p.find(".ccm-pf-thumbs-empty").remove();
    if (!$p.children(".ccm-pf-thumb").length) {
      $p.append("<div class='ccm-pf-thumbs-empty'>No images yet. Use “Choose images” to add one.</div>");
    }
  }

  /** Categories as a checkbox list (parent → child order, children indented). */
  function catCount() {
    $("#p-cat-count").html("<b>" + $("#p-categories input:checked").length + "</b> selected");
  }
  function renderCategories(list) {
    const $box = $("#p-categories");
    if (!$box.length) return;
    const on = $box.find("input:checked").map(function () { return +this.value; }).get();
    const byParent = {};
    list.forEach(function (c) { (byParent[c.parent || 0] = byParent[c.parent || 0] || []).push(c); });
    const name = function (c) { return $("<textarea>").html(c.name).text(); };
    const rows = [];
    (function walk(pid, depth) {
      (byParent[pid] || []).sort(function (a, b) { return name(a).localeCompare(name(b)); }).forEach(function (c) {
        if (name(c).toLowerCase() === "uncategorized") return;
        rows.push(
          "<label class='ccm-pf-cat' style='padding-left:" + (14 + depth * 22) + "px'>" +
          "<input type='checkbox' value='" + c.id + "'" + (on.indexOf(+c.id) > -1 ? " checked" : "") + ">" +
          "<span>" + $("<div>").text(name(c)).html() + "</span>" +
          (c.ink ? "<span class='ccm-vpick-tag is-live'>Ink</span>" : "") + "</label>"
        );
        walk(c.id, depth + 1);
      });
    })(0, 0);
    $box.html(rows.length ? rows.join("") : "<div class='ccm-pol-empty'><b>No categories</b>Create product categories first.</div>");
    catCount();
  }
  $(document).on("change", "#p-categories input[type=checkbox]", catCount);
  $(document).on("input", "#p-name", function () {
    if ($("#p-save").length) $("#pf-title").text($.trim(this.value) || "Edit product");
  });

  function attachMediaPicker() {
    if (typeof wp === "undefined" || !wp.media) return;

    $("#p-pick-images")
      .off("click.wcssm")
      .on("click.wcssm", function (e) {
        e.preventDefault();
        if (!_mediaFrame) {
          _mediaFrame = wp.media({
            title: "Select product images",
            button: { text: "Use these" },
            multiple: true,
            library: { type: "image" },
          });

          _mediaFrame.on("select", function () {
            const sel = _mediaFrame.state().get("selection");
            const ids = [];
            const $prev = $("#p-images-preview").empty();

            sel.each(function (att) {
              const id = att.get("id");
              const url = att.get("sizes")?.thumbnail?.url || att.get("url");
              if (id) ids.push(id);
              if (url) $prev.append(thumbTile(url));
            });

            $("#p-images-ids").val(ids.join(","));
            emptyThumbs();
          });
        }
        _mediaFrame.open();
      });
  }

  function collectPayload() {
    const catIds = $("#p-categories input[type=checkbox]:checked").map(function () { return parseInt(this.value, 10); }).get();

    const imageIds = ($("#p-images-ids").val() || "")
      .toString()
      .split(",")
      .map((v) => parseInt(v, 10) || null)
      .filter(Boolean);

    return $.extend({
      name: $("#p-name").val(),
      sku: $("#p-sku").val(),
      price: $("#p-price").val(),
      short_description: $("#p-short").val(),
      category_ids: catIds,
      brand: $("#p-brand").val(),
      images: imageIds,
      manage_stock: $("#p-manage-stock").is(":checked"),
      stock: parseInt($("#p-stock").val() || 0, 10),
      stock_status: $("#p-stock-status").val(),
      status: $("#p-status").val(),
    }, VP.payload()); // vendor_ids + amazon_asin + gt_part_id
  }
  function bindNewVendorButton() {
    $("#p-add-vendor")
      .off("click.wcssm")
      .on("click.wcssm", function () {
        const name = window.prompt("New vendor name:");
        if (!name) return;

        $.ajax({
          url: WCSSM.rest + "vendors",
          method: "POST",
          headers: {
            "X-WP-Nonce": WCSSM.nonce,
            "Content-Type": "application/json",
          },
          data: JSON.stringify({ name: name }),
          dataType: "json",
        })
          .done(function (t) {
            // add & tick
            VP.add(t);
          })
          .fail(function (x) {
            alert(
              "Failed to create vendor: " +
                (x.responseJSON?.message || x.statusText)
            );
          });
      });
  }

  function toggleStockInput() {
    $("#p-stock").prop("disabled", !$("#p-manage-stock").is(":checked"));
  }

  function loadProductMeta() {
    return $.ajax({
      url: WCSSM.rest + "products/meta",
      headers: { "X-WP-Nonce": WCSSM.nonce },
      dataType: "json",
    }).then(function (meta) {
      renderCategories(meta.categories || []);

      VP.render(meta.vendors || []);

      return meta;
    });
  }

  //update initiate product list
  window.initProductsList = function () {
    const $grid = $("#wcssm-products-grid");
    const $search = $("#p-search");
    const $vendor = $("#p-vendor");
    const $refresh = $("#p-refresh");

    // let state = { page: 1, per_page: 20, search: "" };
    const urlParams = new URLSearchParams(window.location.search);
    const vendorParam = (urlParams.get("vendor") || "").toLowerCase();
    const allowedVendors = ["amazon", "grand-toy"];
    let state = {
      page:
        parseInt(urlParams.get("page"), 10) > 0
          ? parseInt(urlParams.get("page"), 10)
          : 1,
      per_page: 20,
      total_pages: 1,
      total: 0,
      search: urlParams.get("search") || "",
      vendor: allowedVendors.indexOf(vendorParam) !== -1 ? vendorParam : "",
    };

    if ($search.length && state.search) {
      $search.val(state.search);
    }
    if ($vendor.length) {
      $vendor.val(state.vendor || "");
    }

    function readFiltersFromUi() {
      state.search = $search.length ? $search.val() || "" : state.search;
      if ($vendor.length) {
        const v = ($vendor.val() || "").toLowerCase();
        state.vendor = allowedVendors.indexOf(v) !== -1 ? v : "";
      }
    }

    function render(items, meta) {
      const head = `
      <div class="row head">
        <div>ID</div><div>Name</div><div>SKU</div><div>Vendor</div><div>Amazon</div><div>G&amp;T</div><div>Actions</div>
      </div>`;
      const rows = (items || [])
        .map((p) => {
          const qsParts = [];
          if (state.page && state.page > 1) {
            qsParts.push("page=" + encodeURIComponent(state.page));
          }
          if (state.search) {
            qsParts.push("search=" + encodeURIComponent(state.search));
          }
          if (state.vendor) {
            qsParts.push("vendor=" + encodeURIComponent(state.vendor));
          }
          const editUrl =
            p.id + (qsParts.length ? "?" + qsParts.join("&") : "");

          const amazonHtml = p.amazon_price_html || "—";
          const gtHtml = p.gt_price_html || "—";

          return `
      <div class="row">
        <div>#${p.id}</div>
        <div>${(p.name || "").replace(/</g, "&lt;")}</div>
        <div>${(p.sku || "").replace(/</g, "&lt;")}</div>
        <div>${(p.vendor && p.vendor.name ? p.vendor.name : "").replace(
          /</g,
          "&lt;"
        )}</div>
        <div class="price-cell">${amazonHtml}</div>
        <div class="price-cell">${gtHtml}</div>
        <div class="actions">
          <a class="btn btn-sm" href="edit/${editUrl}">Edit</a>
          <button class="btn btn-sm btn-danger delete-product" data-id="${
            p.id
          }">Delete</button>
        </div>
      </div>`;
        })
        .join("");

      const pager = `
        <div class="pager-bar">
          <button type="button" class="btn pager-prev" ${
            state.page <= 1 ? "disabled" : ""
          }>← Prev</button>
          <span class="pager-info">Page ${state.page} / ${
        state.total_pages
      } · ${state.total} items</span>
          <button type="button" class="btn pager-next" ${
            state.page >= state.total_pages ? "disabled" : ""
          }>Next →</button>
        </div>`;

      $grid.html(`${head}${rows || "<p>No products.</p>"}${pager}`);
      console.log($grid);
      const preBtn = $grid.find(".pager-prev");
      console.log(preBtn);

      // pager events
      $grid.find(".pager-prev").on("click", function (e) {
        e.preventDefault();
        if (state.page > 1) {
          state.page--;
          syncQueryWithState();
          load();
        }

        // console.log("btn clicked ....  ");
        // if (state.page > 1) {
        //   state.page--;
        //   load();
        // }
      });

      $grid.find(".pager-next").on("click", function (e) {
        e.preventDefault();
        if (state.page < state.total_pages) {
          state.page++;
          syncQueryWithState();
          load();
        }

        // console.log("btn clicked ....  ");
        // if (state.page < meta.total_pages) {
        //   state.page++;
        //   load();
        // }
      });

      // product export button module..

      // inside window.initProductsList, after state/search handlers:
      $("#p-export")
        .off("click")
        .on("click", function (e) {
          e.preventDefault();

          const exportPayload = { search: state.search || "" };
          if (state.vendor) {
            exportPayload.vendor = state.vendor;
          }
          const qs = $.param(exportPayload);

          fetch((WCSSM.rest || "/wp-json/wcss/v1/") + "products/export?" + qs, {
            headers: { "X-WP-Nonce": WCSSM.nonce },
          })
            .then((r) => {
              if (!r.ok) throw new Error("Export failed");
              return r.blob();
            })
            .then((blob) => {
              const url = URL.createObjectURL(blob);
              const a = document.createElement("a");
              a.href = url;
              a.download =
                "products-" +
                new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-") +
                ".csv";
              document.body.appendChild(a);
              a.click();
              setTimeout(() => {
                URL.revokeObjectURL(url);
                a.remove();
              }, 500);
            })
            .catch((err) => {
              alert(err.message || "Export failed");
            });
        });

      // product export button module..

      function flash(msg, ok = true) {
        const $f = $("#wcssm-flash");
        $f.removeClass("is-ok is-err")
          .addClass(ok ? "is-ok" : "is-err")
          .text(msg)
          .stop(true, true)
          .fadeIn(120);
        setTimeout(() => $f.fadeOut(180), 2500);
      }

      // --- replace your existing delete handler with this ---
      $grid.on("click", ".delete-product", function () {
        const id = parseInt($(this).data("id"), 10);
        if (!Number.isInteger(id)) return;

        if (!confirm("Delete product #" + id + "?")) return;

        $.ajax({
          url: WCSSM.rest + "products/" + id,
          method: "DELETE",
          headers: { "X-WP-Nonce": WCSSM.nonce },
          dataType: "json", // ensure .done() path on 200 JSON
        })
          .done(function () {
            flash("Product deleted.", true);
            load(); // reload list
          })
          .fail(function (x) {
            const msg =
              x.responseJSON && x.responseJSON.message
                ? x.responseJSON.message
                : x.statusText || "Unknown error";
            flash("Delete failed: " + msg, false);
          });
      });
    }

    function load() {
      $grid.html("Loading…");
      const qsPayload = {
        page: state.page,
        per_page: state.per_page,
        search: state.search,
      };
      if (state.vendor) {
        qsPayload.vendor = state.vendor;
      }
      const qs = $.param(qsPayload);
      $.ajax({
        url: WCSSM.rest + "products?" + qs,
        headers: { "X-WP-Nonce": WCSSM.nonce },
      })
        .done(function (d) {
          // update state with server meta
          state.total = d.total || 0;
          state.total_pages = d.total_pages || 1;
          state.page = d.page || state.page;

          render(d.items || [], state); // pass state if you like, but render uses state anyway
        })
        .fail(function (x) {
          $grid.html("Error: " + (x.responseJSON?.message || x.statusText));
        });
    }

    function syncQueryWithState() {
      const params = new URLSearchParams(window.location.search);

      if (state.page && state.page > 1) {
        params.set("page", String(state.page));
      } else {
        params.delete("page");
      }

      if (state.search) {
        params.set("search", state.search);
      } else {
        params.delete("search");
      }

      if (state.vendor) {
        params.set("vendor", state.vendor);
      } else {
        params.delete("vendor");
      }

      const qs = params.toString();
      const newUrl = window.location.pathname + (qs ? "?" + qs : "");

      window.history.replaceState({}, "", newUrl);
    }

    // search + vendor filter hooks
    if ($refresh.length)
      $refresh.on("click", function () {
        state.page = 1;
        readFiltersFromUi();
        syncQueryWithState();
        load();
      });

    if ($search.length)
      $search.on("keypress", function (e) {
        if (e.which === 13) {
          state.page = 1;
          readFiltersFromUi();
          syncQueryWithState();
          load();
        }
      });

    if ($vendor.length)
      $vendor.on("change", function () {
        state.page = 1;
        readFiltersFromUi();
        syncQueryWithState();
        load();
      });

    load();
  };

  // create product form js
  window.initProductCreate = function () {
    $("#create-actions").show();

    $("#p-manage-stock")
      .off("change.wcssm")
      .on("change.wcssm", toggleStockInput);
    toggleStockInput();

    attachMediaPicker();
    bindNewVendorButton();
    emptyThumbs();

    // ensure meta is loaded before enabling save
    loadProductMeta().then(function () {
      let busy = false;

      $("#p-create")
        .off("click.wcssm")
        .on("click.wcssm", function () {
          if (busy) return;
          const $msg = $("#p-msg");
          const payload = collectPayload();

          if (!payload.name) {
            $msg.text("Please enter product name.");
            return;
          }
          if (!VP.validate(payload.sku, payload.price)) {
            $msg.text("Please fix the vendors.");
            return;
          }

          busy = true;
          $msg.removeClass("ok err").text("Creating product and fetching vendor prices…");
          $("#p-create").prop("disabled", true);

          $.ajax({
            url: WCSSM.rest + "products",
            method: "POST",
            headers: {
              "X-WP-Nonce": WCSSM.nonce,
              "Content-Type": "application/json",
            },
            data: JSON.stringify(payload),
            dataType: "json",
          })
            .done(function (p) {
              $msg.addClass("ok").text("Created #" + p.id + " — " + (p.name || "") + ". Opening it…");
              // Open the new product so the fetched vendor prices are visible.
              setTimeout(function () {
                window.location.href = managerBase().replace(/\/+$/, "/") + "products/edit/" + p.id;
              }, 600);
            })
            .fail(function (x) {
              $("#p-msg").text(
                "Error: " + (x.responseJSON?.message || x.statusText)
              );
            })
            .always(function () {
              busy = false;
              $("#p-create").prop("disabled", false);
            });
        });
    });
  };

  /* Edit product form  page */
  window.initProductEdit = function (id) {
    const $msg = $("#p-msg");

    function toast(t, ok = true) {
      $msg
        .removeClass("ok err")
        .addClass(ok ? "ok" : "err")
        .text(t)
        .show();
    }

    function fetchProduct() {
      return $.ajax({
        url: WCSSM.rest + "products/" + id,
        headers: { "X-WP-Nonce": WCSSM.nonce },
        dataType: "json",
      });
    }

    function prefillForm(p) {
      $("#p-name").val(p.name || "");
      $("#p-sku").val(p.sku || "");
      $("#p-price").val(p.price || "");
      $("#p-short").val(p.short_description || "");
      $("#p-status").val(p.status || "publish");

      $("#p-manage-stock").prop("checked", !!p.manage_stock);
      $("#p-stock").val(p.stock || 0);
      $("#p-stock-status").val(p.stock_status || "instock");
      toggleStockInput();

      // Categories
      if (Array.isArray(p.category_ids)) {
        $("#p-categories input[type=checkbox]").each(function () {
          this.checked = p.category_ids.includes(parseInt(this.value, 10));
        });
        catCount();
      }

      // Page header
      $("#pf-title").text(p.name || "Edit product");
      $("#pf-sub").text("Product #" + p.id + (p.sku ? " · SKU " + p.sku : ""));

      // Vendors (checkbox list, one product → many vendors)
      VP.set(p);

      // Images: set hidden field + render thumbs via wp.media attachment fetch
      const ids = Array.isArray(p.images)
        ? p.images.map(Number).filter(Boolean)
        : [];
      $("#p-images-ids").val(ids.join(","));
      const $prev = $("#p-images-preview").empty();

      if (window.wp && wp.media && ids.length) {
        ids.forEach(function (attId) {
          // Placeholder first so the featured image stays first.
          const $tile = $(thumbTile("")).appendTo($prev);
          const att = wp.media.attachment(attId);
          att.fetch().then(function () {
            const url =
              att.get("sizes")?.thumbnail?.url || att.get("url") || null;
            if (url) $tile.find("img").attr("src", url);
            else $tile.remove();
          });
        });
      }
      emptyThumbs();
    }

    // Load meta first, then product, to avoid race
    $.when(loadProductMeta(), fetchProduct())
      .done(function (_meta, prodRes) {
        // $.when returns arrays; prodRes[0] is the payload
        const p = Array.isArray(prodRes) ? prodRes[0] : prodRes;
        prefillForm(p);
      })
      .fail(function (x) {
        toast(
          "Failed to load product: " +
            (x.responseJSON?.message || x.statusText),
          false
        );
      });

    // Save
    $("#p-save")
      .off("click.wcssm")
      .on("click.wcssm", function (e) {
        e.preventDefault();
        const payload = collectPayload();
        if (!VP.validate(payload.sku, payload.price)) {
          toast("Please fix the vendors.", false);
          return;
        }
        toast("Saving and fetching vendor prices…", true);
        const $btn = $("#p-save").prop("disabled", true).text("Saving…");

        $.ajax({
          url: WCSSM.rest + "products/" + id,
          method: "PUT",
          headers: {
            "X-WP-Nonce": WCSSM.nonce,
            "Content-Type": "application/json",
          },
          data: JSON.stringify(payload),
          dataType: "json",
        })
          .done(function (p) {
            VP.set(p);
            $("#pf-title").text(p.name || "Edit product");
            $("#pf-sub").text("Product #" + p.id + (p.sku ? " · SKU " + p.sku : ""));
            toast("Saved. " + priceSyncText(p), true);
          })
          .fail(function (x) {
            toast("Error: " + (x.responseJSON?.message || x.statusText), false);
          })
          .always(function () {
            $btn.prop("disabled", false).text("Save changes");
          });
      });

    // Refresh prices (fetch Amazon / G&T now, no other changes)
    $("#p-refresh-prices")
      .off("click.wcssm")
      .on("click.wcssm", function () {
        const $b = $(this).prop("disabled", true).addClass("is-busy");
        $b.find("span").text("Fetching prices…");
        toast("Fetching Amazon and Grand & Toy prices…", true);
        $.ajax({
          url: WCSSM.rest + "products/" + id + "/refresh-prices",
          method: "POST",
          headers: { "X-WP-Nonce": WCSSM.nonce },
          dataType: "json",
        })
          .done(function (p) {
            VP.setPrices(p);
            toast(priceSyncText(p), true);
          })
          .fail(function (x) {
            toast("Couldn’t refresh prices: " + (x.responseJSON?.message || x.statusText), false);
          })
          .always(function () {
            $b.prop("disabled", false).removeClass("is-busy").find("span").text("Refresh prices");
          });
      });

    // Media picker + vendor quick-add
    attachMediaPicker();
    bindNewVendorButton();

    // Stock toggle
    $("#p-manage-stock")
      .off("change.wcssm")
      .on("change.wcssm", toggleStockInput);
  };
})(jQuery);

/* create new vendor popup */

(function ($) {
  function vendorToast(msg, ok) {
    $("#vendor-msg")
      .removeClass("ok err")
      .addClass(ok ? "ok" : "err")
      .text(msg || "")
      .show();
  }

  function openVendorModal() {
    $("#vendor-msg").text("");
    $("#v-name, #v-email, #v-phone, #v-address").val("");
    $("#vendor-modal").prop("hidden", false);
    $("#v-name").trigger("focus");
  }
  function closeVendorModal() {
    $("#vendor-modal").prop("hidden", true);
  }

  // Modal open/close bindings
  $(document).on("click", "#open-vendor-modal", openVendorModal);
  $(document).on(
    "click",
    "#vendor-modal-close, .wcssm-modal__backdrop",
    closeVendorModal
  );

  // === Save vendor ===
  $(document).on("click", "#vendor-save", function () {
    const name = ($("#v-name").val() || "").trim();
    const email = ($("#v-email").val() || "").trim();
    const phone = ($("#v-phone").val() || "").trim();
    const address = ($("#v-address").val() || "").trim();

    // Basic regex patterns
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const phoneRegex = /^[0-9+\-\s]{7,15}$/;

    // === Validations ===
    if (!name) {
      vendorToast("Vendor name is required", false);
      $("#v-name").focus();
      return;
    }
    // if (!email) {
    //   vendorToast("Email is required", false);
    //   $("#v-email").focus();
    //   return;
    // }
    // if (!emailRegex.test(email)) {
    //   vendorToast("Please enter a valid email address", false);
    //   $("#v-email").focus();
    //   return;
    // }
    if (!phone) {
      vendorToast("Phone number is required", false);
      $("#v-phone").focus();
      return;
    }
    if (!phoneRegex.test(phone)) {
      vendorToast("Please enter a valid phone number", false);
      $("#v-phone").focus();
      return;
    }

    // Save
    vendorToast("Saving…", true);

    $.ajax({
      url: (WCSSM.rest || "/wp-json/wcss/v1/") + "vendors/create",
      method: "POST",
      headers: {
        "X-WP-Nonce": WCSSM.nonce,
        "Content-Type": "application/json",
      },
      data: JSON.stringify({
        name: name,
        email: email,
        phone: phone,
        address: address,
      }),
      dataType: "json",
    })
      .done(function (v) {
        if (!v || !v.id) {
          vendorToast("Unexpected response from server.", false);
          return;
        }

        if (window.wcssVendorPicker) window.wcssVendorPicker.add(v);

        vendorToast("Vendor created successfully!", true);
        var msg = "Vendor created successfully!";
        const $f = $("#wcssm-flash");
        $f.removeClass("is-ok is-err")
          .addClass("is-ok")
          .text(msg)
          .stop(true, true)
          .fadeIn(120);

        // auto-hide after 2.5s
        setTimeout(() => $f.fadeOut(180), 2500);
        $("#o-refresh").trigger("click"); 
        // flash("Vendor created successfully!", true);
        setTimeout(closeVendorModal, 600);
      })
      .fail(function (x) {
        vendorToast(
          x.responseJSON && x.responseJSON.message
            ? x.responseJSON.message
            : "Failed to create vendor",
          false
        );
      });
  });
  // loadVendors()
})(jQuery);

/* store curd below */

(function ($) {
  window.initStoresList = function () {
    const $grid = $("#wcssm-stores-grid");
    const $search = $("#s-search"),
      $refresh = $("#s-refresh");
    let state = { page: 1, per_page: 20, total_pages: 1, total: 0, search: "" };

    function escapeHtml(s) {
      return (s || "").replace(
        /[&<>"]/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c])
      );
    }

    function render(items) {
      const head = `
      <div class="row head">
        <div>ID</div><div>Name</div><div>Code</div><div>City</div><div>State</div><div>Quota</div><div>Budget</div><div>User</div><div>Actions</div>
      </div>`;
      const rows = (items || [])
        .map(
          (s) => `
      <div class="row">
        <div>#${s.id}</div>
        <div>${escapeHtml(s.name || "")}</div>
        <div>${escapeHtml(s.code || "")}</div>
        <div>${escapeHtml(s.city || "")}</div>
        <div>${escapeHtml(s.state || "")}</div>
        <div>${s.quota ?? 0}</div>
        <div>${(s.budget ?? 0).toLocaleString()}</div>
        <div>${s.user_name}</div>
        <div>
          <a class="btn btn-sm" href="edit/${s.id}">Edit</a>
          <button class="btn btn-sm btn-danger st-del" data-id="${
            s.id
          }">Delete</button>
        </div>
      </div>`
        )
        .join("");

      const pager = `
      <div class="pager-bar">
        <button type="button" class="btn pager-prev" ${
          state.page <= 1 ? "disabled" : ""
        }>← Prev</button>
        <span class="pager-info">Page ${state.page} / ${state.total_pages} · ${
        state.total
      } items</span>
        <button type="button" class="btn pager-next" ${
          state.page >= state.total_pages ? "disabled" : ""
        }>Next →</button>
      </div>`;

      $grid.html(head + rows + pager);
    }

    function load() {
      console.log("here");
      $grid.html("Loading…");
      const qs = $.param({
        page: state.page,
        per_page: state.per_page,
        search: state.search,
      });
      $.ajax({
        url: WCSSM.rest + "stores?" + qs,
        headers: { "X-WP-Nonce": WCSSM.nonce },
      })
        .done(function (d) {
          state.total = d.total || 0;
          state.total_pages = d.total_pages || 1;
          render(d.items || []);
        })
        .fail(function (x) {
          $grid.html("Error: " + (x.responseJSON?.message || x.statusText));
        });
    }

    // delegated so it survives re-renders
    $grid.on("click", ".pager-prev", function (e) {
      e.preventDefault();
      if (state.page > 1) {
        state.page--;
        load();
      }
    });
    $grid.on("click", ".pager-next", function (e) {
      e.preventDefault();
      if (state.page < state.total_pages) {
        state.page++;
        load();
      }
    });

    // $grid.on("click", ".st-del", function () {
    //   const id = parseInt($(this).data("id"), 10);
    //   if (!confirm("Delete store #" + id + "?")) return;
    //   $.ajax({
    //     url: WCSSM.rest + "stores/" + id,
    //     method: "DELETE",
    //     headers: { "X-WP-Nonce": WCSSM.nonce },
    //   })
    //     .done(load)
    //     .fail((x) =>
    //       alert("Error: " + (x.responseJSON?.message || x.statusText))
    //     );
    // });

    // helper for inline messages
    function flash(msg, ok = true) {
      const $f = $("#wcssm-flash");
      $f.removeClass("is-ok is-err")
        .addClass(ok ? "is-ok" : "is-err")
        .text(msg)
        .stop(true, true)
        .fadeIn(120);

      // auto-hide after 2.5s
      setTimeout(() => $f.fadeOut(180), 2500);
    }

    $grid.on("click", ".st-del", function () {
      const id = parseInt($(this).data("id"), 10);
      if (!Number.isInteger(id)) return;

      if (!confirm("Delete store #" + id + "?")) return;

      $.ajax({
        url: WCSSM.rest + "stores/" + id,
        method: "DELETE",
        headers: { "X-WP-Nonce": WCSSM.nonce },
        dataType: "json", // <- make jQuery treat it as JSON
      })
        .done(function () {
          flash("Store deleted.", true);
          load();
        })
        .fail(function (x) {
          const msg =
            x.responseJSON && x.responseJSON.message
              ? x.responseJSON.message
              : x.statusText || "Unknown error";
          flash("Delete failed: " + msg, false);
        });
    });

    if ($refresh.length)
      $refresh.on("click", function () {
        state.page = 1;
        state.search = $search.val() || "";
        load();
      });
    if ($search.length)
      $search.on("keypress", function (e) {
        if (e.which === 13) {
          state.page = 1;
          state.search = $search.val() || "";
          load();
        }
      });

    load();
  };

  // -------------------------------------
  // STORES – Create
  // -------------------------------------

  // STORES – Create (CCM layout; full ship-to required for locked checkout)
  window.initStoreCreate = function () {
    const $msg = $("#s-msg");
    const $err = $("#s-err");
    const $btn = $("#s-save");

    function bar(t) {
      $msg.text(t || "");
    }
    function err(m) {
      if ($err.length) {
        $err.text(m || "").prop("hidden", !m);
        if (m) {
          $("html, body").animate({ scrollTop: Math.max(0, $err.offset().top - 120) }, 200);
        }
      }
      if (m) bar(m);
    }
    function v(sel) {
      return ($(sel).val() || "").trim();
    }

    $.ajax({
      url: WCSSM.rest + "users",
      headers: { "X-WP-Nonce": WCSSM.nonce },
      dataType: "json",
    }).done(function (list) {
      var $u = $("#s-user").empty();
      $u.append($("<option>").val("").text("Choose a store employee"));
      (list || []).forEach(function (u) {
        $u.append(
          $("<option>")
            .val(u.id)
            .text((u.name || "User #" + u.id) + (u.email ? " — " + u.email : ""))
        );
      });
      if (typeof window.dropdown === "function") window.dropdown($u[0]);
    });

    $("#sc-form").on("input change", "input, select, textarea", function () {
      err("");
      bar("You have unsaved changes");
      $("#sc-bar").addClass("is-dirty");
    });

    $btn.off("click").on("click", function () {
      const payload = {
        name: v("#s-name"),
        code: v("#s-code"),
        city: v("#s-city"),
        state: v("#s-state"),
        address: v("#s-address"),
        postcode: v("#s-postcode"),
        country: v("#s-country") || "CA",
        phone: v("#s-phone"),
        open_hours: v("#s-hours"),
        quota: v("#s-quota") === "" ? "" : parseInt(v("#s-quota"), 10),
        budget: v("#s-budget") === "" ? "" : parseFloat(v("#s-budget")),
        user_id: parseInt(v("#s-user"), 10) || 0,
      };

      if (!payload.name) return err("Store name is required.");
      if (!payload.code) return err("Store code is required.");
      if (!payload.city) return err("City is required.");
      if (!payload.state) return err("Province is required.");
      if (!payload.address) return err("Street address is required.");
      if (!payload.postcode) return err("Postal code is required.");
      if (!payload.phone) return err("Phone number is required.");
      if (!payload.open_hours) return err("Open hours are required.");
      if (!payload.user_id) return err("Choose the store employee.");
      if (payload.quota !== "" && (isNaN(payload.quota) || payload.quota < 0 || Math.floor(payload.quota) !== payload.quota)) {
        return err("Orders per month must be a whole number, 0 or more.");
      }
      if (payload.budget !== "" && (isNaN(payload.budget) || payload.budget < 0)) {
        return err("Monthly budget must be 0 or more.");
      }

      err("");
      bar("Creating store…");
      $btn.prop("disabled", true).text("Creating…");

      $.ajax({
        url: WCSSM.rest + "stores",
        method: "POST",
        headers: {
          "X-WP-Nonce": WCSSM.nonce,
          "Content-Type": "application/json",
        },
        data: JSON.stringify(payload),
        dataType: "json",
      })
        .done(function (d) {
          bar("Store created — opening it…");
          var id = d && d.id ? d.id : "";
          setTimeout(function () {
            window.location = id
              ? (WCSSM.home || "/manager/") + "stores/edit/" + id + "/"
              : (WCSSM.home || "/manager/") + "stores";
          }, 600);
        })
        .fail(function (x) {
          const msg =
            (x.responseJSON && x.responseJSON.message) || x.statusText || "Couldn’t create this store.";
          err(msg);
          $btn.prop("disabled", false).text("Create store");
        });
    });
  };

  // -------------------------------------
  // STORES – Edit
  // -------------------------------------

  window.initStoreEdit = function (id) {
    const $msg = $("#s-msg");
    const $sel = $("#s-user");
    const $save = jQuery("#s-save");

    function toast(t, ok = true) {
      $msg
        .removeClass("err ok")
        .addClass(ok ? "ok" : "err")
        .text(t)
        .show();
    }
    $save.prop("disabled", true);

    // fetch store + users in parallel
    const reqStore = $.ajax({
      url: WCSSM.rest + "stores/" + id,
      headers: { "X-WP-Nonce": WCSSM.nonce },
      dataType: "json",
    });

    // const reqUsers = $.ajax({
    //   url: WCSSM.rest + "users", // returns only unassigned store_employee
    //   headers: { "X-WP-Nonce": WCSSM.nonce },
    //   dataType: "json",
    // });

    const reqUsers = $.ajax({
      url: WCSSM.rest + "users?store_id=" + encodeURIComponent(id),
      headers: { "X-WP-Nonce": WCSSM.nonce },
      dataType: "json",
    });

    $.when(reqStore, reqUsers)
      .done(function (storeRes, usersRes) {
        console.log(usersRes);

        const s = storeRes[0] || {};
        // const users = usersRes[0] || [];
        const usersRaw = usersRes[0];
        const users = Array.isArray(usersRaw)
          ? usersRaw
          : usersRaw && Array.isArray(usersRaw.items)
          ? usersRaw.items
          : [];

        // fill form fields
        $("#s-name").val(s.name || "");
        $("#s-code").val(s.code || "");
        $("#s-city").val(s.city || "");
        $("#s-state").val(s.state || "");
        $("#s-quota").val(s.quota ?? "");
        $("#s-budget").val(s.budget ?? "");

        $("#s-address").val(s.address || "");
        $("#s-phone").val(s.phone || "");
        $("#s-hours").val(s.open_hours || "");

        // rebuild dropdown
        $sel.empty();
        $sel.append(jQuery("<option>").val("").text("Select a Store Employee"));

        users.forEach(function (u) {
          var label =
            (u.name || "User #" + u.id) + (u.email ? " — " + u.email : "");
          if (u.current) label += " (current)";
          $sel.append($("<option>").val(String(u.id)).text(label));
        });

        // Prefer numeric s.user_id, otherwise match by email from users list
        let curIdNum = 0;
        if (s.user_id && /^\d+$/.test(String(s.user_id))) {
          curIdNum = parseInt(String(s.user_id), 10);
        } else if (s.user_email) {
          const match = users.find(
            (u) =>
              (u.email || "").toLowerCase() ===
              String(s.user_email).toLowerCase()
          );
          if (match && match.id) curIdNum = parseInt(String(match.id), 10) || 0;
        }

        // inject current user if they’re not in the unassigned list
        if (curIdNum > 0) {
          if (!$sel.find('option[value="' + String(curIdNum) + '"]').length) {
            const label =
              (s.user_name || "User #" + curIdNum) +
              (s.user_email ? " — " + s.user_email : "") +
              " (current)";
            $sel.append(jQuery("<option>").val(String(curIdNum)).text(label));
          }
          $sel.val(String(curIdNum));
        } else {
          // fallback: keep placeholder selected
          $sel.val("");
        }

        // enable Save now that the form is ready
        $save.prop("disabled", false);
      })
      .fail(function (x) {
        toast(
          "Load failed: " +
            (x.responseJSON?.message || x.statusText || "Error"),
          false
        );
      });

    // save
    $("#s-save")
      .off("click")
      .on("click", function () {
        const valStr = jQuery("#s-user").val();
        const userId = /^\d+$/.test(String(valStr)) ? parseInt(valStr, 10) : 0;

        console.log(userId);
        const body = {
          name: $("#s-name").val(),
          code: $("#s-code").val(),
          city: $("#s-city").val(),
          state: $("#s-state").val(),
          quota: $("#s-quota").val(),
          budget: $("#s-budget").val(),
          user_id: Number.isInteger(userId) && userId > 0 ? userId : 0,
          address: $("#s-address").val(),
          phone: $("#s-phone").val(),
          open_hours: $("#s-hours").val(),
        };

        if (!body.name) return toast("Store name is required", false);
        if (!body.code) return toast("Store Code is required", false);
        if (!body.quota) return toast("Store quota is required", false);
        if (!body.budget) return toast("Store budget is required", false);
        if (!body.user_id) return toast("Store employee is required", false);
        if (!body.address) return toast("Address is required", false);
        if (!body.phone) return toast("Phone number is required", false);
        if (!body.open_hours) return toast("Open hours are required", false);

        toast("Saving…");
        $.ajax({
          url: WCSSM.rest + "stores/" + id,
          method: "PUT",
          headers: {
            "X-WP-Nonce": WCSSM.nonce,
            "Content-Type": "application/json",
          },
          data: JSON.stringify(body),
        })
          .done(function () {
            toast("Saved! Redirecting…", true);
            setTimeout(function () {
              window.location.href = "/manager/stores";
            }, 800);
          })
          .fail(function (x) {
            toast("Error: " + (x.responseJSON?.message || x.statusText), false);
          });
      });
  };
})(jQuery);

/* orders list functions */

/* ===== ORDERS MODULE ===== */
(function ($) {
  window.initOrdersList = function () {
    const $grid = $("#wcssm-orders-grid");
    const $flash = $("#wcssm-flash");
    const $status = $("#o-status");
    const $from = $("#o-from");
    const $to = $("#o-to");
    const $apply = $("#o-apply");
    const $clear = $("#o-clear");
    const $refresh = $("#o-refresh");

    const state = {
      page: 1,
      per_page: 20,
      total_pages: 1,
      total: 0,
      status: new URLSearchParams(window.location.search).get("status") || "",
      from: "",
      to: "",
    };
    if (state.status) $status.val(state.status);

    function flash(msg, ok = true) {
      $flash
        .removeClass("is-ok is-err")
        .addClass(ok ? "is-ok" : "is-err")
        .text(msg)
        .stop(true, true)
        .fadeIn(120);
      setTimeout(() => $flash.fadeOut(180), 2500);
    }

    function escapeHtml(s) {
      return (s || "")
        .toString()
        .replace(
          /[&<>"]/g,
          (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c])
        );
    }

    function canApprove(slug) {
      return ["pending", "awaiting-approval", "on-hold"].includes(slug);
    }
    function canReject(slug) {
      return ["pending", "awaiting-approval", "on-hold", "approved"].includes(
        slug
      );
    }

    function vendorCell(o) {
      const slug = (o.vendor || "").toString();
      const label = (o.vendor_label || "—").toString();
      const cls =
        slug === "amazon"
          ? "vendor-amazon"
          : slug === "gt"
          ? "vendor-gt"
          : slug === "mixed"
          ? "vendor-mixed"
          : "vendor-none";
      return `<span class="badge vendor-badge ${cls}">${escapeHtml(label)}</span>`;
    }

    function render(items) {
      const head = `
        <div class="row head">
          <div>#</div>
          <div>Date</div>
          <div>Total</div>
          <div>Customer</div>
          <div>Vendor</div>
          <div>EDD</div>
          <div>Ref</div>
          <div>Actions</div>
        </div>`;

      const rows = (items || [])
        .map((o) => {
          // List actions: View only. Amazon / G&T Test Order live on the order View screen.
          const act = [
            `<button class="btn btn-xs o-view" data-id="${o.id}">View</button>`,
          ];

          return `
          <div class="row">
            <div>${escapeHtml(o.number || "#" + o.id)}</div>
            <div>${escapeHtml(o.date || "")}</div>
            <div>${o.total || ""}</div>
            <div>${escapeHtml(o.customer || "")}</div>
            <div>${vendorCell(o)}</div>
            <div>${escapeHtml(o.edd || "")}</div>
            <div>${escapeHtml(o.ref || "")}</div>
            <div class="acts">${act.join(" ")}</div>
          </div>`;
        })
        .join("");

      const pager = `
        <div class="pager-bar">
          <button type="button" class="btn pager-prev" ${
            state.page <= 1 ? "disabled" : ""
          }>← Prev</button>
          <span class="pager-info">Page ${state.page} / ${
        state.total_pages
      } · ${state.total} orders</span>
          <button type="button" class="btn pager-next" ${
            state.page >= state.total_pages ? "disabled" : ""
          }>Next →</button>
        </div>`;

      $grid.html(head + rows + pager);
    }

    function load() {
      $grid.html("Loading…");
      const qs = $.param({
        page: state.page,
        per_page: state.per_page,
        status: state.status || "",
        date_from: state.from || "",
        date_to: state.to || "",
      });
      $.ajax({
        url: WCSSM.rest + "orders?" + qs,
        headers: { "X-WP-Nonce": WCSSM.nonce },
        dataType: "json",
      })
        .done(function (d) {
          state.total = d.total || 0;
          state.total_pages = d.total_pages || 1;
          render(d.items || []);
        })
        .fail(function (x) {
          $grid.html("Error: " + (x.responseJSON?.message || x.statusText));
        });
    }

    // Filters
    $apply.on("click", function () {
      state.page = 1;
      state.status = $status.val() || "";
      state.from = $from.val() || "";
      state.to = $to.val() || "";
      load();
    });
    $clear.on("click", function () {
      $status.val("");
      $from.val("");
      $to.val("");
      state.page = 1;
      state.status = "";
      state.from = "";
      state.to = "";
      load();
    });
    $refresh.on("click", function () {
      load();
    });

    // Pager (delegated)
    $grid.on("click", ".pager-prev", function (e) {
      e.preventDefault();
      if (state.page > 1) {
        state.page--;
        load();
      }
    });
    $grid.on("click", ".pager-next", function (e) {
      e.preventDefault();
      if (state.page < state.total_pages) {
        state.page++;
        load();
      }
    });

    // Actions
    $grid.on("click", ".o-approve", function () {
      const id = parseInt($(this).data("id"), 10);
      $.ajax({
        url: WCSSM.rest + "orders/" + id + "/status",
        method: "POST",
        headers: {
          "X-WP-Nonce": WCSSM.nonce,
          "Content-Type": "application/json",
        },
        data: JSON.stringify({ status: "approved" }),
        dataType: "json",
      })
        .done(function () {
          flash("Order approved.", true);
          load();
        })
        .fail(function (x) {
          flash(
            "Approve failed: " + (x.responseJSON?.message || x.statusText),
            false
          );
        });
    });

    $grid.on("click", ".o-amazon-order", function () {
      const id = parseInt($(this).data("id"), 10);
      const home = (WCSSM.home || "/").replace(/\/+$/, "/");
      window.location.href = home + "amazon-catalog/?order-id=" + id;
    });

    $grid.on("click", ".o-amazon-view", function () {
      const id = parseInt($(this).data("id"), 10);
      const base = (WCSSM.manager_base || "/manager/").replace(/\/+$/, "/");
      window.location.href = base + "orders/amazon-view/" + id;
    });

    $grid.on("click", ".o-gt-order", function () {
      const id = parseInt($(this).data("id"), 10);
      const home = (WCSSM.home || "/").replace(/\/+$/, "/");
      window.location.href = home + "gt-catalog/?order-id=" + id;
    });

    $grid.on("click", ".o-gt-view", function () {
      const id = parseInt($(this).data("id"), 10);
      const base = (WCSSM.manager_base || "/manager/").replace(/\/+$/, "/");
      window.location.href = base + "orders/gt-view/" + id;
    });

    $grid.on("click", ".o-reject", function () {
      const id = parseInt($(this).data("id"), 10);
      const note = prompt("Add a rejection note (optional):") || "";
      $.ajax({
        url: WCSSM.rest + "orders/" + id + "/status",
        method: "POST",
        headers: {
          "X-WP-Nonce": WCSSM.nonce,
          "Content-Type": "application/json",
        },
        data: JSON.stringify({ status: "rejected", note }),
        dataType: "json",
      })
        .done(function () {
          flash("Order rejected.", true);
          load();
        })
        .fail(function (x) {
          flash(
            "Reject failed: " + (x.responseJSON?.message || x.statusText),
            false
          );
        });
    });

    // });

    $grid.on("click", ".o-view", function (e) {
      e.preventDefault();
      const id = parseInt($(this).data("id"), 10);
      if (!id) return;

      // Prefer a base passed from PHP; fallback to /manager/
      const base =
        window.WCSSM && WCSSM.manager_base ? WCSSM.manager_base : "/manager/";
      const url = base.replace(/\/+$/, "/") + "orders/view/" + id;

      if (e.ctrlKey || e.metaKey) {
        // open in new tab if user holds Ctrl/Cmd
        window.open(url, "_blank");
      } else {
        window.location.assign(url);
      }
    });

    load();
  };

  window.initOrderView = function initOrderView(orderId) {
    var $wrap = jQuery("#order-detail");
    var statusBusy = false;

    function setOrderMsg(html) {
      var $msg = $wrap.find("#order-msg");
      if ($msg.length) {
        $msg.html(html);
      }
    }

    function setStatusButtonsBusy(busy, newStatus) {
      var $approve = $wrap.find("#order-approve");
      var $reject = $wrap.find("#order-reject");
      $approve.prop("disabled", !!busy);
      $reject.prop("disabled", !!busy);
      if (busy && newStatus === "approved" && $approve.length) {
        $approve.data("label", $approve.data("label") || $approve.text());
        $approve.text("Approving…");
      } else if (busy && newStatus === "rejected" && $reject.length) {
        $reject.data("label", $reject.data("label") || $reject.text());
        $reject.text("Rejecting…");
      } else {
        if ($approve.data("label")) $approve.text($approve.data("label"));
        if ($reject.data("label")) $reject.text($reject.data("label"));
      }
    }

    function updateStatus(newStatus, opts) {
      opts = opts || {};
      if (statusBusy) return;
      statusBusy = true;
      setStatusButtonsBusy(true, newStatus);
      setOrderMsg("<div class='flash-info'>Updating status…</div>");

      var payload = { status: newStatus };
      if (opts.override) payload.override = 1;
      if (opts.note) payload.note = opts.note;

      fetch(
        (WCSSM.rest || "/wp-json/wcss/v1/") + "orders/" + orderId + "/status",
        {
          method: "POST",
          headers: {
            "X-WP-Nonce": WCSSM.nonce,
            "Content-Type": "application/json",
          },
          body: JSON.stringify(payload),
        }
      )
        .then(function (r) {
          if (r.status === 409) {
            return r.json().then(function (j) {
              j.__status = 409;
              return j;
            });
          }
          return r.json();
        })
        .then(function (res) {
          // WP REST: limit fields live on res.data (flat). Older nested shape: res.data.data
          var limitData = null;
          if (res && res.__status === 409) {
            if (res.data && res.data.data && typeof res.data.data === "object") {
              limitData = res.data.data;
            } else if (res.data && typeof res.data === "object") {
              limitData = res.data;
            }
          }

          if (limitData) {
            statusBusy = false;
            setStatusButtonsBusy(false);
            var d = limitData;
            var money = function (n) {
              try {
                return new Intl.NumberFormat(undefined, {
                  style: "currency",
                  currency: d.currency || "CAD",
                }).format(+n || 0);
              } catch (e) {
                return (d.currency || "") + " " + (n || 0);
              }
            };
            var msg = "Approving this order exceeds the store limits:\n";
            if (d.quota)
              msg += "• Orders: " + d.will_orders + " / " + d.quota + "\n";
            if (d.budget)
              msg +=
                "• Spend: " +
                money(d.will_spend) +
                " / " +
                money(d.budget) +
                "\n";
            msg += "\nProceed anyway?";
            if (window.confirm(msg)) {
              var note = window.prompt(
                "Add a note (optional):",
                "Approved with override"
              );
              updateStatus("approved", {
                override: true,
                note: note || "",
              });
            } else {
              setOrderMsg(
                "<div class='flash-error'>Approval cancelled.</div>"
              );
            }
            return;
          }

          if (res && res.ok) {
            setOrderMsg(
              "<div class='flash-success'>Status updated to " +
                newStatus +
                "!</div>"
            );
            // Stay on this order View and refresh so vendor fulfill options appear.
            setTimeout(function () {
              initOrderView(orderId);
            }, 450);
          } else {
            statusBusy = false;
            setStatusButtonsBusy(false);
            setOrderMsg(
              "<div class='flash-error'>" +
                (res && res.message ? res.message : "Failed") +
                "</div>"
            );
          }
        })
        .catch(function () {
          statusBusy = false;
          setStatusButtonsBusy(false);
          setOrderMsg("<div class='flash-error'>Request failed</div>");
        });
    }

    // Delegated handlers survive HTML re-renders (fixes first-click miss).
    $wrap.off("click.wcssOrderStatus");
    $wrap.on("click.wcssOrderStatus", "#order-approve", function (e) {
      e.preventDefault();
      e.stopPropagation();
      updateStatus("approved");
    });
    $wrap.on("click.wcssOrderStatus", "#order-reject", function (e) {
      e.preventDefault();
      e.stopPropagation();
      updateStatus("rejected");
    });
    jQuery(document)
      .off("click.wcssVendorFulfill", "[data-vendor-fulfill]")
      .on("click.wcssVendorFulfill", "[data-vendor-fulfill]", function () {
        var path = jQuery(this).attr("data-vendor-path") || "";
        if (!path) return;
        var home = (WCSSM.home || "/").replace(/\/+$/, "/");
        window.location.href = home + path + orderId;
      });

    $wrap.off("click.wcssToggleApi", "#ov-toggle-api-json");
    $wrap.on("click.wcssToggleApi", "#ov-toggle-api-json", function (e) {
      e.preventDefault();
      var $btn = jQuery(this);
      var $pre = $wrap.find("#ov-api-json");
      var $copy = $wrap.find("#ov-copy-api-json");
      var $label = $btn.find(".ov-api-toggle__label");
      var open = $btn.attr("aria-expanded") === "true";
      if (open) {
        $btn.attr("aria-expanded", "false");
        $label.text("Show JSON payload");
        $pre.addClass("is-collapsed").attr("hidden", true);
        $copy.attr("hidden", true);
        $wrap.find(".ov-api-panel").removeClass("is-open");
      } else {
        $btn.attr("aria-expanded", "true");
        $label.text("Hide JSON payload");
        $pre.removeClass("is-collapsed").removeAttr("hidden");
        $copy.removeAttr("hidden");
        $wrap.find(".ov-api-panel").addClass("is-open");
      }
    });

    $wrap.off("click.wcssCopyApi", "#ov-copy-api-json");
    $wrap.on("click.wcssCopyApi", "#ov-copy-api-json", function (e) {
      e.preventDefault();
      var pre = document.getElementById("ov-api-json");
      var text = pre ? pre.textContent || "" : "";
      var $btn = jQuery(this);
      var done = function () {
        var prev = $btn.data("label") || $btn.text();
        $btn.data("label", prev).text("Copied");
        setTimeout(function () {
          $btn.text($btn.data("label") || "Copy JSON");
        }, 1400);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () {
          done();
        });
      } else {
        done();
      }
    });

    $wrap.html("<p>Loading...</p>");

    fetch((WCSSM.rest || "/wp-json/wcss/v1/") + "orders/" + orderId, {
      headers: { "X-WP-Nonce": WCSSM.nonce },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data && data.code) {
          $wrap.html(
            "<div class='flash-error'>Error: " +
              (data.message || "Failed") +
              "</div>"
          );
          return;
        }
        //store lines

        // var storeLine = "";
        // console.log(data.store);
        // if (data && data.store && (data.store.id || data.store.name)) {
        //   var s = data.store || {};
        //   var bits = [];

        //   if (s.name) bits.push(s.name);
        //   if (s.code) bits.push("(" + s.code + ")");

        //   var loc = [];
        //   if (s.city) loc.push(s.city);
        //   if (s.state) loc.push(s.state);
        //   if (loc.length) bits.push("— " + loc.join(", "));

        //   if (s.id) bits.push("<span class='muted'>#" + s.id + "</span>");

        //   storeLine = "<p><strong>Store:</strong> " + bits.join(" ") + "</p>";
        // }

        function esc(s) {
          return (s == null ? "" : String(s))
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
        }

        function dlRow(label, value) {
          if (value == null || value === "") return "";
          return (
            "<div class='ov-dl__row'>" +
            "<dt>" +
            esc(label) +
            "</dt>" +
            "<dd>" +
            value +
            "</dd>" +
            "</div>"
          );
        }

        function formatAddr(a) {
          if (!a || typeof a !== "object") return "";
          var name = [a.first_name, a.last_name].filter(Boolean).join(" ").trim();
          var lines = [];
          if (name) lines.push(esc(name));
          if (a.company) lines.push(esc(a.company));
          if (a.address_1) lines.push(esc(a.address_1));
          if (a.address_2) lines.push(esc(a.address_2));
          var cityLine = [a.city, a.state, a.postcode].filter(Boolean).join(", ");
          if (cityLine) lines.push(esc(cityLine));
          if (a.country) lines.push(esc(a.country));
          if (a.email) lines.push(esc(a.email));
          if (a.phone) lines.push(esc(a.phone));
          return lines.length ? lines.join("<br>") : "";
        }

        function hasAddr(a) {
          return !!(a && (a.address_1 || a.city || a.email || a.phone || a.first_name || a.company));
        }

        // ledger + store blocks (optional UI, shown only if present)
        var ledgerBlock = "";
        if (data && data.ledger) {
          var l = data.ledger;
          var fmt = function (n) {
            try {
              return new Intl.NumberFormat(undefined, {
                style: "currency",
                currency: l.currency,
              }).format(+n || 0);
            } catch (e) {
              return (l.currency || "") + " " + (n || 0);
            }
          };
          ledgerBlock =
            "<div class='ov-card ov-ledger'>" +
            "<h3>Monthly Usage</h3>" +
            "<dl class='ov-dl'>" +
            dlRow("Month", esc(l.month || "")) +
            dlRow(
              "Orders",
              esc((l.used_orders || 0) + " / " + (l.quota || "∞"))
            ) +
            dlRow(
              "Spend",
              esc(fmt(l.used_amount || 0) + " / " + (l.budget ? fmt(l.budget) : "∞"))
            ) +
            dlRow(
              "Remaining orders",
              esc(l.quota ? Math.max(0, l.quota - (l.used_orders || 0)) : "∞")
            ) +
            dlRow(
              "Remaining budget",
              esc(
                l.budget
                  ? fmt(Math.max(0, (l.budget || 0) - (l.used_amount || 0)))
                  : "∞"
              )
            ) +
            "</dl>" +
            "</div>";
        }

        var storeBlock = "";
        if (data && data.store && (data.store.name || data.store.id)) {
          var s = data.store;
          storeBlock =
            "<div class='ov-card store-details'>" +
            "<h3>Store</h3>" +
            "<dl class='ov-dl'>" +
            dlRow("Store", esc(s.name || "")) +
            dlRow("Store ID", esc(s.id || "")) +
            dlRow("Store code", esc(s.code || "")) +
            dlRow("City", esc(s.city || "")) +
            dlRow("State / province", esc(s.state || "")) +
            "</dl>" +
            "</div>";
        }

        var orderMetaRows =
          dlRow("Date", esc(data.date || "")) +
          dlRow("Status", esc(data.status || "")) +
          dlRow("Total", data.total_html || esc(data.total || "")) +
          dlRow("Customer", esc(data.customer || "")) +
          dlRow("Email", esc(data.billing_email || (data.billing && data.billing.email) || "")) +
          dlRow("Phone", esc(data.billing_phone || (data.billing && data.billing.phone) || "")) +
          dlRow("Expected delivery", esc(data.edd || "")) +
          dlRow("Internal ref", esc(data.ref || ""));

        if (data.shipment) {
          orderMetaRows +=
            dlRow("Tracking", esc(data.shipment.tracking || "")) +
            dlRow("Carrier", esc(data.shipment.carrier || "")) +
            dlRow("Shipment EDD", esc(data.shipment.edd || ""));
        }

        // Billing / shipping sit under Store (right column) to fill empty space.
        var sideAddressBlocks = "";
        if (hasAddr(data.billing)) {
          sideAddressBlocks +=
            "<div class='ov-card ov-billing-card'>" +
            "<h3>Billing address</h3>" +
            "<div class='ov-addr'>" +
            formatAddr(data.billing) +
            "</div>" +
            "</div>";
        }
        if (hasAddr(data.shipping)) {
          sideAddressBlocks +=
            "<div class='ov-card ov-shipping-card'>" +
            "<h3>Shipping address</h3>" +
            "<div class='ov-addr'>" +
            formatAddr(data.shipping) +
            "</div>" +
            "</div>";
        }

        var sideColumn =
          storeBlock || sideAddressBlocks
            ? "<div class='ov-side-col'>" +
              storeBlock +
              sideAddressBlocks +
              "</div>"
            : "";

        var noteBlock = "";
        if (data.customer_note) {
          noteBlock =
            "<div class='ov-card'>" +
            "<h3>Order notes</h3>" +
            "<p class='ov-note'>" +
            esc(data.customer_note).replace(/\n/g, "<br>") +
            "</p>" +
            "</div>";
        }

        var html =
          "<div class='order-store-wrap'>" +
          "<div class='ov-card order-meta'>" +
          "<h2>Order #" +
          esc(data.number || orderId) +
          "</h2>" +
          "<dl class='ov-dl'>" +
          orderMetaRows +
          "</dl>" +
          "</div>" +
          sideColumn +
          "</div>" +
          noteBlock +
          "<div class='ov-items-card'>" +
          "<h3>Items</h3>" +
          "<div class='ov-table-wrap'>" +
          "<table class='table ov-items-table'>" +
          "<thead><tr><th>Product</th><th>Vendor</th><th>SKU</th><th>Qty</th><th>Unit</th><th>Total</th></tr></thead>" +
          "<tbody>" +
          (Array.isArray(data.items)
            ? data.items
                .map(function (i) {
                  var vendorCell = i.vendor_label
                    ? "<span class='ov-vendor-badge'>" +
                      esc(i.vendor_label) +
                      "</span>" +
                      (i.vendor_id
                        ? "<span class='ov-vendor-id'>" +
                          esc(i.vendor_id) +
                          "</span>"
                        : "")
                    : "<span class='ov-muted'>—</span>";
                  var unitCell =
                    i.vendor_unit_price_html ||
                    i.subtotal ||
                    "<span class='ov-muted'>—</span>";
                  return (
                    "<tr>" +
                    "<td>" +
                    esc(i.name || "") +
                    "</td>" +
                    "<td>" +
                    vendorCell +
                    "</td>" +
                    "<td>" +
                    esc(i.sku || "") +
                    "</td>" +
                    "<td>" +
                    esc(i.qty || 0) +
                    "</td>" +
                    "<td>" +
                    unitCell +
                    "</td>" +
                    "<td>" +
                    (i.total || "") +
                    "</td>" +
                    "</tr>"
                  );
                })
                .join("")
            : "<tr><td colspan='6'>No items</td></tr>") +
          "</tbody>" +
          "</table>" +
          "</div>" +
          "</div>" +
          ledgerBlock +
          (function () {
            var auto = data.auto_submit_detail || data.auto_submit || null;
            var amazon = data.amazon_order || {};
            var gt = data.gt_order || {};
            var autoStatus = (auto && auto.status) || "";
            var autoDone =
              autoStatus === "submitted" ||
              autoStatus === "partial" ||
              autoStatus === "failed" ||
              autoStatus === "skipped";

            function vendorOrderCard(title, payload, kind) {
              if (!payload || !payload.found) {
                return (
                  "<div class='ov-card ov-vendor-order is-empty'>" +
                  "<h3>" +
                  esc(title) +
                  "</h3>" +
                  "<p class='ov-muted'>No " +
                  esc(kind) +
                  " order was triggered for this requisition.</p>" +
                  "</div>"
                );
              }
              var rows = "";
              rows += dlRow("Status", esc(payload.status || "—"));
              if (payload.auto) rows += dlRow("Source", "Automatic submit");
              if (payload.po_id) rows += dlRow("PO / Ref", esc(payload.po_id));
              if (payload.trial_mode) rows += dlRow("Mode", "Amazon trial");
              if (payload.stage_only) rows += dlRow("Environment", "G&T stage");
              if (payload.order_total != null && payload.order_total !== "") {
                rows += dlRow(
                  "Vendor total",
                  esc(String(payload.order_total)) +
                    " " +
                    esc(payload.currency || "CAD")
                );
              }
              if (payload.submitted_at || payload.placed_at) {
                rows += dlRow(
                  "Submitted",
                  esc(payload.submitted_at || payload.placed_at)
                );
              }
              if (payload.message) rows += dlRow("API message", esc(payload.message));
              if (
                payload.gt_result &&
                payload.gt_result.ok === false &&
                Array.isArray(payload.gt_result.errors) &&
                payload.gt_result.errors.length
              ) {
                rows += dlRow(
                  "Errors",
                  esc(payload.gt_result.errors.join("; "))
                );
              }

              var itemRows = "";
              var items =
                (Array.isArray(payload.cart_items) && payload.cart_items.length
                  ? payload.cart_items
                  : payload.intended_lines) || [];
              if (items.length) {
                itemRows =
                  "<div class='ov-table-wrap' style='margin-top:12px'>" +
                  "<table class='table ov-items-table'><thead><tr>" +
                  "<th>Item</th><th>Vendor ID</th><th>Qty</th><th>Unit</th>" +
                  "</tr></thead><tbody>" +
                  items
                    .map(function (it) {
                      var name =
                        it.name ||
                        it.title ||
                        it.description ||
                        it.asin ||
                        it.supplier_part_id ||
                        "Item";
                      var vid =
                        it.vendor_id ||
                        it.asin ||
                        it.supplier_part_id ||
                        it.productIdentifier ||
                        "";
                      var qty = it.qty || it.quantity || 1;
                      var unit =
                        it.unit_price != null
                          ? it.unit_price
                          : it.price != null
                          ? it.price
                          : it.amazon_price != null
                          ? it.amazon_price
                          : "—";
                      return (
                        "<tr><td>" +
                        esc(name) +
                        "</td><td>" +
                        esc(vid) +
                        "</td><td>" +
                        esc(qty) +
                        "</td><td>" +
                        esc(unit) +
                        "</td></tr>"
                      );
                    })
                    .join("") +
                  "</tbody></table></div>";
              }

              return (
                "<div class='ov-card ov-vendor-order'>" +
                "<h3>" +
                esc(title) +
                "</h3>" +
                "<dl class='ov-dl'>" +
                rows +
                "</dl>" +
                itemRows +
                "</div>"
              );
            }

            var vendorOrdersHtml =
              "<div class='ov-vendor-orders'>" +
              "<div class='ov-card ov-auto-submit'>" +
              "<h3>Auto vendor submit</h3>" +
              (auto
                ? "<dl class='ov-dl'>" +
                  dlRow("Status", esc(autoStatus || "—")) +
                  dlRow(
                    "Selected vendor",
                    esc(
                      auto.winning_vendor === "gt"
                        ? "Grand & Toy"
                        : auto.winning_vendor === "amazon"
                        ? "Amazon"
                        : auto.winning_vendor || data.vendor_label || "—"
                    )
                  ) +
                  dlRow("Detail", esc(auto.message || "")) +
                  "</dl>"
                : "<p class='ov-muted'>No automatic vendor submit recorded for this order yet.</p>") +
              "</div>" +
              vendorOrderCard("Amazon order", amazon, "Amazon") +
              vendorOrderCard("Grand & Toy order", gt, "Grand & Toy") +
              "</div>";

            var statusSlug = (data.status_slug || "").toString();
            var canDecide =
              ["pending", "awaiting-approval", "on-hold"].indexOf(statusSlug) !==
              -1;
            var isApproved = statusSlug === "approved";
            var storeVendor = (data.vendor || "").toString();
            var storeVendorLabel = (data.vendor_label || "—").toString();
            var vendorAlreadyPlaced =
              (amazon && amazon.found) || (gt && gt.found);
            var needManualFulfill =
              isApproved &&
              !vendorAlreadyPlaced &&
              (!autoDone ||
                autoStatus === "failed" ||
                autoStatus === "partial" ||
                autoStatus === "skipped");
            var fulfillVendors = [
              {
                key: "amazon",
                label: "Amazon",
                path: "amazon-catalog/?order-id=",
              },
              {
                key: "gt",
                label: "Grand & Toy",
                path: "gt-catalog/?order-id=",
              },
            ];

            var statusHtml =
              "<div class='ov-status-card'>" + "<h3>Order status</h3>";

            if (canDecide) {
              statusHtml +=
                "<p class='ov-status-hint'>This requisition is still waiting. You can approve or reject manually if auto-submit has not finished.</p>" +
                "<div class='actions ov-status-actions'>" +
                "<button type='button' class='btn btn-approve' id='order-approve'>Approve order</button>" +
                "<button type='button' class='btn btn-reject' id='order-reject'>Reject order</button>" +
                "</div>";
            } else {
              statusHtml +=
                "<p class='ov-status-hint'>Current status: <strong>" +
                esc(data.status || statusSlug) +
                "</strong></p>";
            }

            if (needManualFulfill) {
              statusHtml +=
                "<div class='ov-vendor-fulfill'>" +
                "<p class='ov-vendor-fulfill__eyebrow'>Manual fallback</p>" +
                "<h4>Place with a vendor</h4>" +
                "<p class='ov-vendor-fulfill__lead'>Use only if automatic submit failed or was skipped. Store cheapest vendor: <strong>" +
                esc(storeVendorLabel) +
                "</strong></p>" +
                "<div class='actions ov-fulfill-actions'>";
              fulfillVendors.forEach(function (v) {
                var preferred = storeVendor && storeVendor === v.key;
                statusHtml +=
                  "<button type='button' class='btn btn-fulfill" +
                  (preferred ? " is-preferred" : "") +
                  "' data-vendor-fulfill='" +
                  esc(v.key) +
                  "' data-vendor-path='" +
                  esc(v.path) +
                  "'>" +
                  "<span class='btn-fulfill__name'>" +
                  esc(v.label) +
                  (preferred ? " · recommended" : "") +
                  "</span>" +
                  "</button>";
              });
              statusHtml += "</div></div>";
            }

            statusHtml += "<div id='order-msg'></div></div>";

            // --- Below status: calculation breakdown ---
            var totals = data.totals || {};
            var calcRows = "";
            calcRows += dlRow("Currency", esc(totals.currency || "CAD"));
            calcRows += dlRow("Item count", esc(String(totals.item_count != null ? totals.item_count : (data.items || []).length)));
            calcRows += dlRow("Subtotal (before tax)", totals.subtotal_html || esc(String(totals.subtotal != null ? totals.subtotal : "—")));
            if (totals.discount_total > 0) {
              calcRows += dlRow("Discount", totals.discount_total_html || esc(String(totals.discount_total)));
            }
            calcRows += dlRow("Shipping", totals.shipping_total_html || esc(String(totals.shipping_total != null ? totals.shipping_total : "0")));
            if (totals.shipping_tax > 0) {
              calcRows += dlRow("Shipping tax", totals.shipping_tax_html || esc(String(totals.shipping_tax)));
            }
            if (totals.fee_total > 0) {
              calcRows += dlRow("Fees", totals.fee_total_html || esc(String(totals.fee_total)));
            }
            calcRows += dlRow("Cart tax", totals.cart_tax_html || esc(String(totals.cart_tax != null ? totals.cart_tax : "0")));
            calcRows += dlRow("Total tax", totals.total_tax_html || esc(String(totals.total_tax != null ? totals.total_tax : "0")));
            calcRows += dlRow("Grand total", totals.grand_total_html || data.total_html || esc(String(totals.grand_total != null ? totals.grand_total : data.total || "—")));
            if (totals.payment_method) {
              calcRows += dlRow("Payment method", esc(totals.payment_method));
            }

            var taxLinesHtml = "";
            if (Array.isArray(totals.tax_lines) && totals.tax_lines.length) {
              taxLinesHtml =
                "<div class='ov-table-wrap' style='margin-top:12px'>" +
                "<table class='table ov-items-table'><thead><tr>" +
                "<th>Tax</th><th>Rate %</th><th>Amount</th><th>Shipping tax</th>" +
                "</tr></thead><tbody>" +
                totals.tax_lines
                  .map(function (t) {
                    return (
                      "<tr><td>" +
                      esc(t.label || "Tax") +
                      "</td><td>" +
                      esc(t.rate_percent || "—") +
                      "</td><td>" +
                      (t.tax_total_html || esc(String(t.tax_total || 0))) +
                      "</td><td>" +
                      esc(String(t.shipping_tax_total != null ? t.shipping_tax_total : "0")) +
                      "</td></tr>"
                    );
                  })
                  .join("") +
                "</tbody></table></div>";
            }

            var feeLinesHtml = "";
            if (Array.isArray(totals.fee_lines) && totals.fee_lines.length) {
              feeLinesHtml =
                "<div class='ov-table-wrap' style='margin-top:12px'>" +
                "<table class='table ov-items-table'><thead><tr>" +
                "<th>Fee</th><th>Amount</th><th>Tax</th>" +
                "</tr></thead><tbody>" +
                totals.fee_lines
                  .map(function (f) {
                    return (
                      "<tr><td>" +
                      esc(f.name || "Fee") +
                      "</td><td>" +
                      (f.total_html || esc(String(f.total || 0))) +
                      "</td><td>" +
                      esc(String(f.total_tax != null ? f.total_tax : "0")) +
                      "</td></tr>"
                    );
                  })
                  .join("") +
                "</tbody></table></div>";
            }

            var shippingLinesHtml = "";
            if (Array.isArray(totals.shipping_lines) && totals.shipping_lines.length) {
              shippingLinesHtml =
                "<div class='ov-table-wrap' style='margin-top:12px'>" +
                "<table class='table ov-items-table'><thead><tr>" +
                "<th>Shipping method</th><th>Amount</th><th>Tax</th>" +
                "</tr></thead><tbody>" +
                totals.shipping_lines
                  .map(function (s) {
                    return (
                      "<tr><td>" +
                      esc(s.method || "Shipping") +
                      "</td><td>" +
                      (s.total_html || esc(String(s.total || 0))) +
                      "</td><td>" +
                      esc(String(s.total_tax != null ? s.total_tax : "0")) +
                      "</td></tr>"
                    );
                  })
                  .join("") +
                "</tbody></table></div>";
            }

            var calculationHtml =
              "<div class='ov-card ov-calc-card'>" +
              "<h3>Order calculation</h3>" +
              "<p class='ov-status-hint'>Breakdown of what was calculated on the store owner order (subtotal, tax, shipping, fees, and grand total).</p>" +
              "<dl class='ov-dl'>" +
              calcRows +
              "</dl>" +
              taxLinesHtml +
              feeLinesHtml +
              shippingLinesHtml +
              "</div>";

            // --- Vendor order details (Amazon / G&T) — full detail under status ---
            function vendorDetailSection(kind, title, payload) {
              if (!payload || !payload.found) {
                return (
                  "<div class='ov-card ov-vendor-detail is-empty'>" +
                  "<h3>" +
                  esc(title) +
                  "</h3>" +
                  "<p class='ov-muted'>No " +
                  esc(kind) +
                  " vendor order was placed for this requisition.</p>" +
                  "</div>"
                );
              }
              var rows = "";
              rows += dlRow("Vendor", esc(kind));
              rows += dlRow("Status", esc(payload.status || "—"));
              rows += dlRow(
                "Triggered by",
                payload.auto ? "Automatic submit" : "Manual / prior flow"
              );
              if (payload.source) rows += dlRow("Source", esc(payload.source));
              if (payload.po_id) rows += dlRow("PO / reference", esc(payload.po_id));
              if (payload.amazon_request_id) {
                rows += dlRow("Amazon request ID", esc(payload.amazon_request_id));
              }
              if (payload.sender) rows += dlRow("Sender", esc(payload.sender));
              if (payload.trial_mode) rows += dlRow("Mode", "Amazon trial mode");
              if (payload.stage_only) rows += dlRow("Environment", "Grand & Toy stage");
              if (payload.order_total != null && payload.order_total !== "") {
                rows += dlRow(
                  "Vendor order total",
                  esc(String(payload.order_total)) +
                    " " +
                    esc(payload.currency || "CAD")
                );
              }
              if (payload.submitted_at || payload.placed_at) {
                rows += dlRow(
                  "Submitted at",
                  esc(payload.submitted_at || payload.placed_at)
                );
              }
              if (payload.message) rows += dlRow("Vendor message", esc(payload.message));

              var ship = payload.shipping || payload.ship_to || null;
              if (ship && typeof ship === "object" && Object.keys(ship).length) {
                var shipBits = [];
                ["name", "deliver_to", "company", "address_1", "city", "state", "postal_code", "postcode", "country", "email", "phone"].forEach(function (k) {
                  if (ship[k]) shipBits.push(String(ship[k]));
                });
                if (Array.isArray(ship.streets) && ship.streets.length) {
                  shipBits = ship.streets.map(String).concat(shipBits);
                }
                if (shipBits.length) {
                  rows += dlRow("Ship to", esc(shipBits.join(", ")));
                }
              }

              var items =
                (Array.isArray(payload.cart_items) && payload.cart_items.length
                  ? payload.cart_items
                  : payload.intended_lines) || [];
              var itemsTable = "";
              if (items.length) {
                itemsTable =
                  "<div class='ov-table-wrap' style='margin-top:12px'>" +
                  "<table class='table ov-items-table'><thead><tr>" +
                  "<th>Item</th><th>Vendor ID</th><th>Qty</th><th>Unit price</th>" +
                  "</tr></thead><tbody>" +
                  items
                    .map(function (it) {
                      var name =
                        it.name ||
                        it.title ||
                        it.description ||
                        it.asin ||
                        it.supplier_part_id ||
                        "Item";
                      var vid =
                        it.vendor_id ||
                        it.asin ||
                        it.supplier_part_id ||
                        it.productIdentifier ||
                        "";
                      var qty = it.qty || it.quantity || 1;
                      var unit =
                        it.unit_price != null
                          ? it.unit_price
                          : it.price != null
                          ? it.price
                          : it.amazon_price != null
                          ? it.amazon_price
                          : "—";
                      return (
                        "<tr><td>" +
                        esc(name) +
                        "</td><td>" +
                        esc(vid) +
                        "</td><td>" +
                        esc(qty) +
                        "</td><td>" +
                        esc(unit) +
                        "</td></tr>"
                      );
                    })
                    .join("") +
                  "</tbody></table></div>";
              }

              return (
                "<div class='ov-card ov-vendor-detail'>" +
                "<h3>" +
                esc(title) +
                "</h3>" +
                "<dl class='ov-dl'>" +
                rows +
                "</dl>" +
                itemsTable +
                "</div>"
              );
            }

            var vendorDetailsHtml =
              "<div class='ov-vendor-details-block'>" +
              "<h3 class='ov-section-title'>Vendor order details</h3>" +
              "<p class='ov-status-hint'>Details of the order that was triggered to Amazon and/or Grand &amp; Toy for this store requisition.</p>" +
              "<div class='ov-vendor-details-grid'>" +
              vendorDetailSection("Amazon", "Amazon order details", amazon) +
              vendorDetailSection("Grand & Toy", "Grand & Toy order details", gt) +
              "</div>" +
              "</div>";

            // --- API response inspector ---
            var triggerPayload =
              data.trigger_payload ||
              {
                order_id: data.id || orderId,
                order_number: data.number || "",
                status: data.status_slug || data.status || "",
                selected_vendors: data.vendors || [],
                auto_submit: data.auto_submit_detail || data.auto_submit || null,
                amazon_order: amazon && amazon.found ? amazon : null,
                gt_order: gt && gt.found ? gt : null,
                cart_vendor_summary: data.cart_vendor_summary || null,
                totals: data.totals || null,
              };

            var apiJson = "";
            try {
              apiJson = JSON.stringify(triggerPayload, null, 2);
            } catch (e) {
              apiJson = String(triggerPayload);
            }

            var selectedVendorLabel =
              auto && auto.winning_vendor
                ? auto.winning_vendor === "gt"
                  ? "Grand & Toy"
                  : auto.winning_vendor === "amazon"
                  ? "Amazon"
                  : auto.winning_vendor === "mixed"
                  ? "Mixed (per line)"
                  : auto.winning_vendor
                : data.vendor_label || "—";

            var autoStatusLabel = autoStatus || (auto ? "recorded" : "not run");
            var autoPillClass =
              autoStatus === "submitted"
                ? "is-success"
                : autoStatus === "partial"
                ? "is-warn"
                : autoStatus === "failed"
                ? "is-danger"
                : autoStatus === "skipped" || autoStatusLabel === "not run"
                ? "is-muted"
                : "is-info";

            var amazonTriggered = !!(amazon && amazon.found);
            var gtTriggered = !!(gt && gt.found);

            var apiResponseHtml =
              "<div class='ov-card ov-api-response'>" +
              "<div class='ov-api-head'>" +
              "<div class='ov-api-head__text'>" +
              "<h3>API response</h3>" +
              "<p class='ov-api-lead'>Trigger summary for this requisition — selected vendor, what ran automatically, and the stored payload.</p>" +
              "</div>" +
              "<div class='ov-api-head__actions'>" +
              "<a class='btn btn-xs ov-api-btn' href='" +
              esc((WCSSM.rest || "/wp-json/wcss/v1/") + "orders/" + orderId) +
              "' target='_blank' rel='noopener'>Open full API</a>" +
              "</div>" +
              "</div>" +
              "<div class='ov-api-metrics'>" +
              "<div class='ov-api-metric'>" +
              "<span class='ov-api-metric__label'>Auto-submit</span>" +
              "<span class='ov-api-pill " +
              autoPillClass +
              "'>" +
              esc(autoStatusLabel) +
              "</span>" +
              "</div>" +
              "<div class='ov-api-metric'>" +
              "<span class='ov-api-metric__label'>Selected vendor</span>" +
              "<span class='ov-api-metric__value'>" +
              esc(selectedVendorLabel) +
              "</span>" +
              "</div>" +
              "<div class='ov-api-metric'>" +
              "<span class='ov-api-metric__label'>Amazon</span>" +
              "<span class='ov-api-pill " +
              (amazonTriggered ? "is-success" : "is-muted") +
              "'>" +
              (amazonTriggered
                ? "Triggered · " + esc(amazon.status || "recorded")
                : "Not triggered") +
              "</span>" +
              "</div>" +
              "<div class='ov-api-metric'>" +
              "<span class='ov-api-metric__label'>Grand &amp; Toy</span>" +
              "<span class='ov-api-pill " +
              (gtTriggered ? "is-success" : "is-muted") +
              "'>" +
              (gtTriggered
                ? "Triggered · " + esc(gt.status || "recorded")
                : "Not triggered") +
              "</span>" +
              "</div>" +
              "</div>" +
              (auto && auto.message
                ? "<div class='ov-api-note'><strong>Trigger detail</strong><span>" +
                  esc(auto.message) +
                  "</span></div>"
                : "") +
              "<div class='ov-api-panel'>" +
              "<div class='ov-api-panel__bar'>" +
              "<button type='button' class='ov-api-toggle' id='ov-toggle-api-json' aria-expanded='false'>" +
              "<span class='ov-api-toggle__chev' aria-hidden='true'></span>" +
              "<span class='ov-api-toggle__label'>Show JSON payload</span>" +
              "</button>" +
              "<button type='button' class='btn btn-xs ov-api-btn' id='ov-copy-api-json' hidden>Copy JSON</button>" +
              "</div>" +
              "<pre class='ov-api-json is-collapsed' id='ov-api-json' hidden>" +
              esc(apiJson) +
              "</pre>" +
              "</div>" +
              "</div>";

            return (
              vendorOrdersHtml +
              statusHtml +
              calculationHtml +
              vendorDetailsHtml +
              (WCSSM.is_admin ? apiResponseHtml : "")
            );
          })();

        // var html =
        //   "<div class='order-meta'>" +
        //   "<h2>Order #" +
        //   (data.number || orderId) +
        //   "</h2>" +
        //   "<p><strong>Date:</strong> " +
        //   (data.date || "") +
        //   "</p>" +
        //   "<p><strong>Status:</strong> " +
        //   (data.status || "") +
        //   "</p>" +
        //   "<p><strong>Total:</strong> " +
        //   (data.total_html || data.total || "") +
        //   "</p>" +
        //   "<p><strong>Customer:</strong> " +
        //   (data.customer || "") +
        //   "</p>" +
        //   storeLine +
        //   "</div>" +
        //   "<h3>Items</h3>" +
        //   "<table class='table'>" +
        //   "<thead><tr><th>Product</th><th>SKU</th><th>Qty</th><th>Total</th></tr></thead>" +
        //   "<tbody>" +
        //   (Array.isArray(data.items)
        //     ? data.items
        //         .map(function (i) {
        //           return (
        //             "<tr>" +
        //             "<td>" +
        //             (i.name || "") +
        //             "</td>" +
        //             "<td>" +
        //             (i.sku
        //               ? "<br><span class='muted sku'>SKU: " + i.sku + "</span>"
        //               : "") +
        //             "</td>" +
        //             "<td>" +
        //             (i.qty || 0) +
        //             "</td>" +
        //             "<td>" +
        //             (i.total || "") +
        //             "</td>" +
        //             "</tr>"
        //           );
        //         })
        //         .join("")
        //     : "<tr><td colspan='3'>No items</td></tr>") +
        //   "</tbody>" +
        //   "</table>" +
        //   ledgerBlock +
        //   "<h3>Update Status</h3>" +
        //   "<div class='actions'>" +
        //   "<button class='btn btn-success' id='order-approve'>Approve</button> " +
        //   "<button class='btn btn-danger' id='order-reject'>Reject</button>" +
        //   "</div>" +
        //   "<div id='order-msg'></div>";

        $wrap.html(html);
      })
      .catch(function () {
        $wrap.html("<div class='flash-error'>Failed to load order.</div>");
      });
  };
})(jQuery);

//dashboard and reporting
(function ($) {
  // ===== Dashboard =====
  (function ($) {
    // ===== Dashboard =====
    window.initDashboard = function () {
      var DAY = 864e5;
      var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
      var MONTHS_FULL = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
      var PENDING_STATUSES = "pending,awaiting-approval,on-hold";
      // Woo status slug → the three states managers act on.
      var STATUS = {
        pending: "pending", "awaiting-approval": "pending", "on-hold": "pending",
        approved: "approved", processing: "approved", completed: "approved",
        rejected: "rejected", cancelled: "rejected",
      };
      var LABEL = { pending: "Awaiting approval", approved: "Approved", rejected: "Rejected" };
      var PERIOD_LABEL = { d7: "Last 7 days", d30: "Last 30 days", month: "This month", m6: "Last 6 months", custom: "Custom range" };

      var api = WCSSM.rest || "/wp-json/wcss/v1/";
      var base = (WCSSM.manager_base || "/manager/").replace(/\/+$/, "/");
      var state = { period: "d30", from: "", to: "", report: null, orders: [], events: [], currency: "CAD" };

      function ymd(d) {
        return d.getFullYear() + "-" + ("0" + (d.getMonth() + 1)).slice(-2) + "-" + ("0" + d.getDate()).slice(-2);
      }
      function parseYmd(s) {
        if (!s) return null;
        var p = String(s).split("-");
        if (p.length !== 3) return null;
        var d = new Date(+p[0], +p[1] - 1, +p[2]);
        return isNaN(d.getTime()) ? null : d;
      }
      function shortDate(s) {
        var d = parseYmd(s);
        if (!d) return s || "";
        return MONTHS[d.getMonth()] + " " + d.getDate() + (d.getFullYear() === new Date().getFullYear() ? "" : ", " + d.getFullYear());
      }
      function periodLabel() {
        if (state.period !== "custom") return PERIOD_LABEL[state.period] || "";
        if (state.from && state.to) return shortDate(state.from) + " – " + shortDate(state.to);
        return "Custom range";
      }
      function ensureCustomDefaults() {
        if (state.from && state.to) return;
        var to = new Date();
        var from = new Date(Date.now() - 29 * DAY);
        state.from = ymd(from);
        state.to = ymd(to);
        $("#dash-from").val(state.from);
        $("#dash-to").val(state.to);
      }

      function esc(s) {
        return String(s == null ? "" : s).replace(
          /[&<>"]/g,
          (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c])
        );
      }

      // Vendor names may already contain &amp; from WP; decode once before esc.
      function plainText(s) {
        var el = document.createElement("textarea");
        el.innerHTML = String(s || "");
        return el.value;
      }

      function prefix() {
        return state.currency === "CAD" ? "CA$" : state.currency + " ";
      }
      function money(n) {
        return prefix() + Number(n || 0).toLocaleString("en-CA", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }
      function moneyShort(n) {
        return prefix() + Number(n || 0).toLocaleString("en-CA", { maximumFractionDigits: 0 });
      }
      function dt(s) {
        return new Date(String(s).replace(" ", "T"));
      }
      function fdt(s) {
        if (!s) return "";
        var d = dt(s);
        var year = d.getFullYear() === new Date().getFullYear() ? "" : ", " + d.getFullYear();
        return MONTHS[d.getMonth()] + " " + d.getDate() + year + ", " + String(s).slice(11, 16);
      }
      function nowHM() {
        var d = new Date();
        return ("0" + d.getHours()).slice(-2) + ":" + ("0" + d.getMinutes()).slice(-2);
      }

      var toastTimer;
      function toast(msg) {
        var $t = $("#ccm-toast");
        $t.find("span").text(msg);
        $t.prop("hidden", false);
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
          $t.prop("hidden", true);
        }, 2800);
      }

      function inRange(s, r, prev) {
        var t = dt(s).getTime();
        if (r === "custom") {
          var fromD = parseYmd(state.from);
          var toD = parseYmd(state.to);
          if (!fromD || !toD) return false;
          if (fromD > toD) {
            var swap = fromD;
            fromD = toD;
            toD = swap;
          }
          var start = fromD.getTime();
          var end = new Date(toD.getFullYear(), toD.getMonth(), toD.getDate(), 23, 59, 59, 999).getTime();
          if (!prev) return t >= start && t <= end;
          var len = end - start + 1;
          return t >= start - len && t < start;
        }
        if (r === "month") {
          var n = new Date();
          var startM = new Date(n.getFullYear(), n.getMonth(), 1).getTime();
          var prevStart = new Date(n.getFullYear(), n.getMonth() - 1, 1).getTime();
          return prev ? t >= prevStart && t < startM : t >= startM;
        }
        var now = Date.now(), days = { d7: 7, d30: 30, m6: 182 }[r];
        return prev ? t >= now - 2 * days * DAY && t < now - days * DAY : t >= now - days * DAY;
      }

      function norm(o) {
        var status = STATUS[o.status_slug] || "other";
        var vendor = o.vendor_label && o.vendor_label !== "—" ? plainText(o.vendor_label) : "";
        return {
          id: o.id,
          date: o.date || "",
          total: +o.total || 0,
          customer: plainText(o.customer || ""),
          vendor: vendor,
          status: status,
          statusLabel: LABEL[status] || plainText(o.status || ""),
          pillCls: "ccm-pill " + (LABEL[status] ? "p-" + status : "p-neutral"),
        };
      }

      function orderUrl(id) {
        return base + "orders/view/" + id;
      }

      /* ---------- render ---------- */

      function renderKpis() {
        var P = state.period, orders = state.orders;
        var cur = orders.filter((o) => inRange(o.date, P));
        var prev = orders.filter((o) => inRange(o.date, P, true));
        var sum = (arr, st) => arr.filter((o) => o.status === st).reduce((a, o) => a + o.total, 0);
        var cnt = (arr, st) => arr.filter((o) => o.status === st).length;
        var d = cur.length - prev.length;
        var lastMonth = MONTHS_FULL[(new Date().getMonth() + 11) % 12];
        var vsLabel = {
          d7: "vs previous 7 days",
          d30: "vs previous 30 days",
          month: "vs " + lastMonth,
          m6: "vs previous 6 months",
          custom: "vs previous equal period",
        }[P];
        var pending = (state.report && state.report.pending) || { count: 0, oldest: "" };
        var label = periodLabel();

        $("#k-orders").text(cur.length);
        $("#k-orders-delta")
          .attr("class", d > 0 ? "up" : d < 0 ? "down" : "flat")
          .text(d > 0 ? "▲ " + d : d < 0 ? "▼ " + Math.abs(d) : "No change");
        $("#k-vs").text(vsLabel);
        $("#k-pending").text(pending.count);
        $("#k-pending-sub").text(pending.count && pending.oldest ? "Oldest waiting since " + fdt(pending.oldest) : "You’re all caught up");
        $("#k-spend").text(money(sum(cur, "approved")));
        $("#k-spend-sub").text(label + " · " + cnt(cur, "approved") + " approved orders");
        $("#k-rejected").text(cnt(cur, "rejected"));
        $("#k-rejected-sub").text(label + " · " + money(sum(cur, "rejected")) + " declined");

        $("#dash-alert").prop("hidden", !pending.count);
        $("#dash-alert-t").text(
          pending.count === 1 ? "1 order is waiting for your approval" : pending.count + " orders are waiting for your approval"
        );
        var $badge = $(".ccm-navbtn .ccm-nbadge");
        if (pending.count) $badge.text(pending.count);
        else $badge.remove();
      }

      function renderChart() {
        var trend = (state.report && state.report.trend) || [];
        var months = trend.map((x) => [MONTHS[parseInt(String(x.ym).slice(5), 10) - 1] || x.ym, +x.orders || 0]);
        var max = Math.max.apply(null, months.map((m) => m[1]).concat([1]));
        $("#dash-chart")
          .attr("aria-label", "Orders per month: " + months.map((m) => m[0] + " " + m[1]).join(", "))
          .html(
            months
              .map(function (m, i) {
                var h = Math.max(3, Math.round((m[1] / max) * 170));
                var cls = i === months.length - 1 ? "ccm-bar cur" : "ccm-bar";
                return "<div class='ccm-col'><span class='ccm-colv'>" + m[1] + "</span><div class='" + cls + "' style='height:" + h + "px'></div></div>";
              })
              .join("")
          );
        $("#dash-xlabels").html(months.map((m) => "<div>" + esc(m[0]) + "</div>").join(""));
        $("#chart-sub").text("Last 6 months · " + MONTHS_FULL[new Date().getMonth()] + " is month-to-date");
      }

      function renderAttention() {
        // Administrator only — Supply Manager does not get this technical checklist.
        if (!WCSSM.is_admin || !$("#dash-attention").length) return;
        var r = state.report || {};
        var a = r.attention || {};
        var pendingCount = (r.pending && r.pending.count) || 0;
        var noVendorOrders = state.orders.filter((o) => !o.vendor && o.status !== "rejected").length;
        var noVendor = a.products_no_vendor || 0, zero = a.products_zero_price || 0;
        var items = [
          { n: pendingCount, label: "Orders awaiting approval", hint: "Stores are waiting on these", cta: "Review", sev: "high", url: base + "orders?status=" + PENDING_STATUSES },
          { n: noVendorOrders, label: "Orders with no vendor", hint: "Assign a vendor so they can be fulfilled", cta: "Assign", sev: "high", url: base + "orders" },
          { n: (r.store_summary && r.store_summary.issues) || 0, label: "Stores with data issues", hint: "Duplicate codes, test stores, mismatched users", cta: "Fix", sev: "med", url: base + "stores" },
          { n: noVendor + zero, label: "Products need fixing", hint: noVendor + " without a vendor · " + zero + " priced at " + money(0), cta: "Fix", sev: "med", url: base + "products" },
          { n: a.vendors_no_email || 0, label: "Vendors missing contact email", hint: "Orders can’t be sent to them automatically", cta: "Update", sev: "low", url: base + "vendorslist" },
          { n: a.gt_unsynced || 0, label: "Grand & Toy prices not synced", hint: "Price comparison is incomplete", cta: "Sync", sev: "low", url: (WCSSM.home || "/") + "gt-catalog/" },
        ].filter((x) => x.n > 0);

        $("#dash-attention").html(
          items.length
            ? items
                .map(function (x) {
                  return (
                    "<div class='ccm-attn'>" +
                    "<span class='ccm-attn-n sev-" + x.sev + "'>" + x.n + "</span>" +
                    "<div style='flex:1;min-width:0'><div class='ccm-strong'>" + esc(x.label) + "</div><div class='ccm-muted ccm-small'>" + esc(x.hint) + "</div></div>" +
                    "<a class='ccm-link' href='" + esc(x.url) + "'>" + esc(x.cta) + " →</a>" +
                    "</div>"
                  );
                })
                .join("")
            : "<div class='ccm-empty'><strong>All clear</strong>Nothing needs action right now.</div>"
        );
      }

      function renderRecent() {
        var rows = state.orders.slice(0, 5).map(function (o) {
          var act =
            o.status === "pending"
              ? "<span class='ccm-actions' style='justify-content:flex-end;flex-wrap:nowrap'>" +
                "<button type='button' class='ccm-btn ok sm dash-approve' data-id='" + o.id + "'>Approve</button>" +
                "<button type='button' class='ccm-btn danger sm dash-reject' data-id='" + o.id + "'>Reject</button></span>"
              : "<a class='ccm-btn sm' href='" + orderUrl(o.id) + "' data-ccm-order='" + o.id + "'>View</a>";
          return (
            "<tr>" +
            "<td><a class='ccm-idlink' href='" + orderUrl(o.id) + "' data-ccm-order='" + o.id + "'>#" + o.id + "</a><div class='ccm-muted ccm-small'>" + esc(fdt(o.date)) + "</div></td>" +
            "<td style='white-space:normal;min-width:180px;overflow-wrap:anywhere'>" + esc(o.customer) + "</td>" +
            "<td class='num'>" + money(o.total) + "</td>" +
            "<td><span class='" + o.pillCls + "'>" + esc(o.statusLabel) + "</span></td>" +
            "<td class='num'>" + act + "</td>" +
            "</tr>"
          );
        });
        $("#dash-recent").html(rows.join("") || "<tr><td colspan='5' class='ccm-empty'>No orders yet.</td></tr>");
      }

      function renderFeed() {
        var submitted = state.orders.slice(0, 7).map(function (o) {
          return { label: fdt(o.date), text: "Order #" + o.id + " submitted · " + money(o.total), who: o.customer, kind: "new" };
        });
        var feed = state.events.concat(submitted).slice(0, 7);
        $("#dash-feed").html(
          feed
            .map(function (e) {
              return (
                "<div class='ccm-ev'><span class='ccm-evdot ev-" + e.kind + "' aria-hidden='true'></span><div>" +
                "<div class='ccm-evt'>" + esc(e.text) + "</div><div class='ccm-evm'>" + esc(e.label) + " · " + esc(e.who) + "</div></div></div>"
              );
            })
            .join("") || "<div class='ccm-empty'><strong>No activity yet</strong>New orders will show up here.</div>"
        );
      }

      function renderVendors() {
        var vmap = {};
        state.orders
          .filter((o) => inRange(o.date, state.period) && o.status !== "rejected")
          .forEach(function (o) {
            var n = o.vendor || "Not assigned";
            vmap[n] = vmap[n] || { name: n, total: 0, count: 0 };
            vmap[n].total += o.total;
            vmap[n].count++;
          });
        var list = Object.keys(vmap).map((k) => vmap[k]).sort((a, b) => b.total - a.total);
        var vmax = list.length ? list[0].total || 1 : 1;
        $("#vendor-period").text(periodLabel());
        $("#dash-vendors").html(
          list
            .map(function (v) {
              var pct = Math.max(2, Math.round((v.total / vmax) * 100));
              return (
                "<div class='ccm-hbar'><div class='ccm-hbar-top'><span class='ccm-strong'>" + esc(v.name) + "</span>" +
                "<span style='font-variant-numeric:tabular-nums'>" + money(v.total) + " <span class='ccm-muted ccm-small'>· " + v.count + " orders</span></span></div>" +
                "<div class='ccm-progress'><div style='width:" + pct + "%'></div></div></div>"
              );
            })
            .join("") || "<div class='ccm-empty'><strong>No spend in this period</strong>Try a longer period above.</div>"
        );
      }

      function renderBudgets() {
        var r = state.report || {};
        var ss = r.store_summary || { budget_sum: 0, top: [] };
        $("#st-total").text((r.stores && r.stores.total) || 0);
        $("#st-active").text((r.stores && r.stores.active) || 0);
        $("#st-budget").text(moneyShort(ss.budget_sum));
        $("#dash-budgets").html(
          (ss.top || [])
            .map(function (s) {
              var pct = s.budget > 0 ? Math.min(100, Math.round((s.spend / s.budget) * 100)) : 0;
              return (
                "<div class='ccm-hbar'><div class='ccm-hbar-top'><span>" + esc(s.name) + (s.code ? " <span class='ccm-muted ccm-small'>#" + esc(s.code) + "</span>" : "") + "</span>" +
                "<span class='ccm-muted ccm-small' style='font-variant-numeric:tabular-nums'>" + moneyShort(s.spend) + " of " + moneyShort(s.budget) + "</span></div>" +
                "<div class='ccm-progress'><div style='width:" + pct + "%'></div></div></div>"
              );
            })
            .join("")
        );
      }

      function renderPeriod() {
        $(".ccm-seg [data-period]").each(function () {
          $(this).toggleClass("on", $(this).data("period") === state.period);
        });
        var custom = state.period === "custom";
        $("#dash-dates").prop("hidden", !custom);
        if (custom) {
          if (!$("#dash-from").val() && state.from) $("#dash-from").val(state.from);
          if (!$("#dash-to").val() && state.to) $("#dash-to").val(state.to);
        }
        renderKpis();
        renderVendors();
      }

      function renderAll() {
        renderPeriod();
        renderChart();
        renderAttention();
        renderRecent();
        renderFeed();
        renderBudgets();
        $(".ccm-updated").text(nowHM());
      }

      /* ---------- data ---------- */

      function fetchOrders() {
        // Default: a year back covers 6 months + prior period. Custom may reach further back.
        var from = new Date(Date.now() - 366 * DAY).toISOString().slice(0, 10);
        if (state.period === "custom" && state.from) {
          var customFrom = parseYmd(state.from);
          if (customFrom) {
            // Include an equal prior window for the KPI “vs previous” delta.
            var toD = parseYmd(state.to) || new Date();
            var lenDays = Math.max(1, Math.round((toD - customFrom) / DAY) + 1);
            var prior = new Date(customFrom.getTime() - lenDays * DAY);
            from = ymd(prior);
          }
        }
        var items = [];
        function page(n) {
          return $.ajax({
            url: api + "orders?" + $.param({ page: n, per_page: 100, date_from: from }),
            headers: { "X-WP-Nonce": WCSSM.nonce },
            dataType: "json",
          }).then(function (d) {
            items = items.concat(d.items || []);
            // total_pages from this endpoint isn't reliable; a full page means there may be more.
            if ((d.items || []).length === 100 && n < 8) return page(n + 1);
            return items;
          });
        }
        return page(1);
      }

      function load() {
        var report = $.ajax({
          url: api + "reports/overview",
          headers: { "X-WP-Nonce": WCSSM.nonce },
          dataType: "json",
        });
        return $.when(report, fetchOrders())
          .done(function (repRes, orders) {
            state.report = repRes[0];
            state.currency = (state.report.sales && state.report.sales.currency) || "CAD";
            state.orders = (orders || []).map(norm);
            renderAll();
          })
          .fail(function (x) {
            toast((x && x.responseJSON && x.responseJSON.message) || "Failed to load dashboard");
          });
      }

      function loadVendorSplitAndCoverage() {
        var month = new Date().toLocaleString("en-CA", { month: "long" });
        var split = $.ajax({
          url: api + "reports/run",
          data: { type: "vendors", range: "this_month" },
          headers: { "X-WP-Nonce": WCSSM.nonce },
          dataType: "json",
        }).done(function (d) {
          if (window.ccm && typeof window.ccm.vendorSplit === "function") {
            $("#dash-split").html(
              window.ccm.vendorSplit(d.summary, {
                title: "This month: Amazon vs Grand & Toy",
                sub: "Approved spend, " + String(d.period || "").replace(/^This month · /, ""),
                link: base + "reports?type=vendors&range=this_month",
                linkLabel: "Vendor report",
              })
            );
          }
        });
        var coverage = $.ajax({
          url: api + "reports/run",
          data: { type: "coverage", range: "this_month" },
          headers: { "X-WP-Nonce": WCSSM.nonce },
          dataType: "json",
        }).done(function (d) {
          var rows = d.rows || [];
          var yes = rows.filter(function (r) { return r.ordered === "Ordered"; });
          var no = rows.filter(function (r) { return r.ordered !== "Ordered"; });
          var pct = rows.length ? (yes.length / rows.length) * 100 : 0;
          function escHtml(s) {
            return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
              return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
            });
          }
          $("#dash-coverage").html(
            "<div class='ccm-cov-top'><div><span class='ccm-cov-n'>" + yes.length + "</span><span class='ccm-muted'> of " + rows.length + " stores ordered in " + escHtml(month) + "</span></div>" +
            "<span class='ccm-pill " + (pct >= 80 ? "p-approved" : pct >= 40 ? "p-pending" : "p-neutral") + "'>" + pct.toFixed(0) + "%</span></div>" +
            "<div class='ccm-vs-bar' style='margin:10px 0 16px'><i class='is-ok' style='width:" + pct + "%'></i></div>" +
            (no.length
              ? "<div class='ccm-card-s' style='margin-bottom:6px'>Not ordered yet</div><ul class='ccm-cov-list'>" + no.slice(0, 6).map(function (r) {
                  return "<li><span class='ccm-strong'>" + escHtml(r.store) + "</span><span class='ccm-muted ccm-small'>" + escHtml([r.code ? "#" + r.code : "", r.city].filter(Boolean).join(" · ")) + "</span></li>";
                }).join("") + "</ul>" + (no.length > 6 ? "<div class='ccm-muted ccm-small' style='margin-top:8px'>+ " + (no.length - 6) + " more stores</div>" : "")
              : "<div class='ccm-empty'><strong>Every store has ordered</strong>Nothing left for this month.</div>")
          );
        });
        return $.when(split, coverage);
      }

      function setStatus(id, status) {
        // Shared helper (ccm-screens.js) also handles the over-limit "Approve anyway" confirm.
        if (window.ccm && window.ccm.setOrderStatus) {
          window.ccm.setOrderStatus(id, status).done(function (txt) {
            state.events.unshift({ label: "Today, " + nowHM(), text: txt, who: $(".ccm-me .ccm-strong").first().text(), kind: status });
            load();
          });
          return;
        }
        $.ajax({
          url: api + "orders/" + id + "/status",
          method: "POST",
          headers: { "X-WP-Nonce": WCSSM.nonce, "Content-Type": "application/json" },
          data: JSON.stringify({ status: status }),
          dataType: "json",
        })
          .done(function () {
            var txt = status === "approved" ? "You approved order #" + id : "You rejected order #" + id;
            state.events.unshift({ label: "Today, " + nowHM(), text: txt, who: $(".ccm-me .ccm-strong").first().text(), kind: status });
            toast(txt);
            load();
          })
          .fail(function (x) {
            toast(
              (x.status === 409 ? "Order #" + id + " is over the store’s limits — open it to override. " : "") +
                ((x.responseJSON && x.responseJSON.message) || x.statusText)
            );
          });
      }

      /* ---------- events ---------- */

      var hour = new Date().getHours();
      $("#dash-greeting").text(hour < 12 ? "Good morning" : hour < 18 ? "Good afternoon" : "Good evening");

      $(".ccm-seg").on("click", "[data-period]", function () {
        var next = $(this).data("period");
        state.period = next;
        if (next === "custom") {
          ensureCustomDefaults();
          renderPeriod();
          return;
        }
        renderPeriod();
      });

      $("#dash-dates-apply").on("click", function () {
        var from = $("#dash-from").val();
        var to = $("#dash-to").val();
        if (!from || !to) {
          toast("Choose both a From and To date");
          return;
        }
        if (from > to) {
          var tmp = from;
          from = to;
          to = tmp;
          $("#dash-from").val(from);
          $("#dash-to").val(to);
        }
        state.period = "custom";
        state.from = from;
        state.to = to;
        renderPeriod();
        // Reload orders so older custom ranges are covered.
        load();
        toast("Showing " + periodLabel());
      });

      $("#dash-from, #dash-to").on("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          $("#dash-dates-apply").trigger("click");
        }
      });

      $("#dash-recent")
        .on("click", ".dash-approve", function () {
          setStatus($(this).data("id"), "approved");
        })
        .on("click", ".dash-reject", function () {
          setStatus($(this).data("id"), "rejected");
        });

      // Topbar Refresh — dashboard only (button is not rendered on other tabs).
      window.ccmRefresh = function () {
        return $.when(load(), loadVendorSplitAndCoverage()).done(function () {
          toast("Dashboard refreshed");
        });
      };
      // Silent reload after a change made in the order drawer.
      window.ccmReload = load;

      $("#dash-export-csv")
        .off("click")
        .on("click", function () {
          const ym = WCSSM.month
            ? "month=" + encodeURIComponent(WCSSM.month) + "&"
            : "";
          const url =
            (WCSSM.rest || "/wp-json/wcss/v1/") +
            "reports/overview.csv?" +
            ym +
            "_dl=1&_wpnonce=" +
            encodeURIComponent(WCSSM.nonce);
          window.location = url;
        });

      $("#dash-export-pdf")
        .off("click")
        .on("click", function () {
          const ym = WCSSM.month
            ? "month=" + encodeURIComponent(WCSSM.month) + "&"
            : "";
          const url =
            (WCSSM.rest || "/wp-json/wcss/v1/") +
            "reports/overview.pdf?" +
            ym +
            "_dl=1&_wpnonce=" +
            encodeURIComponent(WCSSM.nonce);
          window.location = url;
        });

      load();
    };
  })(jQuery);
})(jQuery);

// users crud..

(function ($) {
  // ===== Users page =====
  window.initUsersList = function () {
    var $grid = $("#wcssm-users-grid");
    var $flash = $("#wcssm-flash");
    var storesCache = []; // [{id,name}]
    var usersCache = []; // from API

    function flash(msg, ok) {
      $flash
        .removeClass("is-ok is-err")
        .addClass(ok ? "is-ok" : "is-err")
        .text(msg)
        .stop(true, true)
        .fadeIn(120);
      setTimeout(function () {
        $flash.fadeOut(180);
      }, 2200);
    }

    function escapeHtml(s) {
      return (s || "").toString().replace(/[&<>"]/g, function (c) {
        return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
      });
    }

    function render() {
      if (!usersCache.length) {
        $grid.html("<p class='muted'>No users found.</p>");
        return;
      }
      var rows = usersCache
        .map(function (u) {
          var storeLabel = u.store_name
            ? escapeHtml(u.store_name)
            : u.store_id
            ? "#" + u.store_id
            : "<span class='muted'> Not Assigned</span>";
          return (
            "<div class='row'>" +
            "<div id='store-id'>#" +
            u.id +
            "</div>" +
            "<div id='user-name'>" +
            escapeHtml(u.name || "User #" + u.id) +
            "</div>" +
            "<div id='store-email'>" +
            escapeHtml(u.email || "") +
            "</div>" +
            "<div id='store-name'>" +
            (storeLabel && storeLabel.trim() !== "-"
              ? storeLabel
              : "Not Assigned") +
            "</div>" +
            "</div>"
          );
        })
        .join("");
      $grid.html(rows);
    }

    function loadUsers() {
      $grid.html("Loading…");
      return $.ajax({
        url: (WCSSM.rest || "/wp-json/wcss/v1/") + "users?all=1",
        headers: { "X-WP-Nonce": WCSSM.nonce },
        dataType: "json",
      })
        .done(function (list) {
          usersCache = Array.isArray(list) ? list : [];
          render();
        })
        .fail(function (x) {
          $grid.html("Error: " + (x.responseJSON?.message || x.statusText));
        });
    }

    function loadStores() {
      // Use your existing stores endpoint (returns items: [{id,name,...}])
      return $.ajax({
        url: (WCSSM.rest || "/wp-json/wcss/v1/") + "stores",
        headers: { "X-WP-Nonce": WCSSM.nonce },
        dataType: "json",
      }).done(function (d) {
        storesCache =
          d && d.items
            ? d.items.map(function (s) {
                return { id: s.id, name: s.name };
              })
            : [];
      });
    }

    // ----- Create User modal -----
    $("#u-new")
      .off("click")
      .on("click", function () {
        $("#um-first,#um-last,#um-email").val("");
        $("#user-modal").fadeIn(120);
      });
    $("#um-cancel")
      .off("click")
      .on("click", function () {
        $("#user-modal").fadeOut(120);
      });
    $("#um-save")
      .off("click")
      .on("click", function () {
        var first = $("#um-first").val().trim();
        var last = $("#um-last").val().trim();
        var email = $("#um-email").val().trim();
        if (!first) {
          alert("First name is required.");
          return;
        }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          alert("Valid email is required.");
          return;
        }
        $("#um-save").prop("disabled", true).text("Creating…");
        $.ajax({
          url: (WCSSM.rest || "/wp-json/wcss/v1/") + "users",
          method: "POST",
          headers: {
            "X-WP-Nonce": WCSSM.nonce,
            "Content-Type": "application/json",
          },
          data: JSON.stringify({
            first_name: first,
            last_name: last,
            email: email,
          }),
        })
          .done(function (u) {
            flash("User created.", true);
            $("#user-modal").fadeOut(120);
            loadUsers();
          })
          .fail(function (x) {
            alert(
              x.responseJSON?.message ||
                x.statusText ||
                "Failed to create user."
            );
          })
          .always(function () {
            $("#um-save").prop("disabled", false).text("Create");
          });
      });

    // ----- Assign modal -----
    // var assignUserId = 0;
    // $grid.on("click", ".u-assign", function () {
    //   assignUserId = parseInt($(this).data("id"), 10) || 0;
    //   if (!assignUserId) return;

    //   // ensure stores ready
    //   $.when(storesCache.length ? $.Deferred().resolve() : loadStores()).done(
    //     function () {
    //       // build select
    //       var $sel = $("#am-store").empty();
    //       $sel.append($("<option>").val("").text("— Select a store —"));
    //       storesCache.forEach(function (s) {
    //         $sel.append(
    //           $("<option>")
    //             .val(String(s.id))
    //             .text(s.name || "#" + s.id)
    //         );
    //       });

    //       var u =
    //         usersCache.find(function (x) {
    //           return x.id === assignUserId;
    //         }) || {};
    //       $("#am-userline").text(
    //         (u.name || "User #" + assignUserId) +
    //           (u.email ? " — " + u.email : "")
    //       );
    //       if (u.store_id) $("#am-store").val(String(u.store_id));
    //       $("#assign-modal").fadeIn(120);
    //     }
    //   );
    // });
    // $("#am-cancel")
    //   .off("click")
    //   .on("click", function () {
    //     $("#assign-modal").fadeOut(120);
    //   });
    // $("#am-save")
    //   .off("click")
    //   .on("click", function () {
    //     var val = $("#am-store").val();
    //     var storeId = val ? parseInt(val, 10) : 0;

    //     $("#am-save").prop("disabled", true).text("Saving…");
    //     $.ajax({
    //       url:
    //         (WCSSM.rest || "/wp-json/wcss/v1/") +
    //         "users/" +
    //         assignUserId +
    //         "/store",
    //       method: "POST",
    //       headers: {
    //         "X-WP-Nonce": WCSSM.nonce,
    //         "Content-Type": "application/json",
    //       },
    //       data: JSON.stringify({ store_id: storeId }),
    //     })
    //       .done(function () {
    //         $("#assign-modal").fadeOut(120);
    //         flash("Assignment saved.", true);
    //         loadUsers();
    //       })
    //       .fail(function (x) {
    //         alert(
    //           x.responseJSON?.message ||
    //             x.statusText ||
    //             "Failed to assign store."
    //         );
    //       })
    //       .always(function () {
    //         $("#am-save").prop("disabled", false).text("Save");
    //       });
    //   });

    // // Unassign
    // $grid.on("click", ".u-unassign", function () {
    //   var uid = parseInt($(this).data("id"), 10) || 0;
    //   if (!uid) return;
    //   if (!confirm("Unassign this user from their store?")) return;
    //   $.ajax({
    //     url: (WCSSM.rest || "/wp-json/wcss/v1/") + "users/" + uid + "/store",
    //     method: "POST",
    //     headers: {
    //       "X-WP-Nonce": WCSSM.nonce,
    //       "Content-Type": "application/json",
    //     },
    //     data: JSON.stringify({ store_id: 0 }),
    //   })
    //     .done(function () {
    //       flash("User unassigned.", true);
    //       loadUsers();
    //     })
    //     .fail(function (x) {
    //       alert(
    //         x.responseJSON?.message || x.statusText || "Failed to unassign."
    //       );
    //     });
    // });

    // $("#u-refresh")
    //   .off("click")
    //   .on("click", function () {
    //     loadUsers();
    //   });

    // ----- Assign modal -----
    var assignUserId = 0;
    $grid.on("click", ".u-assign", function () {
      assignUserId = parseInt($(this).data("id"), 10) || 0;
      if (!assignUserId) return;

      // ensure stores ready
      $.when(storesCache.length ? $.Deferred().resolve() : loadStores()).done(
        function () {
          const $sel = $("#am-store").empty();
          const $existingFilter = $("#am-store-filter");

          // add search input if not already there
          if (!$existingFilter.length) {
            $("<input>", {
              id: "am-store-filter",
              class: "input",
              placeholder: "Search stores…",
              style: "margin-bottom:8px;",
            }).insertBefore("#am-store");
          }

          // build select
          $sel.append($("<option>").val("").text("— Select a store —"));

          storesCache.forEach(function (s) {
            let label =
              (s.name || "#" + s.id) +
              (s.code ? " — " + s.code : "") +
              (s.city ? " — " + s.city : "");

            // if the store is assigned to someone else, show and disable
            if (s.user && s.user_id && s.user_id !== assignUserId) {
              label += " (assigned to " + s.user + ")";
            }

            const opt = $("<option>").val(String(s.id)).text(label);

            if (s.user_id && s.user_id !== assignUserId) {
              opt.prop("disabled", true);
            }

            $sel.append(opt);
          });

          // find current user details
          const u =
            usersCache.find(function (x) {
              return x.id === assignUserId;
            }) || {};

          $("#am-userline").text(
            (u.name || "User #" + assignUserId) +
              (u.email ? " — " + u.email : "")
          );

          if (u.store_id) $sel.val(String(u.store_id));

          // simple search filter logic
          $("#am-store-filter")
            .off("input")
            .on("input", function () {
              const q = $(this).val().toLowerCase();
              $sel.find("option").each(function () {
                if (!this.value) return; // skip placeholder
                const txt = $(this).text().toLowerCase();
                $(this).toggle(txt.indexOf(q) !== -1);
              });
            });

          $("#assign-modal").fadeIn(120);
        }
      );
    });

    $("#am-cancel")
      .off("click")
      .on("click", function () {
        $("#assign-modal").fadeOut(120);
      });

    $("#am-save")
      .off("click")
      .on("click", function () {
        var val = $("#am-store").val();
        var storeId = val ? parseInt(val, 10) : 0;

        if (!storeId) {
          alert("Please select a store before saving.");
          return;
        }

        $("#am-save").prop("disabled", true).text("Saving…");

        // ✅ Assign user to the selected store (this is your supported route)
        $.ajax({
          url: (WCSSM.rest || "/wp-json/wcss/v1/") + "stores/" + storeId,
          method: "PUT",
          headers: {
            "X-WP-Nonce": WCSSM.nonce,
            "Content-Type": "application/json",
          },
          data: JSON.stringify({ user_id: assignUserId }),
        })
          .done(function () {
            $("#assign-modal").fadeOut(120);
            flash("Assignment saved.", true);
            loadUsers(); // refresh the users grid to reflect store column
          })
          .fail(function (x) {
            alert(
              x.responseJSON?.message ||
                x.statusText ||
                "Failed to assign store."
            );
          })
          .always(function () {
            $("#am-save").prop("disabled", false).text("Save");
          });
      });

    // $("#am-save")
    //   .off("click")
    //   .on("click", function () {
    //     const val = $("#am-store").val();
    //     const storeId = val ? parseInt(val, 10) : 0;

    //     if (!storeId) {
    //       alert("Please select a store before saving.");
    //       return;
    //     }

    //     $("#am-save").prop("disabled", true).text("Saving…");
    //     $.ajax({
    //       url: (WCSSM.rest || "/wp-json/wcss/v1/") + "stores/" + storeId,
    //       // url:
    //       //   (WCSSM.rest || "/wp-json/wcss/v1/") +
    //       //   "users/" +
    //       //   assignUserId +
    //       //   "/store",
    //       method: "POST",
    //       headers: {
    //         "X-WP-Nonce": WCSSM.nonce,
    //         "Content-Type": "application/json",
    //       },
    //       data: JSON.stringify({ store_id: storeId }),
    //     })
    //       .done(function () {
    //         $("#assign-modal").fadeOut(120);
    //         flash("Assignment saved.", true);
    //         loadUsers();
    //       })
    //       .fail(function (x) {
    //         alert(
    //           x.responseJSON?.message ||
    //             x.statusText ||
    //             "Failed to assign store."
    //         );
    //       })
    //       .always(function () {
    //         $("#am-save").prop("disabled", false).text("Save");
    //       });
    //   });

    // initial load
    $.when(loadStores()).always(loadUsers);
  };
})(jQuery);

// vendor crud
(function ($) {
  window.initVendorsList = function () {
    var $grid = $("#wcssm-vendors-grid");
    var $flash = $("#wcssm-flash");

    function flash(msg, ok) {
      $flash
        .removeClass("is-ok is-err")
        .addClass(ok ? "is-ok" : "is-err")
        .text(msg)
        .stop(true, true)
        .fadeIn(120);

      setTimeout(function () {
        $flash.fadeOut(180);
      }, 2200);
    }

    function plainText(s) {
      var el = document.createElement("textarea");
      el.innerHTML = String(s || "");
      return el.value;
    }

    function escapeHtml(s) {
      return plainText(s).replace(/[&<>"]/g, function (c) {
        return {
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
        }[c];
      });
    }

    function render(list) {
      if (!list.length) {
        $grid.html("<p class='muted'>No vendors found.</p>");
        return;
      }

      var rows = list
        .map(function (v) {
          return `
            <tr 
              data-id="${v.id}"
              data-name="${escapeHtml(v.name || "")}"
              data-email="${escapeHtml(v.email || "")}"
              data-phone="${escapeHtml(v.phone || "")}"
              data-address="${escapeHtml(v.address || "")}"
            >
              <td>#${v.id}</td>
              <td>${escapeHtml(v.name || "")}</td>
              <td>${escapeHtml(v.email || "")}</td>
              <td>${escapeHtml(v.phone || "")}</td>
              <td>${escapeHtml(v.address || "")}</td>
              <td>
                <button class="btn btn-sm v-edit" data-id="${v.id}">Edit</button>

                <div id="vendor-modal-${v.id}" class="wcssm-modal" hidden>
                  <div class="wcssm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="vendor-modal-title">
                    <button type="button" class="wcssm-modal__close vendor-modal-close" data-id="${v.id}" id="vendor-modal-close" aria-label="Close">×</button>
                    <h3 id="vendor-modal-title">Edit Vendor</h3>

                    <div class="grid-2">
                      <div>
                        <label>Vendor name *</label>
                        <input id="v-name-${v.id}" class="input" value="${escapeHtml(v.name || "")}" required>
                      </div>
                      <div>
                        <label>Email</label>
                        <input id="v-email-${v.id}" class="input" value="${escapeHtml(v.email || "")}" type="email" placeholder="name@example.com">
                      </div>
                    </div>

                    <div class="grid-2">
                      <div>
                        <label>Phone</label>
                        <input id="v-phone-${v.id}" class="input" value="${escapeHtml(v.phone || "")}" placeholder="+1 555 123 4567">
                      </div>
                      <div>
                        <label>Address</label>
                        <input id="v-address-${v.id}" class="input" value="${escapeHtml(v.address || "")}" placeholder="Street, City, State">
                      </div>
                    </div>


                    <div class="actions">
                      <button type="button" class="btn btn-primary vendor-update" data-id="${v.id}" id="vendor-update">Update vendor</button>
                      <span id="vendor-msg" class="muted"></span>
                    </div>
                  </div>
                  <div class="wcssm-modal__backdrop"></div>
                </div>
                <button class="btn btn-sm v-delete" data-id="${v.id}">Delete</button>
              </td>
            </tr>
          `;
        })
        .join("");

      $grid.html(`
        <table class="wcssm-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Email</th>
              <th>Phone</th>
              <th>Address</th>
              <th style="width:160px;">Actions</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      `);
    }

    function loadVendors() {
      $grid.html("Loading…");

      return $.ajax({
        url: (WCSSM.rest || "/wp-json/wcss/v1/") + "vendors",
        headers: { "X-WP-Nonce": WCSSM.nonce },
        dataType: "json",
      })
        .done(function (res) {
          var list = Array.isArray(res)
            ? res
            : (res && res.items) ? res.items : [];

          render(list);
        })
        .fail(function (x) {
          $grid.html(
            "<p class='error'>Error: " +
              (x.responseJSON?.message || x.statusText) +
            "</p>"
          );
        });
    }

    // 🔄 Refresh
    $("#o-refresh")
      .off("click")
      .on("click", function () {
        loadVendors();
        flash("Refreshing vendors...", true);
      });

    // 🚀 Initial load
    loadVendors();

    // =========================
    // MODAL LOGIC
    // =========================

    // Open create modal
    $("#v-create-vendor")
      .off("click")
      .on("click", function () {
        $("#vendor-modal").removeData("id");
        $("#v-name,#v-email,#v-phone,#v-address").val("");
        $("#vendor-modal-title").text("Create Vendor");
        $("#vendor-modal").prop("hidden", false);
      });

    // Close modal
    $("#vendor-modal-close, .wcssm-modal__backdrop")
      .off("click")
      .on("click", function () {
        $("#vendor-modal").prop("hidden", true);
      });

      // close edit modal
    $(document).on("click",".vendor-modal-close", function () {
        var id = $(this).data("id");
        if (id) {
          $("#vendor-modal-" + id).prop("hidden", true);
        }
    });

    // Vendor edit modul open
    $(document).on("click", ".v-edit", function () {
      var id = $(this).data("id");
      $("#vendor-modal-" + id).prop("hidden", false);
    });

    // vendor updaate
    $(document).on("click", ".vendor-update", function () {
      var id = $(this).data("id");
      var name = $("#v-name-" + id).val().trim();
      var email = $("#v-email-" + id).val().trim();
      var phone = $("#v-phone-" + id).val().trim();
      var address = $("#v-address-" + id).val().trim();

      if (!name) {
        alert("Vendor name is required.");
        return;
      }

      $.ajax({
        url: (WCSSM.rest || "/wp-json/wcss/v1/") + "vendors/" + id,
        method: "PUT",
        headers: {
          "X-WP-Nonce": WCSSM.nonce,
          "Content-Type": "application/json",
        },
        data: JSON.stringify({
          id: id,
          name: name,
          email: email,
          phone: phone,
          address: address,
        }),
      })
        .done(function () {
          flash("Vendor updated.", true);
          $("#vendor-modal-" + id).prop("hidden", true);
          loadVendors();
        })
        .fail(function (x) {
          alert(
            x.responseJSON?.message ||
              x.statusText ||
              "Failed to update vendor."
          );
        });
    });

    // vendor delete
    $(document).on("click", ".v-delete", function () {
      var id = $(this).data("id");
      var name = $(this).closest("tr").data("name") || "ID " + id;
      if (!confirm("Are you sure you want to delete this vendor '" + name + "'?")) return;

      $.ajax({
        url: (WCSSM.rest || "/wp-json/wcss/v1/") + "vendors/" + id,
        method: "DELETE",
        headers: {
          "X-WP-Nonce": WCSSM.nonce,
        },
      })
        .done(function () {
          flash("Vendor deleted.", true);
          loadVendors();
        })
        .fail(function (x) {
          alert(
            x.responseJSON?.message ||
              x.statusText ||
              "Failed to delete vendor."
          );
        });
    });
  };
})(jQuery);

// ===== Manager shell: topbar search + refresh (every /manager page) =====
(function ($) {
  $(function () {
    var $q = $("#ccm-gq");
    if (!$q.length) return;
    var $res = $("#ccm-gresults");
    var api = WCSSM.rest || "/wp-json/wcss/v1/";
    var base = (WCSSM.manager_base || "/manager/").replace(/\/+$/, "/");
    var timer, seq = 0;

    function esc(s) {
      return String(s == null ? "" : s).replace(
        /[&<>"]/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c])
      );
    }

    function get(path, params) {
      return $.ajax({
        url: api + path + "?" + $.param(params),
        headers: { "X-WP-Nonce": WCSSM.nonce },
        dataType: "json",
      }).then(
        (d) => (d && d.items) || [],
        () => $.Deferred().resolve([])
      );
    }

    function show(results) {
      $res
        .html(
          results
            .slice(0, 7)
            .map(function (r) {
              return (
                "<a class='ccm-gres' href='" + esc(r.url) + "'><span><span class='ccm-strong'>" + esc(r.label) +
                "</span> <span class='ccm-muted ccm-small'>" + esc(r.meta) + "</span></span><span class='ccm-pill p-neutral'>" + r.kind + "</span></a>"
              );
            })
            .join("") || "<div class='ccm-empty' style='padding:16px'>No matches</div>"
        )
        .prop("hidden", false);
    }

    function search(q) {
      var mine = ++seq;
      var orders = /^#?\d+$/.test(q)
        ? [{ label: "Order #" + q.replace("#", ""), meta: "Open order", kind: "Order", url: base + "orders/view/" + q.replace("#", "") }]
        : [];
      $.when(get("products", { search: q, per_page: 5 }), get("stores", { search: q, per_page: 5 })).done(function (products, stores) {
        if (mine !== seq) return;
        show(
          orders
            .concat(products.map((p) => ({ label: p.name, meta: p.sku ? "SKU " + p.sku : "", kind: "Product", url: base + "products/edit/" + p.id })))
            .concat(stores.map((s) => ({ label: s.name, meta: [s.city, s.code ? "code " + s.code : ""].filter(Boolean).join(" · "), kind: "Store", url: base + "stores/edit/" + s.id })))
        );
      });
    }

    $q.on("input", function () {
      var q = $.trim($q.val());
      clearTimeout(timer);
      if (q.length < 2) {
        seq++;
        $res.prop("hidden", true);
        return;
      }
      timer = setTimeout(function () {
        search(q);
      }, 250);
    }).on("keydown", function (e) {
      if (e.key === "Escape") $res.prop("hidden", true);
    });

    $(document).on("click", function (e) {
      if (!$(e.target).closest(".ccm-gsearch").length) $res.prop("hidden", true);
    });

    $("#ccm-refresh").on("click", function () {
      var $btn = $(this);
      if ($btn.data("busy") || typeof window.ccmRefresh !== "function") return;
      $btn.data("busy", true).prop("disabled", true).attr("aria-busy", "true");
      $.when(window.ccmRefresh()).always(function () {
        $btn.data("busy", false).prop("disabled", false).removeAttr("aria-busy");
      });
    });
  });
})(jQuery);
