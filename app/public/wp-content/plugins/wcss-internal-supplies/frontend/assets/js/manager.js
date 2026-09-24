//  starting product CRUD JS
(function ($) {
  // one media frame per page to avoid duplicate handlers
  var _mediaFrame = null;

  function managerBase() {
    return (window.WCSSM && (WCSSM.home || WCSSM.manager_base)) || "/manager/";
  }

  /* Helpers */

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
              if (url) $prev.append('<img src="' + url + '" alt="">');
            });

            $("#p-images-ids").val(ids.join(","));
          });
        }
        _mediaFrame.open();
      });
  }

  function collectPayload() {
    const catIds = ($("#p-categories").val() || []).map((v) => parseInt(v, 10));
    const vendorIds = ($("#p-vendors").val() || []).map((v) => parseInt(v, 10));

    const imageIds = ($("#p-images-ids").val() || "")
      .toString()
      .split(",")
      .map((v) => parseInt(v, 10) || null)
      .filter(Boolean);

    return {
      name: $("#p-name").val(),
      sku: $("#p-sku").val(),
      price: $("#p-price").val(),
      short_description: $("#p-short").val(),
      category_ids: catIds,
      // keep these keys – your REST accepts both "vendor" (name) and "vendor_ids"
      brand: $("#p-brand").val(),
      vendor: $("#p-vendor").val(),
      vendor_ids: vendorIds,
      images: imageIds,
      manage_stock: $("#p-manage-stock").is(":checked"),
      stock: parseInt($("#p-stock").val() || 0, 10),
      stock_status: $("#p-stock-status").val(),
      status: $("#p-status").val(),
    };
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
            // add & select
            $("<option>")
              .val(t.id)
              .text(t.name)
              .prop("selected", true)
              .appendTo("#p-vendors");
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
      const $cats = $("#p-categories").empty();
      (meta.categories || []).forEach((c) =>
        $("<option>").val(c.id).html(c.name).appendTo($cats)
      );

      const $vendors = $("#p-vendors").empty();
      (meta.vendors || []).forEach((v) =>
        $("<option>").val(v.id).text(v.name).appendTo($vendors)
      );

      return meta;
    });
  }

  //update initiate product list
  window.initProductsList = function () {
    const $grid = $("#wcssm-products-grid");
    const $search = $("#p-search");
    const $refresh = $("#p-refresh");

    // let state = { page: 1, per_page: 20, search: "" };
    const urlParams = new URLSearchParams(window.location.search);
    let state = {
      page:
        parseInt(urlParams.get("page"), 10) > 0
          ? parseInt(urlParams.get("page"), 10)
          : 1,
      per_page: 20,
      total_pages: 1,
      total: 0,
      search: urlParams.get("search") || "",
    };

    function render(items, meta) {
      const head = `
      <div class="row head">
        <div>ID</div><div>Name</div><div>SKU</div><div>Vendor</div><div>Price</div><div>Actions</div>
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
          const editUrl =
            p.id + (qsParts.length ? "?" + qsParts.join("&") : "");

          return `
      <div class="row">
        <div>#${p.id}</div>
        <div>${(p.name || "").replace(/</g, "&lt;")}</div>
        <div>${(p.sku || "").replace(/</g, "&lt;")}</div>
        <div>${(p.vendor && p.vendor.name ? p.vendor.name : "").replace(
          /</g,
          "&lt;"
        )}</div>
        <div>${p.price_html || p.price || ""}</div>
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

          const qs = $.param({ search: state.search || "" });

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
      const qs = $.param({
        page: state.page,
        per_page: state.per_page,
        search: state.search,
      });
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

      const qs = params.toString();
      const newUrl = window.location.pathname + (qs ? "?" + qs : "");

      window.history.replaceState({}, "", newUrl);
    }

    // search hooks (optional)
    if ($refresh.length)
      $refresh.on("click", function () {
        state.page = 1;
        state.search = $search.val() || "";
        syncQueryWithState();
        load();

        // state.page = 1;
        // state.search = $search.val() || "";
        // load();
      });

    if ($search.length)
      $search.on("keypress", function (e) {
        if (e.which === 13) {
          state.page = 1;
          state.search = $search.val() || "";
          syncQueryWithState();
          load();
        }
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
          if (!payload.price) {
            $msg.text("Please enter product price.");
            return;
          }

          busy = true;
          $msg.text("Creating…");

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
              $msg.text("Created #" + p.id + " — " + (p.name || ""));
              // redirect to products list
              setTimeout(function () {
                window.location.href = managerBase() + "products";
              }, 600);
            })
            .fail(function (x) {
              $("#p-msg").text(
                "Error: " + (x.responseJSON?.message || x.statusText)
              );
            })
            .always(function () {
              busy = false;
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
        $("#p-categories option").each(function () {
          const v = parseInt($(this).val(), 10);
          $(this).prop("selected", p.category_ids.includes(v));
        });
      }

      // Vendors (multi)
      if (Array.isArray(p.vendor_id)) {
        $("#p-vendors option").each(function () {
          const v = parseInt($(this).val(), 10);
          $(this).prop("selected", p.vendor_id.includes(v));
        });
      }

      // Images: set hidden field + render thumbs via wp.media attachment fetch
      const ids = Array.isArray(p.images)
        ? p.images.map(Number).filter(Boolean)
        : [];
      $("#p-images-ids").val(ids.join(","));
      const $prev = $("#p-images-preview").empty();

      if (window.wp && wp.media && ids.length) {
        ids.forEach(function (attId) {
          const att = wp.media.attachment(attId);
          att.fetch().then(function () {
            const url =
              att.get("sizes")?.thumbnail?.url || att.get("url") || null;
            if (url) $prev.append('<img src="' + url + '" alt="">');
          });
        });
      }
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
        toast("Saving…", true);
        const payload = collectPayload();

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
          .done(function () {
            toast("✅ Saved!", true);
            const params = new URLSearchParams(window.location.search);
            const backParts = [];
            const pg = params.get("page");
            const srch = params.get("search");

            if (pg) backParts.push("page=" + encodeURIComponent(pg));
            if (srch) backParts.push("search=" + encodeURIComponent(srch));

            let backUrl = (WCSSM.manager_base || "/manager/") + "products";
            if (backParts.length) {
              backUrl += "?" + backParts.join("&");
            }

            setTimeout(function () {
              // window.location.href = managerBase() + "products"
              window.location.href = backUrl;
            }, 500);
          })
          .fail(function (x) {
            toast("Error: " + (x.responseJSON?.message || x.statusText), false);
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

        const $sel = $("#p-vendors");
        if ($sel.find(`option[value='${v.id}']`).length === 0) {
          $("<option>").val(v.id).text(v.name).appendTo($sel);
        }
        $sel.find(`option[value='${v.id}']`).prop("selected", true);

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

  // STORES – Create
  window.initStoreCreate = function () {
    const $msg = $("#s-msg");
    const $btn = $("#s-save");

    function toast(t, ok = true) {
      $msg
        .removeClass("err ok")
        .addClass(ok ? "ok" : "err")
        .text(t)
        .show();
    }
    function val(id) {
      return ($("#" + id).val() || "").trim();
    }

    // populate dropdown with store_employee users
    $.ajax({
      url: WCSSM.rest + "users",
      headers: { "X-WP-Nonce": WCSSM.nonce },
      dataType: "json",
    }).done(function (list) {
      var $u = $("#s-user").empty();
      $u.append($("<option>").val("").text("Select a Store Employee"));
      (list || []).forEach(function (u) {
        $u.append(
          $("<option>")
            .val(u.id)
            .text(u.name + " — " + (u.email || ""))
        );
      });
    });

    $btn.off("click").on("click", function () {
      console.log("btn clicked");

      const payload = {
        name: $("#s-name").val(),
        code: $("#s-code").val(),
        city: $("#s-city").val(),
        state: $("#s-state").val(),
        address: $("#s-address").val(),
        phone: $("#s-phone").val(),
        open_hours: $("#s-hours").val(),
        quota: parseInt($("#s-quota").val(), 10) || 0,
        budget: parseFloat($("#s-budget").val()) || 0,
        user_id: parseInt($("#s-user").val(), 10) || 0,
      };

      console.log("step 1");
      // Required fields (match server-side)
      if (!payload.name) return toast("Name is required", false);
      if (!payload.code) return toast("Code is required", false);
      if (!payload.quota) return toast("Quota is required", false);
      if (!payload.budget) return toast("Budget is required", false);
      if (!payload.user_id) return toast("Select a Store Employee.", false);
      if (!payload.address) return toast("Address is required", false);
      if (!payload.phone) return toast("Phone number is required", false);
      if (!payload.open_hours) return toast("Open hours are required", false);

      // If you also want these required on the front end:
      // if (!payload.city)  return toast("City is required", false);
      // if (!payload.state) return toast("State/Province is required", false);

      // Numeric validation when present
      if (
        payload.quota !== "" &&
        (!/^\d+$/.test(payload.quota) || parseInt(payload.quota, 10) < 0)
      ) {
        return toast("Quota must be a non-negative integer.", false);
      }
      if (
        payload.budget !== "" &&
        (isNaN(payload.budget) || parseFloat(payload.budget) < 0)
      ) {
        return toast("Budget must be a non-negative number.", false);
      }

      console.log("step 3");

      toast("Saving…");
      $btn.prop("disabled", true);

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
        .done(function () {
          toast("Created! Redirecting…", true);
          setTimeout(function () {
            window.location = (WCSSM.home || "/manager/") + "stores";
          }, 800);
        })
        .fail(function (x) {
          const msg =
            x.responseJSON?.message || x.statusText || "Request failed";
          toast(msg, false);
        })
        .always(function () {
          $btn.prop("disabled", false);
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
      status: "",
      from: "",
      to: "",
    };

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

    function render(items) {
      const head = `
        <div class="row head">
          <div>#</div>
          <div>Date</div>
          <div>Status</div>
          <div>Total</div>
          <div>Customer</div>
          <div>EDD</div>
          <div>Ref</div>
          <div>Actions</div>
        </div>`;

      const rows = (items || [])
        .map((o) => {
          const act = [];
          if (canApprove(o.status_slug)) {
            act.push(
              `<button class="btn btn-xs o-approve" data-id="${o.id}">Approve</button>`
            );
          }
          if (canReject(o.status_slug)) {
            act.push(
              `<button class="btn btn-xs btn-danger o-reject" data-id="${o.id}">Reject</button>`
            );
          }
          act.push(
            `<button class="btn btn-xs o-view" data-id="${o.id}">View</button>`
          );
          act.push(
            `<button class="btn btn-xs o-amazon-order" data-id="${o.id}">Amazon Test Order</button>`
          );
          act.push(
            `<button class="btn btn-xs o-amazon-view" data-id="${o.id}" ${
              o.has_amazon_order ? "" : 'title="No Amazon details saved yet — open after cart/checkout"'
            }>Amazon order view</button>`
          );

          return `
          <div class="row">
            <div>${escapeHtml(o.number || "#" + o.id)}</div>
            <div>${escapeHtml(o.date || "")}</div>
            <div><span class="badge status-${escapeHtml(
              o.status_slug
            )}">${escapeHtml(o.status)}</span></div>
            <div>${o.total || ""}</div>
            <div>${escapeHtml(o.customer || "")}</div>
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

    $grid.on("click", ".o-amazon-order", function(){
      const id = parseInt($(this).data("id"), 10);
       window.location.href = "/amazon-catalog/?order-id=" + id;
    });

    $grid.on("click", ".o-amazon-view", function () {
      const id = parseInt($(this).data("id"), 10);
      const base = (WCSSM.manager_base || "/manager/").replace(/\/+$/, "/");
      window.location.href = base + "orders/amazon-view/" + id;
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
            "<h3>Monthly Usage</h3>" +
            "<div class='ov-ledger'>" +
            "<p><strong>Month:</strong> " +
            (l.month || "") +
            "</p>" +
            "<p><strong>Orders:</strong> " +
            (l.used_orders || 0) +
            " / " +
            (l.quota || "∞") +
            "</p>" +
            "<p><strong>Spend:</strong> " +
            fmt(l.used_amount || 0) +
            " / " +
            (l.budget ? fmt(l.budget) : "∞") +
            "</p>" +
            "<p><strong>Remaining Orders:</strong> " +
            (l.quota ? Math.max(0, l.quota - (l.used_orders || 0)) : "∞") +
            "</p>" +
            "<p><strong>Remaining Budget:</strong> " +
            (l.budget
              ? fmt(Math.max(0, (l.budget || 0) - (l.used_amount || 0)))
              : "∞") +
            "</p>" +
            "</div>";
        }

        var storeLine = "";
        if (data && data.store && (data.store.name || data.store.id)) {
          storeLine =
            "<div class='store-details'>" +
            "<p><strong>Store:</strong> " +
            (data.store.name || null) +
            "<br>" +
            "<p><strong>Store ID:</strong> " +
            (data.store.id || null) +
            "<br>" +
            "<p><strong>Store Code:</strong> " +
            (data.store.code || null) +
            "<br>" +
            "<p><strong>Store City:</strong> " +
            (data.store.city || null) +
            "<br>" +
            "</p>" +
            "</div>";
        }

        var html =
          "<div class='order-store-wrap'>" +
          "<div class='order-meta'>" +
          "<h2>Order #" +
          (data.number || orderId) +
          "</h2>" +
          "<p><strong>Date:</strong> " +
          (data.date || "") +
          "</p>" +
          "<p><strong>Status:</strong> " +
          (data.status || "") +
          "</p>" +
          "<p><strong>Total:</strong> " +
          (data.total_html || data.total || "") +
          "</p>" +
          "<p><strong>Customer:</strong> " +
          (data.customer || "") +
          "</p>" +
          "</div>" +
          storeLine +
          "</div>" +
          "<h3>Items</h3>" +
          "<table class='table'>" +
          "<thead><tr><th>Product</th><th>SKU</th><th>Qty</th><th>Total</th></tr></thead>" +
          "<tbody>" +
          (Array.isArray(data.items)
            ? data.items
                .map(function (i) {
                  return (
                    "<tr>" +
                    "<td>" +
                    (i.name || "") +
                    "</td>" +
                    "<td>" +
                    (i.sku || "") +
                    "</td>" +
                    "<td>" +
                    (i.qty || 0) +
                    "</td>" +
                    "<td>" +
                    (i.total || "") +
                    "</td>" +
                    "</tr>"
                  );
                })
                .join("")
            : "<tr><td colspan='3'>No items</td></tr>") +
          "</tbody>" +
          "</table>" +
          ledgerBlock +
          "<h3>Update Status</h3>" +
          "<div class='actions'>" +
          "<button class='btn btn-success' id='order-approve'>Approve</button> " +
          "<button class='btn btn-danger' id='order-reject'>Reject</button>" +
          "</div>" +
          "<div id='order-msg'></div>";

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

        jQuery("#order-approve").on("click", function () {
          updateStatus("approved");
        });
        jQuery("#order-reject").on("click", function () {
          updateStatus("rejected");
        });

        function updateStatus(newStatus, opts) {
          opts = opts || {};
          var payload = { status: newStatus };
          if (opts.override) payload.override = 1;
          if (opts.note) payload.note = opts.note;

          fetch(
            (WCSSM.rest || "/wp-json/wcss/v1/") +
              "orders/" +
              orderId +
              "/status",
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
              // Handle 409 (limit exceeded) specially
              if (r.status === 409)
                return r.json().then(function (j) {
                  j.__status = 409;
                  return j;
                });
              return r.json();
            })
            .then(function (res) {
              // Limit exceeded → show confirm with details, allow override
              if (res && res.__status === 409 && res.data) {
                var d = res.data;
                var money = function (n) {
                  try {
                    return new Intl.NumberFormat(undefined, {
                      style: "currency",
                      currency: d.currency,
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
                  jQuery("#order-msg").html(
                    "<div class='flash-error'>Approval cancelled.</div>"
                  );
                }
                return;
              }

              if (res && res.ok) {
                jQuery("#order-msg").html(
                  "<div class='flash-success'>Status updated to " +
                    newStatus +
                    "!</div>"
                );
                // go back to orders list after a short pause
                setTimeout(function () {
                  window.location.href =
                    (WCSSM.manager_base || "/manager/") + "orders";
                }, 500);
              } else {
                jQuery("#order-msg").html(
                  "<div class='flash-error'>" +
                    (res && res.message ? res.message : "Failed") +
                    "</div>"
                );
              }
            })
            .catch(function (e) {
              jQuery("#order-msg").html(
                "<div class='flash-error'>Request failed</div>"
              );
            });
        }
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
        }, 1800);
      }

      function money(n, ccy) {
        try {
          return new Intl.NumberFormat(undefined, {
            style: "currency",
            currency: ccy || "USD",
          }).format(+n || 0);
        } catch (e) {
          return (ccy || "") + " " + (+n || 0).toFixed(2);
        }
      }

      function esc(s) {
        return String(s || "").replace(
          /[&<>"]/g,
          (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c])
        );
      }

      function render(d) {
        // ---- cards
        var pendingAll =
          (d.counts?.pending || 0) + (d.counts?.["awaiting-approval"] || 0);
        $("#m-pending").text(pendingAll);
        $("#m-approved").text(d.counts?.approved || 0);
        $("#m-rejected").text(d.counts?.rejected || 0);
        $("#m-revenue").text(money(d.sales?.revenue, d.sales?.currency));

        // ---- stores
        $("#st-total").text(d.stores?.total || 0);
        $("#st-active").text(d.stores?.active || 0);

        // ---- vendors table (tbody of #v-table)
        var vRows = (Array.isArray(d.vendors) ? d.vendors : [])
          .map(function (v) {
            return (
              "<tr>" +
              "<td>" +
              esc(v.name || "Vendor #" + (v.id || "")) +
              "</td>" +
              "<td>" +
              (v.orders || 0) +
              "</td>" +
              "<td>" +
              money(v.revenue || 0, d.sales?.currency) +
              "</td>" +
              "</tr>"
            );
          })
          .join("");
        $("#v-table tbody").html(
          vRows ||
            "<tr><td colspan='3' class='muted'>No vendor activity.</td></tr>"
        );

        // ---- trend bars (container #trend, simple CSS bars)
        // var max = Math.max.apply(
        //   null,
        //   (d.trend || [])
        //     .map(function (x) {
        //       return x.orders || 0;
        //     })
        //     .concat([1])
        // );
        // var bars = (d.trend || [])
        //   .map(function (x) {
        //     var pct = Math.round(((x.orders || 0) / max) * 100);
        //     return (
        //       "<div class='bar'>" +
        //       "<span style='height:" +
        //       pct +
        //       "%'></span>" +
        //       "<em>" +
        //       esc(x.ym) +
        //       "</em>" +
        //       "</div>"
        //     );
        //   })
        //   .join("");
        // $("#trend").html(bars || "<p class='muted'>No recent orders.</p>");

        // trend bars
        var trend = Array.isArray(d.trend) ? d.trend : [];
        var max = Math.max.apply(
          null,
          trend
            .map(function (x) {
              return +x.orders || 0;
            })
            .concat([1])
        );

        var bars = trend
          .map(function (x) {
            var orders = +x.orders || 0;
            var pct = max > 0 ? Math.round((orders / max) * 100) : 0;
            return (
              "<div class='bar'>" +
              "<i class='val'>Orders: " +
              orders +
              "</i>" +
              "<span style='--h:" +
              pct +
              "%;height:" +
              pct +
              "%'>" +
              "</span>" +
              "<em>" +
              (x.ym || "") +
              "</em>" +
              "</div>"
            );
          })
          .join("");

        $("#trend").html(
          bars || "<p class='muted'>No orders in recent months.</p>"
        );

        // ---- ledger (tbody of #ledger-tbl)
        // JSON sample had: { store_id, store, orders, spend, quota?, budget? }

        var lrows = Array.isArray(d.ledger) ? d.ledger : [];

        var lrows = (d.ledger || [])
          .map(function (r) {
            console.log(r);
            return (
              "<tr><td>" +
              (r.store || "" + (r.store || "")) +
              "</td>" +
              "<td>" +
              (r.store || "#" + (r.store_id || "")) +
              "</td>" +
              "<td>" +
              (r.orders || 0) +
              "</td>" +
              "<td>" +
              (typeof r.quota !== "undefined" ? r.quota : "∞") +
              "</td>" +
              "<td>" +
              money(r.spend || 0, d.sales?.currency) +
              "</td>" +
              "<td>" +
              (typeof r.budget !== "undefined"
                ? money(r.budget, d.sales?.currency)
                : "∞") +
              "</td></tr>"
            );
          })
          .join("");

        // var lrows = (Array.isArray(d.ledger) ? d.ledger : [])
        //   .map(function (r) {
        //     return (
        //       "<tr>" +
        //       "<td>" +
        //       esc(r.store || "#" + (r.store_id || "")) +
        //       "</td>" +
        //       "<td>" +
        //       (r.orders || 0) +
        //       "</td>" +
        //       "<td>" +
        //       (typeof r.quota !== "undefined" ? r.quota : "∞") +
        //       "</td>" +
        //       "<td>" +
        //       money(r.spend || 0, d.sales?.currency) +
        //       "</td>" +
        //       "<td>" +
        //       (typeof r.budget !== "undefined"
        //         ? money(r.budget, d.sales?.currency)
        //         : "∞") +
        //       "</td>" +
        //       "</tr>"
        //     );
        //   })
        //   .join("");

        $("#ledger-tbl tbody").html(
          lrows ||
            "<tr><td colspan='5' class='muted'>No store activity.</td></tr>"
        );
      }

      function load() {
        $.ajax({
          url: (WCSSM.rest || "/wp-json/wcss/v1/") + "reports/overview",
          headers: { "X-WP-Nonce": WCSSM.nonce },
          dataType: "json",
        })
          .done(function (d) {
            render(d);
          })
          .fail(function (x) {
            flash(
              x.responseJSON?.message ||
                x.statusText ||
                "Failed to load dashboard",
              false
            );
          });
      }

      $("#dash-refresh").off("click").on("click", load);

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

    function escapeHtml(s) {
      return (s || "").toString().replace(/[&<>"]/g, function (c) {
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
