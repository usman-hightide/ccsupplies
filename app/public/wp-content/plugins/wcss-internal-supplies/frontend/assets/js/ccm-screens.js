/**
 * Manager console screens (design/dashboard-design-reference.html):
 * Orders, Products, Vendors, Stores, Users, Amazon catalog, Grand & Toy,
 * plus the shared order drawer, add/edit modal, confirm dialog and toast.
 * Each page template sets data-ccm-screen on its wrapper.
 */
(function ($) {
  "use strict";

  var api = WCSSM.rest || "/wp-json/wcss/v1/";
  var base = (WCSSM.manager_base || "/manager/").replace(/\/+$/, "/");
  var home = (WCSSM.home || "/").replace(/\/+$/, "/");
  var DAY = 864e5;
  var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  var PENDING_STATUSES = "pending,awaiting-approval,on-hold";
  // Woo status slug → the three states managers act on.
  var STATUS = {
    pending: "pending", "awaiting-approval": "pending", "on-hold": "pending",
    approved: "approved", processing: "approved", completed: "approved",
    rejected: "rejected", cancelled: "rejected",
  };
  var LABEL = { pending: "Awaiting approval", approved: "Approved", rejected: "Rejected" };

  /* ---------- helpers ---------- */

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  // Term / order names may arrive HTML-encoded (e.g. "Grand &amp; Toy").
  function plainText(s) {
    var el = document.createElement("textarea");
    el.innerHTML = String(s == null ? "" : s);
    return el.value;
  }
  function num(x) {
    return x === null || x === undefined || x === "" ? null : Number(x);
  }
  function money(n) {
    return "CA$" + Number(n || 0).toLocaleString("en-CA", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function moneyShort(n) {
    return "CA$" + Number(n || 0).toLocaleString("en-CA", { maximumFractionDigits: 0 });
  }
  function dt(s) {
    return new Date(String(s).replace(" ", "T"));
  }
  function fd(s) {
    var d = dt(s);
    return MONTHS[d.getMonth()] + " " + d.getDate() + ", " + d.getFullYear();
  }
  function ft(s) {
    return String(s).slice(11, 16);
  }
  function fdt(s) {
    if (!s) return "";
    var d = dt(s);
    var year = d.getFullYear() === new Date().getFullYear() ? "" : ", " + d.getFullYear();
    return MONTHS[d.getMonth()] + " " + d.getDate() + year + ", " + ft(s);
  }
  function nowHM() {
    var d = new Date();
    return ("0" + d.getHours()).slice(-2) + ":" + ("0" + d.getMinutes()).slice(-2);
  }
  function initials(n) {
    var w = String(n).replace(/[^A-Za-z0-9 ]/g, " ").trim().split(/\s+/);
    return ((w[0] || "?")[0] + ((w[1] || "")[0] || "")).toUpperCase();
  }
  function monthStart() {
    var n = new Date();
    return new Date(n.getFullYear(), n.getMonth(), 1);
  }
  function ymd(d) {
    return d.getFullYear() + "-" + ("0" + (d.getMonth() + 1)).slice(-2) + "-" + ("0" + d.getDate()).slice(-2);
  }
  function inRange(s, r) {
    if (r === "all") return true;
    var t = dt(s).getTime();
    if (r === "month") return t >= monthStart().getTime();
    return t >= Date.now() - { d7: 7, d30: 30 }[r] * DAY;
  }
  function errMsg(x, fallback) {
    return (x && x.responseJSON && x.responseJSON.message) || (x && x.statusText) || fallback;
  }

  function req(method, path, data) {
    return $.ajax({
      url: api + path,
      method: method,
      headers: { "X-WP-Nonce": WCSSM.nonce },
      contentType: data ? "application/json" : undefined,
      data: data ? JSON.stringify(data) : undefined,
      dataType: "json",
    });
  }
  function get(path, params) {
    return $.ajax({
      url: api + path + (params ? "?" + $.param(params) : ""),
      headers: { "X-WP-Nonce": WCSSM.nonce },
      dataType: "json",
    });
  }
  // Page through a list endpoint ({items}); stops on a short page.
  function getAll(path, params, maxPages) {
    var items = [];
    var per = params.per_page || 100;
    function page(n) {
      return get(path, $.extend({}, params, { page: n, per_page: per })).then(function (d) {
        var got = (d && d.items) || [];
        items = items.concat(got);
        return got.length === per && n < (maxPages || 10) ? page(n + 1) : items;
      });
    }
    return page(1);
  }

  function normOrder(o) {
    var status = STATUS[o.status_slug] || "other";
    var vendor = o.vendor_label && o.vendor_label !== "—" ? plainText(o.vendor_label) : "";
    return {
      id: o.id,
      date: o.date || "",
      total: +o.total || 0,
      customer: plainText(o.customer || ""),
      vendor: vendor,
      vendors: o.vendors || [],
      hasAmazon: !!o.has_amazon_order,
      storeId: o.store && o.store.id ? +o.store.id : 0,
      status: status,
      statusLabel: LABEL[status] || plainText(o.status || ""),
      pillCls: "ccm-pill " + (LABEL[status] ? "p-" + status : "p-neutral"),
    };
  }
  function normProduct(p) {
    return {
      id: p.id,
      name: plainText(p.name),
      sku: p.sku || "",
      vendor: p.vendor && p.vendor.name ? plainText(p.vendor.name) : "",
      woo: num(p.price),
      amz: num(p.amazon_price),
      gt: num(p.gt_price),
    };
  }
  function loadOrders(params) {
    return getAll("orders", $.extend({ per_page: 100 }, params || {}), 10).then(function (items) {
      return items.map(normOrder);
    });
  }
  function loadProducts(params) {
    return getAll("products", $.extend({ per_page: 100 }, params || {}), 20).then(function (items) {
      return items.map(normProduct);
    });
  }
  function loadStores() {
    return getAll("stores", { per_page: 100 }, 10);
  }
  function loadVendors() {
    return get("vendors").then(function (d) {
      return ((d && d.items) || d || []).map(function (v) {
        return {
          id: v.id, name: plainText(v.name), email: v.email || "", phone: v.phone || "", address: v.address || "",
          contact: v.contact || "", website: v.website || "", account_no: v.account_no || "", notes: v.notes || "",
          budgets: v.budgets || {}, budget_default: v.budget_default == null ? null : +v.budget_default, feed: v.feed || null,
        };
      });
    });
  }

  function csv(filename, header, rows) {
    var cell = function (v) {
      var s = String(v == null ? "" : v);
      return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    };
    var text = [header].concat(rows).map(function (r) { return r.map(cell).join(","); }).join("\n");
    var a = document.createElement("a");
    a.href = URL.createObjectURL(new Blob(["﻿" + text], { type: "text/csv;charset=utf-8" }));
    a.download = filename + "-" + ymd(new Date()) + ".csv";
    document.body.appendChild(a);
    a.click();
    a.remove();
    toast("CSV export started — check your downloads");
  }

  function setOptions($sel, values, keepFirst) {
    $sel.find("option").slice(keepFirst).remove();
    values.forEach(function (v) {
      $sel.append($("<option>").val(v).text(v));
    });
  }

  /* ---------- toast / overlays ---------- */

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

  var lastFocus = null;
  function openLayer(id) {
    lastFocus = document.activeElement;
    var $l = $("#" + id).prop("hidden", false);
    setTimeout(function () {
      $l.find("input,select,button.ccm-btn").filter(":visible").first().trigger("focus");
    }, 0);
  }
  function closeLayer(id) {
    $("#" + id).prop("hidden", true);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  $(document).on("click", "[data-ccm-close]", function () {
    closeLayer($(this).closest("#ccm-drawer,#ccm-modal,#ccm-confirm,#ccm-info").attr("id"));
  });
  $(document).on("keydown", "#ccm-drawer,#ccm-modal,#ccm-confirm,#ccm-info", function (e) {
    if (e.key === "Escape") closeLayer(this.id);
  });

  var confirmOk = null;
  function confirmDialog(opts) {
    $("#cf-title").text(opts.title);
    $("#cf-sub").text(opts.sub || "This removes it for everyone and can’t be undone.");
    $("#cf-ok").text(opts.ok || "Delete").prop("disabled", false);
    confirmOk = opts.onOk;
    openLayer("ccm-confirm");
  }
  $(document).on("click", "#cf-ok", function () {
    var $b = $(this).prop("disabled", true);
    $.when(confirmOk && confirmOk()).always(function () {
      $b.prop("disabled", false);
      closeLayer("ccm-confirm");
    });
  });

  var modalSave = null;
  /**
   * fields: [{k, l, type, req, full, ph, options:[{v,l}]}]
   * onSave(values) must return a promise; a rejection message is shown in the modal.
   */
  function formModal(opts) {
    $("#md-title").text(opts.title);
    $("#md-sub").text(opts.sub || "");
    $("#md-save").text(opts.saveLabel).prop("disabled", false);
    $("#md-err").prop("hidden", true).text("");
    var vals = opts.values || {};
    $("#md-fields").html(
      opts.fields
        .map(function (f) {
          var v = vals[f.k] == null ? "" : String(vals[f.k]);
          var control = f.options
            ? "<select class='ccm-select' name='" + f.k + "'>" +
              f.options.map(function (o) {
                return "<option value='" + esc(o.v) + "'" + (String(o.v) === v ? " selected" : "") + ">" + esc(o.l) + "</option>";
              }).join("") + "</select>"
            : f.type === "textarea"
            ? "<textarea class='ccm-input ccm-textarea' name='" + f.k + "' rows='3' placeholder='" + esc(f.ph || "") + "'>" + esc(v) + "</textarea>"
            : "<input class='ccm-input' name='" + f.k + "' type='" + (f.type || "text") + "'" + (f.type === "number" ? " min='0' step='0.01'" : "") + " placeholder='" + esc(f.ph || "") + "' value='" + esc(v) + "'>";
          return "<label class='ccm-field'" + (f.full ? " style='grid-column: 1 / -1'" : "") + ">" + esc(f.l + (f.req ? " *" : "")) + control + "</label>";
        })
        .join("")
    );
    modalSave = function () {
      var out = {};
      opts.fields.forEach(function (f) {
        out[f.k] = $.trim($("#md-fields [name='" + f.k + "']").val() || "");
      });
      for (var i = 0; i < opts.fields.length; i++) {
        if (opts.fields[i].req && !out[opts.fields[i].k]) return $.Deferred().reject(opts.fields[i].l + " is required.");
      }
      return opts.onSave(out);
    };
    openLayer("ccm-modal");
  }
  $(document).on("submit", "#ccm-modal form", function (e) {
    e.preventDefault();
    if (!modalSave) return;
    var $b = $("#md-save").prop("disabled", true);
    $.when(modalSave())
      .done(function () {
        closeLayer("ccm-modal");
      })
      .fail(function (m) {
        $("#md-err").text(typeof m === "string" ? m : errMsg(m, "Couldn’t save.")).prop("hidden", false);
      })
      .always(function () {
        $b.prop("disabled", false);
      });
  });
  $(document).on("input change", "#md-fields", function () {
    $("#md-err").prop("hidden", true);
  });

  /* ---------- order status + drawer ---------- */

  function setOrderStatus(id, status, override) {
    return req("POST", "orders/" + id + "/status", { status: status, override: override ? 1 : 0 }).then(
      function () {
        var txt =
          status === "approved" ? "You approved order #" + id
          : status === "rejected" ? "You rejected order #" + id
          : "Order #" + id + " moved back to pending";
        toast(txt);
        return txt;
      },
      function (x) {
        if (x.status === 409 && status === "approved") {
          var d = $.Deferred();
          confirmDialog({
            title: "Approve over the store’s limit?",
            sub: errMsg(x, "This order goes over the store’s monthly quota or budget."),
            ok: "Approve anyway",
            onOk: function () {
              return setOrderStatus(id, status, true).then(d.resolve, d.reject);
            },
          });
          return d.promise();
        }
        toast(errMsg(x, "Couldn’t update order #" + id));
        return $.Deferred().reject(x).promise();
      }
    );
  }

  var drawerOrder = null;
  var drawerChanged = null;
  var EVENT_TEXT = {
    status_change: function (p) {
      var s = STATUS[p.new] || "";
      return {
        text: s === "approved" ? "Approved" : s === "rejected" ? "Rejected" : "Moved back to pending",
        kind: s === "approved" ? "approved" : s === "rejected" ? "rejected" : "edit",
        note: p.note,
      };
    },
    note: function (p) { return { text: "Note added", kind: "edit", note: p.note }; },
    edit: function () { return { text: "Order details edited", kind: "edit" }; },
    shipment_update: function () { return { text: "Shipment updated", kind: "edit" }; },
    receiving_update: function () { return { text: "Receiving updated", kind: "edit" }; },
  };

  function renderDrawer(o) {
    var n = normOrder(o);
    $("#dr-id").text("#" + n.id);
    $("#dr-status").attr("class", n.pillCls).text(n.statusLabel);
    $("#dr-placed").text(n.date ? fd(n.date) + " at " + ft(n.date) : "—");
    $("#dr-customer").text(n.customer || "—");
    $("#dr-total").text(money(n.total));
    $("#dr-edd").text(o.edd || "Set once the vendor confirms").toggleClass("ccm-muted", !o.edd);
    $("#dr-ref").text(o.ref || "Not yet issued").toggleClass("ccm-muted", !o.ref);
    $("#dr-vendor").text(n.vendor || "Not assigned");
    $("#dr-full").attr("href", base + "orders/view/" + n.id);

    var hist = [{ text: "Submitted by " + (n.customer || "store"), label: fdt(n.date), kind: "new" }];
    (o.timeline || []).slice().reverse().forEach(function (ev) {
      var f = EVENT_TEXT[ev.type];
      if (!f) return;
      var h = f(ev.payload || {});
      hist.push({ text: h.text + (h.note ? " — " + h.note : ""), label: fdt(ev.created_at), kind: h.kind });
    });
    if (hist.length === 1 && n.status !== "pending" && LABEL[n.status]) hist.push({ text: n.statusLabel, label: "Earlier", kind: n.status });
    $("#dr-history").html(
      hist.map(function (h) {
        return "<div class='ccm-ev'><span class='ccm-evdot ev-" + h.kind + "' aria-hidden='true'></span><div><div class='ccm-evt'>" + esc(h.text) + "</div><div class='ccm-evm'>" + esc(h.label) + "</div></div></div>";
      }).join("")
    );

    $("#dr-foot").html(
      n.status === "pending"
        ? "<button type='button' class='ccm-btn danger' data-dr='rejected'>Reject</button><button type='button' class='ccm-btn ok' data-dr='approved'>Approve order</button>"
        : "<button type='button' class='ccm-btn' data-dr='awaiting-approval'>Move back to pending</button><button type='button' class='ccm-btn primary' data-ccm-close>Done</button>"
    );
  }

  function openOrder(id, onChange) {
    drawerChanged = onChange || null;
    $("#dr-id").text("#" + id);
    $("#dr-history,#dr-foot").empty();
    openLayer("ccm-drawer");
    get("orders/" + id)
      .done(function (o) {
        drawerOrder = o;
        renderDrawer(o);
      })
      .fail(function (x) {
        toast(errMsg(x, "Couldn’t load order #" + id));
        closeLayer("ccm-drawer");
      });
  }

  $(document).on("click", "#dr-foot [data-dr]", function () {
    if (!drawerOrder) return;
    var id = drawerOrder.id, $b = $("#dr-foot button").prop("disabled", true);
    setOrderStatus(id, $(this).data("dr"))
      .done(function () {
        if (drawerChanged) drawerChanged();
        get("orders/" + id).done(function (o) {
          drawerOrder = o;
          renderDrawer(o);
        });
      })
      .always(function () {
        $b.prop("disabled", false);
      });
  });

  // Any [data-ccm-order] link opens the drawer instead of the full page.
  $(document).on("click", "[data-ccm-order]", function (e) {
    if (!$("#ccm-drawer").length) return;
    e.preventDefault();
    openOrder($(this).data("ccmOrder"), window.ccmReload || null);
  });

  window.ccm = { toast: toast, openOrder: openOrder, setOrderStatus: setOrderStatus };

  /* ======================= screens ======================= */

  var screens = {};

  /* ---------- Orders ---------- */
  screens.orders = function () {
    var S = { orders: [], status: "all", q: "", vendor: "all", range: "all" };
    var want = new URLSearchParams(location.search).get("status") || "";
    if (/awaiting|pending|on-hold/.test(want)) S.status = "pending";
    else if (LABEL[STATUS[want]]) S.status = STATUS[want];

    function base0() {
      var q = S.q.toLowerCase();
      return S.orders.filter(function (o) {
        if (S.vendor === "none" && o.vendor) return false;
        if (S.vendor !== "all" && S.vendor !== "none" && o.vendor !== S.vendor) return false;
        if (!inRange(o.date, S.range)) return false;
        if (q && (String(o.id) + " " + o.customer).toLowerCase().indexOf(q) < 0) return false;
        return true;
      });
    }
    function view() {
      return base0().filter(function (o) { return S.status === "all" || o.status === S.status; });
    }

    function render() {
      var b = base0(), rows = view();
      $("#o-tabs").html(
        [["all", "All"], ["pending", "Awaiting approval"], ["approved", "Approved"], ["rejected", "Rejected"]]
          .map(function (t) {
            var n = t[0] === "all" ? b.length : b.filter(function (o) { return o.status === t[0]; }).length;
            var on = S.status === t[0];
            return "<button type='button' class='ccm-tab" + (on ? " on" : "") + "' role='tab' aria-selected='" + on + "' data-status='" + t[0] + "'>" + t[1] + " <span class='ccm-count'>" + n + "</span></button>";
          })
          .join("")
      );
      $("#o-rows").html(
        rows.map(function (o) {
          return (
            "<tr>" +
            "<td><a class='ccm-idlink' href='" + base + "orders/view/" + o.id + "' data-ccm-order='" + o.id + "'>#" + o.id + "</a></td>" +
            "<td>" + esc(o.date ? fd(o.date) : "") + " <span class='ccm-muted ccm-small'>" + esc(o.date ? ft(o.date) : "") + "</span></td>" +
            "<td>" + esc(o.customer) + "</td>" +
            "<td><span class='ccm-pill " + (o.vendor ? "p-neutral" : "p-pending") + "'>" + esc(o.vendor || "Not assigned") + "</span></td>" +
            "<td class='num ccm-strong'>" + money(o.total) + "</td>" +
            "<td><span class='" + o.pillCls + "'>" + esc(o.statusLabel) + "</span></td>" +
            "<td class='num'><a class='ccm-btn sm' href='" + base + "orders/view/" + o.id + "' data-ccm-order='" + o.id + "'>View details</a></td>" +
            "</tr>"
          );
        }).join("")
      );
      $("#o-empty").prop("hidden", rows.length > 0);
      $("#o-foot").text(
        "Showing " + rows.length + " of " + S.orders.length + " orders · " +
          money(rows.reduce(function (a, o) { return a + o.total; }, 0)) + " total"
      );
    }

    function load() {
      return loadOrders().done(function (orders) {
        S.orders = orders;
        var names = orders.map(function (o) { return o.vendor; }).filter(function (v, i, a) { return v && a.indexOf(v) === i; }).sort();
        setOptions($("#o-vendor"), names, 2);
        $("#o-vendor").val(S.vendor);
        render();
      }).fail(function (x) {
        $("#o-rows").html("<tr><td colspan='7' class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load orders")) + "</td></tr>");
      });
    }

    $("#o-tabs").on("click", "[data-status]", function () {
      S.status = $(this).data("status");
      render();
    });
    $("#o-q").on("input", function () { S.q = $.trim(this.value); render(); });
    $("#o-vendor").on("change", function () { S.vendor = this.value; render(); });
    $("#o-range").on("change", function () { S.range = this.value; render(); });
    $("#o-clear").on("click", function () {
      S.q = ""; S.vendor = "all"; S.range = "all"; S.status = "all";
      $("#o-q").val(""); $("#o-vendor").val("all"); $("#o-range").val("all");
      render();
    });
    $("[data-ccm-export]").on("click", function () {
      csv("orders", ["Order", "Date", "Requested by", "Vendor", "Total", "Status"], view().map(function (o) {
        return [o.id, o.date, o.customer, o.vendor || "Not assigned", o.total.toFixed(2), o.statusLabel];
      }));
    });
    window.ccmRefresh = window.ccmReload = function () { load(); };
    load();
  };

  /* ---------- Products ---------- */
  function productFlags(p) {
    var prices = [p.woo, p.amz, p.gt].filter(function (x) { return x != null && x > 0; });
    var best = prices.length > 1 ? Math.min.apply(null, prices) : null;
    var flags = [];
    if (!p.vendor) flags.push(["No vendor", "p-pending"]);
    if (!p.woo) flags.push(["Price is $0", "p-rejected"]);
    if (/test|product 1/i.test(p.name)) flags.push(["Test item", "p-neutral"]);
    if (best != null && p.woo === best) flags.push(["Website is cheapest", "p-approved"]);
    return { best: best, flags: flags, compare: prices.length > 1 };
  }

  screens.products = function () {
    var params = new URLSearchParams(location.search);
    var S = { products: [], q: params.get("search") || "", vendor: "all", filter: "all" };
    var wantVendor = params.get("vendor") || "";
    $("#p-q").val(S.q);

    function view() {
      var q = S.q.toLowerCase();
      return S.products.filter(function (p) {
        if (q && (p.name + " " + p.sku).toLowerCase().indexOf(q) < 0) return false;
        if (S.vendor === "none" && p.vendor) return false;
        if (S.vendor !== "all" && S.vendor !== "none" && p.vendor !== S.vendor) return false;
        if (S.filter === "issues" && p.vendor && p.woo > 0) return false;
        if (S.filter === "compare" && !productFlags(p).compare) return false;
        return true;
      });
    }
    function render() {
      var rows = view();
      $("#p-rows").html(
        rows.map(function (p) {
          var f = productFlags(p);
          var cell = function (x) {
            var cls = f.best != null && x === f.best ? "ccm-best" : x == null ? "ccm-muted" : "";
            return "<span class='" + cls + "'>" + (x == null ? "—" : money(x)) + "</span>";
          };
          return (
            "<tr>" +
            "<td style='white-space:normal;min-width:220px'><div class='ccm-strong'>" + esc(p.name) + "</div><div class='ccm-muted ccm-small'>ID " + p.id + "</div></td>" +
            "<td style='font-variant-numeric:tabular-nums'>" + esc(p.sku) + "</td>" +
            "<td><span class='ccm-pill " + (p.vendor ? "p-neutral" : "p-pending") + "'>" + esc(p.vendor || "Not assigned") + "</span></td>" +
            "<td class='num'>" + cell(p.woo) + "</td><td class='num'>" + cell(p.amz) + "</td><td class='num'>" + cell(p.gt) + "</td>" +
            "<td><div class='ccm-flags'>" + f.flags.map(function (x) { return "<span class='ccm-pill " + x[1] + "'>" + x[0] + "</span>"; }).join("") + "</div></td>" +
            "<td class='num'><span class='ccm-actions' style='justify-content:flex-end;flex-wrap:nowrap'>" +
            "<a class='ccm-btn sm' href='" + base + "products/edit/" + p.id + "'>Edit</a>" +
            "<button type='button' class='ccm-btn sm danger' data-del='" + p.id + "'>Delete</button></span></td>" +
            "</tr>"
          );
        }).join("")
      );
      $("#p-empty").prop("hidden", rows.length > 0);
      $("#p-foot").text("Showing " + rows.length + " of " + S.products.length + " products");
    }

    $.when(loadProducts(), loadVendors())
      .done(function (products, vendors) {
        S.products = products;
        var names = vendors.map(function (v) { return v.name; }).sort();
        setOptions($("#pl-vendor"), names, 2);
        if (wantVendor) {
          var w = wantVendor.toLowerCase().replace(/[^a-z0-9]/g, "");
          names.forEach(function (n) {
            if (n.toLowerCase().replace(/[^a-z0-9]/g, "") === w || n.toLowerCase().replace(/&/g, "").replace(/[^a-z0-9]/g, "") === w) S.vendor = n;
          });
          $("#pl-vendor").val(S.vendor);
        }
        render();
      })
      .fail(function (x) {
        $("#p-rows").html("<tr><td colspan='8' class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load products")) + "</td></tr>");
      });

    $("#p-q").on("input", function () { S.q = $.trim(this.value); render(); });
    $("#pl-vendor").on("change", function () { S.vendor = this.value; render(); });
    $("#p-filter").on("change", function () { S.filter = this.value; render(); });
    $("#p-rows").on("click", "[data-del]", function () {
      var id = +$(this).data("del");
      var p = S.products.filter(function (x) { return x.id === id; })[0];
      confirmDialog({
        title: "Delete " + p.name + "?",
        onOk: function () {
          return req("DELETE", "products/" + id).then(
            function () {
              S.products = S.products.filter(function (x) { return x.id !== id; });
              render();
              toast(p.name + " deleted");
            },
            function (x) { toast(errMsg(x, "Couldn’t delete product")); }
          );
        },
      });
    });
    $("[data-ccm-export]").on("click", function () {
      csv("products", ["ID", "Product", "SKU", "Vendor", "Website", "Amazon", "Grand & Toy"], view().map(function (p) {
        return [p.id, p.name, p.sku, p.vendor, p.woo, p.amz, p.gt];
      }));
    });
  };

  /* ---------- Vendors ---------- */
  screens.vendors = function () {
    var S = { vendors: [], products: [], orders: [], loadedAt: "" };
    var MONTHS_FULL = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    var FIELDS = [
      { k: "name", l: "Vendor name", req: true, full: true, ph: "e.g. Grand & Toy" },
      { k: "email", l: "Order email", type: "email", ph: "orders@vendor.com" },
      { k: "phone", l: "Phone", type: "tel", ph: "+1 555 123 4567" },
      { k: "contact", l: "Contact person", ph: "Account manager name" },
      { k: "account_no", l: "Account / customer no.", ph: "Your account number with this vendor" },
      { k: "website", l: "Website", type: "url", full: true, ph: "https://vendor.com" },
      { k: "address", l: "Address", type: "textarea", full: true, ph: "Street, city, province, postal code" },
      { k: "budget_default", l: "Default monthly budget (CA$)", type: "number", ph: "Leave empty for no budget" },
      { k: "notes", l: "Notes", type: "textarea", full: true, ph: "Ordering instructions, delivery days, minimums…" },
    ];
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    // Client-side checks so mistakes show in the form before saving.
    function checkVendor(f) {
      if (f.email && !EMAIL_RE.test(f.email)) return "Order email is not a valid email address.";
      if (f.budget_default !== "" && (isNaN(+f.budget_default) || +f.budget_default < 0)) return "Default monthly budget must be a positive number.";
      return "";
    }
    function vendorPayload(f) {
      return $.extend({}, f, { budget_default: f.budget_default === "" ? null : +f.budget_default });
    }

    function ym(d) {
      return d.getFullYear() + "-" + ("0" + (d.getMonth() + 1)).slice(-2);
    }
    function monthLabel(key) {
      return MONTHS_FULL[+key.slice(5) - 1] + " " + key.slice(0, 4);
    }
    // A month's own budget wins; otherwise the vendor's default.
    function budgetFor(v, key) {
      if (v.budgets && v.budgets[key] != null) return { amount: +v.budgets[key], source: "month" };
      if (v.budget_default != null) return { amount: +v.budget_default, source: "default" };
      return null;
    }
    function ordersOf(v) {
      var key = v.name.toLowerCase();
      return S.orders.filter(function (o) {
        return o.status !== "rejected" && o.vendor.toLowerCase().split(" + ").indexOf(key) >= 0;
      });
    }
    function row(label, value) {
      return "<div class='ccm-vrow'><dt>" + label + "</dt><dd>" + value + "</dd></div>";
    }
    function feedRows(f) {
      if (!f) return row("Price feed", "<span class='ccm-muted'>Manual — set on products</span>");
      if (!f.last_fetch) return row("Prices fetched", "Never");
      var bits = ["<span class='ccm-ok-text'>" + f.ok + " OK</span>"];
      if (f.missing_id) bits.push(f.missing_id + " no ID");
      if (f.errors) bits.push("<span class='ccm-err-text'>" + f.errors + " failed</span>");
      return row("Prices fetched", esc(fdt(f.last_fetch))) + row("Fetch results", bits.join(" · "));
    }

    function render() {
      var thisMonth = ym(new Date()), mStart = monthStart().getTime();
      $("#v-cards").html(
        S.vendors.map(function (v) {
          var vo30 = ordersOf(v);
          var voMonth = vo30.filter(function (o) { return dt(o.date).getTime() >= mStart; });
          var spentMonth = voMonth.reduce(function (a, o) { return a + o.total; }, 0);
          var products = S.products.filter(function (p) { return p.vendor === v.name; }).length;
          var last = vo30.length ? vo30[0].date : "";
          var b = budgetFor(v, thisMonth);
          var pct = b && b.amount > 0 ? Math.round((spentMonth / b.amount) * 100) : 0;
          var over = b && spentMonth > b.amount;
          var budgetHtml = b
            ? "<div class='ccm-hbar-top'><span class='ccm-small'><span class='ccm-strong'>" + money(spentMonth) + "</span> <span class='ccm-muted'>of " + money(b.amount) + (b.source === "default" ? " (default)" : "") + "</span></span>" +
              "<span class='ccm-pill " + (over ? "p-rejected" : pct >= 80 ? "p-pending" : "p-approved") + "'>" + (over ? "Over budget" : pct + "% used") + "</span></div>" +
              "<div class='ccm-progress'><div style='width:" + Math.min(100, pct) + "%;" + (over ? "background:#A8261C" : "") + "'></div></div>"
            : "<div class='ccm-muted ccm-small'>No budget set — spent " + money(spentMonth) + " so far</div>";
          return (
            "<article class='ccm-card ccm-vcard'>" +
            "<div class='ccm-vhead'><div class='ccm-vlogo' aria-hidden='true'>" + esc(initials(v.name)) + "</div>" +
            "<div style='flex:1;min-width:0'><div class='ccm-strong' style='font-size:16px'>" + esc(v.name) + "</div><div class='ccm-muted ccm-small'>Vendor ID " + v.id + "</div></div>" +
            "<span class='ccm-pill " + (v.email ? "p-approved" : "p-pending") + "'>" + (v.email ? "Ready" : "Missing contact") + "</span></div>" +
            "<div class='ccm-vstats'>" +
            "<div><div class='ccm-vstat-v'>" + products + "</div><div class='ccm-vstat-l'>Products</div></div>" +
            "<div><div class='ccm-vstat-v'>" + vo30.length + "</div><div class='ccm-vstat-l'>Orders (30d)</div></div>" +
            "<div><div class='ccm-vstat-v'>" + moneyShort(vo30.reduce(function (a, o) { return a + o.total; }, 0)) + "</div><div class='ccm-vstat-l'>Spend (30d)</div></div></div>" +
            "<div class='ccm-vbudget'><div class='ccm-hbar-top'><span class='ccm-strong ccm-small'>" + esc(monthLabel(thisMonth)) + " budget</span>" +
            "<button type='button' class='ccm-link' data-budget='" + v.id + "'>" + (b ? "Edit budget" : "Set budget") + "</button></div>" + budgetHtml + "</div>" +
            "<dl class='ccm-vrows'>" +
            row("Email", v.email ? esc(v.email) : "<span class='ccm-muted'>Not set</span>") +
            row("Phone", v.phone ? esc(v.phone) : "<span class='ccm-muted'>Not set</span>") +
            (v.contact ? row("Contact", esc(v.contact)) : "") +
            (v.account_no ? row("Account no.", esc(v.account_no)) : "") +
            (v.website ? row("Website", "<a class='ccm-link' style='padding:0;font-size:13px' href='" + esc(v.website) + "' target='_blank' rel='noopener'>" + esc(v.website.replace(/^https?:\/\//, "").replace(/\/$/, "")) + "</a>") : "") +
            row("Last order", last ? esc(fdt(last)) : "<span class='ccm-muted'>None in 30 days</span>") +
            feedRows(v.feed) + "</dl>" +
            (v.feed && v.feed.errors && v.feed.last_error ? "<div class='ccm-feed-err' title='" + esc(v.feed.last_error) + "'>" + esc(v.feed.last_error) + "</div>" : "") +
            "<button type='button' class='ccm-btn sm ccm-apibtn' data-api='" + v.id + "'>" +
            "<svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><path d='m8 9-4 3 4 3'/><path d='m16 9 4 3-4 3'/><path d='m14 5-4 14'/></svg>" +
            "View API response</button>" +
            "<div class='ccm-actions'><button type='button' class='ccm-btn sm' data-edit='" + v.id + "'>Edit details</button>" +
            "<a class='ccm-btn sm' href='" + base + "products?vendor=" + encodeURIComponent(v.name) + "'>View products</a>" +
            "<button type='button' class='ccm-btn sm danger' style='margin-left:auto' data-del='" + v.id + "'>Delete</button></div>" +
            "<div class='ccm-vupdated'>Data updated " + esc(S.loadedAt) + "</div>" +
            "</article>"
          );
        }).join("") || "<div class='ccm-card ccm-empty'><strong>No vendors yet</strong>Add the suppliers that fulfil store orders.</div>"
      );
    }
    function find(id) {
      return S.vendors.filter(function (v) { return v.id === id; })[0];
    }
    function stamp() {
      S.loadedAt = "today, " + nowHM();
      $(".ccm-updated").text(nowHM());
    }
    function reloadVendors() {
      return loadVendors().done(function (v) { S.vendors = v; stamp(); render(); });
    }
    function loadAll() {
      // Covers both the 30-day stats and month-to-date budget spend.
      var from = new Date(Math.min(Date.now() - 30 * DAY, monthStart().getTime()));
      return $.when(loadVendors(), loadProducts(), loadOrders({ date_from: ymd(from) }))
        .done(function (vendors, products, orders) {
          S.vendors = vendors; S.products = products; S.orders = orders;
          stamp();
          render();
        })
        .fail(function (x) {
          $("#v-cards").html("<div class='ccm-card ccm-empty'>" + esc(errMsg(x, "Couldn’t load vendors")) + "</div>");
        });
    }
    window.ccmRefresh = window.ccmReload = function () { loadAll(); };
    loadAll();

    $("#v-new").on("click", function () {
      formModal({
        title: "Add vendor", sub: "Vendors receive approved orders by email. Only the name is required.", saveLabel: "Add vendor", fields: FIELDS,
        onSave: function (f) {
          var bad = checkVendor(f);
          if (bad) return $.Deferred().reject(bad);
          return req("POST", "vendors/create", $.extend(vendorPayload(f), { strict: true })).then(function () {
            toast("Vendor added: " + f.name);
            reloadVendors();
          });
        },
      });
    });
    function statusPill(st) {
      var map = { ok: ["OK", "p-approved"], error: ["Failed", "p-rejected"], missing_id: ["No vendor ID", "p-pending"] };
      var m = map[st] || [st, "p-neutral"];
      return "<span class='ccm-pill " + m[1] + "'>" + esc(m[0]) + "</span>";
    }
    function renderApi(v, d) {
      $("#if-title").text("API response · " + d.vendor);
      if (!d.has_feed) {
        $("#if-sub").text("This vendor has no API connection.");
        $("#if-body").html("<div class='ccm-empty'><strong>No live price feed</strong>Prices for " + esc(d.vendor) + " are entered on each product. Only Amazon and Grand &amp; Toy are fetched from an API.</div>");
        $("#if-foot").html("<button type='button' class='ccm-btn primary' data-ccm-close>Close</button>");
        return;
      }
      var f = d.feed || {};
      $("#if-sub").text("Results of the latest price fetch for each product (newest problems first).");
      var summary =
        "<div class='ccm-minis ccm-api-minis'>" +
        "<div><div class='ccm-mini-v'>" + esc(f.last_fetch ? fdt(f.last_fetch) : "Never") + "</div><div class='ccm-mini-l'>Last fetch</div></div>" +
        "<div><div class='ccm-mini-v ccm-ok-text'>" + (f.ok || 0) + "</div><div class='ccm-mini-l'>OK</div></div>" +
        "<div><div class='ccm-mini-v'>" + (f.missing_id || 0) + "</div><div class='ccm-mini-l'>No vendor ID on product</div></div>" +
        "<div><div class='ccm-mini-v ccm-err-text'>" + (f.errors || 0) + "</div><div class='ccm-mini-l'>Failed</div></div></div>";
      var errs = (d.errors || []).length
        ? "<h3 class='ccm-card-t' style='margin:4px 0 8px'>What went wrong</h3>" +
          d.errors.map(function (e) {
            return "<div class='ccm-api-err'><span class='ccm-api-err-n'>" + e.count + " product" + (e.count === 1 ? "" : "s") + "</span><div>" + esc(e.message) +
              (e.example_id ? "<div class='ccm-api-err-id'>Example incident ID: " + esc(e.example_id) + "</div>" : "") + "</div></div>";
          }).join("")
        : "<div class='ccm-api-okbox'>No errors in the latest fetch.</div>";
      var table =
        "<h3 class='ccm-card-t' style='margin:8px 0'>Per product (" + d.items.length + ")</h3>" +
        "<div class='ccm-tablewrap'><table class='ccm-table ccm-api-table'><thead><tr><th>Product</th><th>SKU</th><th>Vendor item ID</th><th>Status</th><th class='num'>Price</th><th>Fetched</th></tr></thead><tbody>" +
        d.items.map(function (r) {
          return "<tr><td class='ccm-api-prod'><a class='ccm-idlink' href='" + base + "products/edit/" + r.product_id + "'>" + esc(r.product || "#" + r.product_id) + "</a>" +
            (r.error ? "<div class='ccm-api-errline'>" + esc(r.error) + "</div>" : "") + "</td>" +
            "<td>" + esc(r.sku) + "</td><td>" + esc(r.vendor_sku || "—") + "</td><td>" + statusPill(r.status) + "</td>" +
            "<td class='num'>" + (r.price == null ? "—" : money(r.price)) + "</td><td>" + esc(r.fetched_at ? fdt(r.fetched_at) : "—") + "</td></tr>";
        }).join("") +
        "</tbody></table></div>";
      var log = d.sync_log
        ? "<details class='ccm-api-log'><summary>Last sync run (raw log)</summary><pre>" + esc(JSON.stringify(d.sync_log, null, 2)) + "</pre></details>"
        : "";
      $("#if-body").html(summary + errs + table + log);
      $("#if-foot").html(
        "<span class='ccm-muted ccm-small' style='margin-right:auto'>Fetch now runs one batch (up to 15 products) for Amazon and Grand &amp; Toy.</span>" +
        "<button type='button' class='ccm-btn' data-ccm-close>Close</button>" +
        "<button type='button' class='ccm-btn primary' id='if-fetch'>Fetch now</button>"
      );
      $("#if-fetch").on("click", function () {
        var $b = $(this).prop("disabled", true).text("Fetching…");
        req("POST", "prices/sync")
          .done(function (r) {
            toast(r.message || "Price fetch finished");
            openApi(v);
            reloadVendors();
          })
          .fail(function (x) {
            toast(errMsg(x, "Price fetch failed"));
            $b.prop("disabled", false).text("Fetch now");
          });
      });
    }
    function openApi(v) {
      $("#if-title").text("API response · " + v.name);
      $("#if-sub").text("Loading…");
      $("#if-body").html("<div class='ccm-empty'>Loading fetch results…</div>");
      $("#if-foot").empty();
      if ($("#ccm-info").prop("hidden")) openLayer("ccm-info");
      get("vendors/" + v.id + "/price-log")
        .done(function (d) { renderApi(v, d); })
        .fail(function (x) { $("#if-body").html("<div class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load the API response")) + "</div>"); });
    }

    $("#v-cards")
      .on("click", "[data-api]", function () {
        openApi(find(+$(this).data("api")));
      })
      .on("click", "[data-budget]", function () {
        var v = find(+$(this).data("budget"));
        var now = new Date(), opts = [];
        for (var i = -2; i <= 12; i++) {
          var key = ym(new Date(now.getFullYear(), now.getMonth() + i, 1));
          var own = v.budgets && v.budgets[key] != null ? " — " + moneyShort(v.budgets[key]) : "";
          opts.push({ v: key, l: monthLabel(key) + own });
        }
        var cur = ym(now), b = budgetFor(v, cur);
        formModal({
          title: "Monthly budget · " + v.name,
          sub: "Spending cap for orders sent to this vendor. Leave the amount empty to remove a month’s budget.",
          saveLabel: "Save budget",
          fields: [
            { k: "month", l: "Month", options: opts },
            { k: "amount", l: "Budget (CA$)", type: "number", ph: "e.g. 5000" },
            { k: "as_default", l: "Apply to", full: true, options: [
              { v: "", l: "Only the selected month" },
              { v: "1", l: "Selected month and every month without its own budget (default)" },
            ] },
          ],
          values: { month: cur, amount: b ? b.amount : "", as_default: "" },
          onSave: function (f) {
            return req("POST", "vendors/" + v.id + "/budget", { month: f.month, amount: f.amount === "" ? null : +f.amount, as_default: !!f.as_default })
              .then(function () {
                toast("Budget saved for " + v.name + " · " + monthLabel(f.month));
                reloadVendors();
              });
          },
        });
        // Prefill the amount with whatever that month already has.
        $("#md-fields [name='month']").on("change", function () {
          var own = v.budgets && v.budgets[this.value];
          $("#md-fields [name='amount']").val(own != null ? own : "");
        });
      })
      .on("click", "[data-edit]", function () {
        var v = find(+$(this).data("edit"));
        formModal({
          title: "Edit vendor", sub: "Clear a field and save to remove it.", saveLabel: "Save changes", fields: FIELDS,
          values: $.extend({}, v, { budget_default: v.budget_default == null ? "" : v.budget_default }),
          onSave: function (f) {
            var bad = checkVendor(f);
            if (bad) return $.Deferred().reject(bad);
            return req("PUT", "vendors/" + v.id, vendorPayload(f)).then(function () {
              toast("Vendor updated: " + f.name);
              reloadVendors();
            });
          },
        });
      })
      .on("click", "[data-del]", function () {
        var v = find(+$(this).data("del"));
        confirmDialog({
          title: "Delete " + v.name + "?",
          onOk: function () {
            return req("DELETE", "vendors/" + v.id).then(
              function () { toast(v.name + " deleted"); reloadVendors(); },
              function (x) { toast(errMsg(x, "Couldn’t delete vendor")); }
            );
          },
        });
      });
  };

  /* ---------- Stores ---------- */
  function storeFlags(stores) {
    var codes = {}, map = {};
    stores.forEach(function (s) { if (s.code) codes[s.code] = (codes[s.code] || 0) + 1; });
    stores.forEach(function (s) {
      var f = [];
      if (s.code && codes[s.code] > 1) f.push(["Duplicate code", "p-rejected"]);
      if (/test/i.test(s.name)) f.push(["Test store", "p-neutral"]);
      var m = String(s.user_name || "").match(/(\d{3})\s*$/);
      if (m && m[1] !== s.code) f.push(["User code " + m[1] + " ≠ " + s.code, "p-pending"]);
      if (f.length) map[s.id] = f;
    });
    return map;
  }

  screens.stores = function () {
    var S = { stores: [], spend: {}, flags: {}, q: "", prov: "all", filter: "all" };

    function view() {
      var q = S.q.toLowerCase();
      return S.stores.filter(function (s) {
        if (q && (s.name + " " + s.code + " " + s.city + " " + s.user_name).toLowerCase().indexOf(q) < 0) return false;
        if (S.prov !== "all" && s.state !== S.prov) return false;
        if (S.filter === "issues" && !S.flags[s.id]) return false;
        return true;
      });
    }
    function render() {
      var rows = view();
      $("#s-rows").html(
        rows.map(function (s) {
          var spent = S.spend[s.id] || 0;
          var pct = s.budget > 0 ? Math.min(100, Math.round((spent / s.budget) * 100)) : 0;
          return (
            "<tr>" +
            "<td><div class='ccm-strong'>" + esc(s.name) + "</div><div class='ccm-muted ccm-small'>Code " + esc(s.code || "—") + " · ID " + s.id + "</div></td>" +
            "<td>" + esc(s.city) + "<div class='ccm-muted ccm-small'>" + esc(s.state) + "</div></td>" +
            "<td class='num'>" + (s.quota || 0) + "</td>" +
            "<td class='num'>" + (s.budget ? moneyShort(s.budget) : "—") + "</td>" +
            "<td><div class='ccm-progress'><div style='width:" + pct + "%'></div></div><div class='ccm-muted ccm-small' style='margin-top:4px'>" + moneyShort(spent) + " · " + pct + "%</div></td>" +
            "<td>" + esc(s.user_name || "—") + "</td>" +
            "<td><div class='ccm-flags'>" + (S.flags[s.id] || []).map(function (f) { return "<span class='ccm-pill " + f[1] + "'>" + esc(f[0]) + "</span>"; }).join("") + "</div></td>" +
            "<td class='num'><span class='ccm-actions' style='justify-content:flex-end;flex-wrap:nowrap'>" +
            "<a class='ccm-btn sm' href='" + base + "stores/edit/" + s.id + "'>Edit</a>" +
            "<button type='button' class='ccm-btn sm danger' data-del='" + s.id + "'>Delete</button></span></td>" +
            "</tr>"
          );
        }).join("")
      );
      $("#s-empty").prop("hidden", rows.length > 0);
      $("#s-foot").text("Showing " + rows.length + " of " + S.stores.length + " stores");
    }
    function summary(ordered) {
      var provs = S.stores.map(function (s) { return s.state; }).filter(function (x, i, a) { return x && a.indexOf(x) === i; }).sort();
      $("#s-total").text(S.stores.length);
      $("#s-ordered").text(ordered);
      $("#s-issues").text(Object.keys(S.flags).length);
      $("#s-provs").text(provs.length);
      setOptions($("#s-prov"), provs, 1);
      $("#s-prov").val(S.prov);
    }

    $.when(loadStores(), loadOrders({ date_from: ymd(monthStart()) }))
      .done(function (stores, orders) {
        S.stores = stores.map(function (s) {
          return $.extend({}, s, { name: plainText(s.name), budget: +s.budget || 0, user_name: plainText(s.user_name || "") });
        });
        S.flags = storeFlags(S.stores);
        var ordered = {};
        orders.forEach(function (o) {
          if (!o.storeId) return;
          ordered[o.storeId] = true;
          if (o.status !== "rejected") S.spend[o.storeId] = (S.spend[o.storeId] || 0) + o.total;
        });
        summary(Object.keys(ordered).length);
        render();
      })
      .fail(function (x) {
        $("#s-rows").html("<tr><td colspan='8' class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load stores")) + "</td></tr>");
      });

    $("#s-q").on("input", function () { S.q = $.trim(this.value); render(); });
    $("#s-prov").on("change", function () { S.prov = this.value; render(); });
    $("#s-filter").on("change", function () { S.filter = this.value; render(); });
    $("#s-rows").on("click", "[data-del]", function () {
      var id = +$(this).data("del");
      var s = S.stores.filter(function (x) { return x.id === id; })[0];
      confirmDialog({
        title: "Delete " + s.name + "?",
        onOk: function () {
          return req("DELETE", "stores/" + id).then(
            function () {
              S.stores = S.stores.filter(function (x) { return x.id !== id; });
              S.flags = storeFlags(S.stores);
              render();
              toast(s.name + " deleted");
            },
            function (x) { toast(errMsg(x, "Couldn’t delete store")); }
          );
        },
      });
    });
    $("[data-ccm-export]").on("click", function () {
      csv("stores", ["ID", "Store", "Code", "City", "Province", "Quota / mo", "Budget / mo", "Used this month", "Assigned user"], view().map(function (s) {
        return [s.id, s.name, s.code, s.city, s.state, s.quota, s.budget, (S.spend[s.id] || 0).toFixed(2), s.user_name];
      }));
    });
  };

  /* ---------- Users ---------- */
  screens.users = function () {
    var S = { users: [], stores: [], q: "" };

    function view() {
      var q = S.q.toLowerCase();
      return S.users.filter(function (u) {
        return !q || (u.name + " " + u.email + " " + u.store_name).toLowerCase().indexOf(q) >= 0;
      });
    }
    function render() {
      var rows = view();
      $("#u-rows").html(
        rows.map(function (u) {
          return (
            "<tr>" +
            "<td><div style='display:flex;gap:10px;align-items:center'><div class='ccm-avatar sm' aria-hidden='true'>" + esc(initials(u.name)) + "</div><div><div class='ccm-strong'>" + esc(u.name) + "</div><div class='ccm-muted ccm-small'>ID " + u.id + "</div></div></div></td>" +
            "<td><a class='ccm-link' style='font-size:14px;font-weight:400;padding:0' href='mailto:" + esc(u.email) + "'>" + esc(u.email) + "</a></td>" +
            "<td>" + esc(u.store_name || "Not assigned") + "</td>" +
            "<td><span class='ccm-pill p-info'>Store employee</span></td>" +
            "<td><span class='ccm-pill p-approved'>Active</span></td>" +
            "<td class='num'><span class='ccm-actions' style='justify-content:flex-end;flex-wrap:nowrap'>" +
            "<button type='button' class='ccm-btn sm' data-edit='" + u.id + "'>Edit</button>" +
            "<button type='button' class='ccm-btn sm danger' data-del='" + u.id + "'>Remove</button></span></td>" +
            "</tr>"
          );
        }).join("")
      );
      $("#u-empty").prop("hidden", rows.length > 0);
      $("#u-foot").text("Showing " + rows.length + " users");
    }
    function loadUsers() {
      return get("users", { all: 1 }).done(function (list) {
        S.users = (list || []).map(function (u) {
          return $.extend({}, u, { name: plainText(u.name), store_name: plainText(u.store_name || "") });
        }).sort(function (a, b) { return a.email.localeCompare(b.email); });
        render();
      });
    }
    function fields() {
      return [
        { k: "name", l: "Full name", req: true, full: true },
        { k: "email", l: "Email", type: "email", req: true, full: true },
        {
          k: "store_id", l: "Assigned store", full: true,
          options: [{ v: "", l: "Choose a store" }].concat(S.stores.map(function (s) {
            return { v: s.id, l: s.name + (s.code ? " (" + s.code + ")" : "") };
          })),
        },
      ];
    }
    function find(id) {
      return S.users.filter(function (u) { return u.id === id; })[0];
    }

    $.when(loadUsers(), loadStores())
      .done(function (_u, stores) {
        S.stores = stores.map(function (s) { return { id: s.id, name: plainText(s.name), code: s.code }; })
          .sort(function (a, b) { return a.name.localeCompare(b.name); });
      })
      .fail(function (x) {
        $("#u-rows").html("<tr><td colspan='6' class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load users")) + "</td></tr>");
      });

    $("#u-q").on("input", function () { S.q = $.trim(this.value); render(); });
    $("#u-new").on("click", function () {
      formModal({
        title: "Add store employee", sub: "Each employee orders for one store.", saveLabel: "Add store employee", fields: fields(),
        onSave: function (f) {
          var parts = f.name.split(/\s+/);
          return req("POST", "users", { first_name: parts.shift(), last_name: parts.join(" "), email: f.email })
            .then(function (u) {
              return f.store_id ? req("PUT", "users/" + u.id, { name: f.name, email: f.email, store_id: +f.store_id }) : u;
            })
            .then(function () {
              toast("User added: " + f.name);
              loadUsers();
            });
        },
      });
    });
    $("#u-rows")
      .on("click", "[data-edit]", function () {
        var u = find(+$(this).data("edit"));
        formModal({
          title: "Edit store employee", sub: "Each employee orders for one store.", saveLabel: "Save changes", fields: fields(),
          values: { name: u.name, email: u.email, store_id: u.store_id || "" },
          onSave: function (f) {
            return req("PUT", "users/" + u.id, { name: f.name, email: f.email, store_id: +f.store_id || 0 }).then(function () {
              toast("User updated: " + f.name);
              loadUsers();
            });
          },
        });
      })
      .on("click", "[data-del]", function () {
        var u = find(+$(this).data("del"));
        confirmDialog({
          title: "Remove " + u.name + "?",
          sub: "They lose ordering access and are unassigned from their store. Their past orders are kept.",
          ok: "Remove",
          onOk: function () {
            return req("POST", "users/" + u.id + "/remove").then(
              function () { toast(u.name + " removed"); loadUsers(); },
              function (x) { toast(errMsg(x, "Couldn’t remove user")); }
            );
          },
        });
      });
  };

  /* ---------- Amazon catalog ---------- */
  screens.amazon = function () {
    var S = { products: [], linked: [] };

    function diffPill(p) {
      var diff = p.amz - (p.woo || 0);
      return "<span class='ccm-pill " + (diff > 0 ? "p-approved" : "p-pending") + "'>" + (diff > 0 ? "Amazon +" : "Amazon −") + money(Math.abs(diff)) + "</span>";
    }
    function render() {
      $("#az-rows").html(
        S.linked.map(function (p) {
          return "<tr><td class='ccm-strong'>" + esc(p.name) + "</td><td>" + esc(p.sku) + "</td><td class='num'>" + money(p.woo) + "</td><td class='num'>" + money(p.amz) + "</td><td class='num'>" + diffPill(p) + "</td></tr>";
        }).join("") || "<tr><td colspan='5' class='ccm-empty'><strong>No products linked yet</strong>Link products to Amazon to compare prices.</td></tr>"
      );
      $("#az-foot").text(S.linked.length + " of " + S.products.length + " products linked to Amazon");
    }

    loadProducts().done(function (products) {
      S.products = products;
      S.linked = products.filter(function (p) { return p.amz != null; });
      render();
    });
    loadOrders({ date_from: ymd(monthStart()) }).done(function (orders) {
      var n = orders.filter(function (o) { return o.hasAmazon || o.vendors.indexOf("amazon") >= 0; }).length;
      $("#az-month").text("Amazon orders this month: " + n);
    });

    $("#az-q").on("input", function () { $("#az-results").prop("hidden", true); });
    $("#az-form").on("submit", function (e) {
      e.preventDefault();
      var q = $.trim($("#az-q").val()), ql = q.toLowerCase();
      if (!q) return;
      var res = S.linked.filter(function (p) { return (p.name + " " + p.sku).toLowerCase().indexOf(ql) >= 0; });
      $("#az-results")
        .html(
          (res.map(function (p) {
            return "<div class='ccm-attn'><div style='flex:1'><div class='ccm-strong'>" + esc(p.name) + "</div><div class='ccm-muted ccm-small'>SKU " + esc(p.sku) + " · linked to product " + p.id + "</div></div><div class='ccm-strong' style='font-variant-numeric:tabular-nums'>" + money(p.amz) + "</div><span class='ccm-pill p-approved'>Linked</span></div>";
          }).join("") || "<div class='ccm-empty'><strong>No Amazon results for “" + esc(q) + "”</strong>Check the spelling or search by ASIN.</div>") +
            "<a class='ccm-link' href='" + home + "amazon-catalog/'>Search Amazon’s live catalog →</a>"
        )
        .prop("hidden", false);
    });
  };

  /* ---------- Grand & Toy ---------- */
  screens.gt = function () {
    var S = { products: [] };

    function render() {
      $("#gt-rows").html(
        S.products.map(function (p) {
          var ok = p.gt != null;
          return (
            "<tr><td class='ccm-strong'>" + esc(p.name) + "</td><td>" + esc(p.sku) + "</td>" +
            "<td class='num'>" + (p.woo == null ? "—" : money(p.woo)) + "</td><td class='num'>" + (ok ? money(p.gt) : "—") + "</td>" +
            "<td><span class='ccm-pill " + (ok ? "p-approved" : "p-pending") + "'>" + (ok ? "Up to date" : "Not synced") + "</span></td>" +
            "<td class='num'><a class='ccm-btn sm' href='" + base + "products/edit/" + p.id + "'>Edit</a></td></tr>"
          );
        }).join("") || "<tr><td colspan='6' class='ccm-empty'><strong>No Grand &amp; Toy products</strong>Set Grand &amp; Toy as the vendor on a product to see it here.</td></tr>"
      );
      $("#gt-count").text(S.products.length);
      $("#gt-synced").text(S.products.filter(function (p) { return p.gt != null; }).length);
    }
    function lastSync(d) {
      if (!d || !d.last_sync) return "Never";
      return d.last_sync.slice(0, 10) === ymd(new Date()) ? "Today, " + d.last_sync.slice(11, 16) : fdt(d.last_sync);
    }
    function load() {
      loadProducts({ vendor: "grand-toy" }).done(function (p) { S.products = p; render(); });
      get("prices/sync").done(function (d) { $("#gt-last").text(lastSync(d)); });
    }

    $("#gt-sync").on("click", function () {
      var $b = $(this);
      if ($b.prop("disabled")) return;
      $b.prop("disabled", true).text("Syncing…");
      req("POST", "prices/sync")
        .done(function (d) {
          $("#gt-last").text(lastSync(d));
          toast(d.message || "Price sync finished");
          load();
        })
        .fail(function (x) { toast(errMsg(x, "Price sync failed")); })
        .always(function () { $b.prop("disabled", false).text("Sync prices now"); });
    });
    load();
  };

  $(function () {
    var name = $("[data-ccm-screen]").data("ccmScreen");
    if (name && screens[name]) screens[name]();
  });
})(jQuery);
