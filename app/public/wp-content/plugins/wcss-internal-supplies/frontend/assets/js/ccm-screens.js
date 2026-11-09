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
  // Why an order needs Supply Ops approval (WCSS_Order_Policy reasons).
  var REASON = {
    outside_window: "Outside ordering window",
    over_quota: "Already ordered this month",
    over_budget: "Over monthly budget",
    no_store: "No store linked",
    ink_review: "Ink order — always needs Supply Ops review",
  };

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
    var orderType = o.order_type === "ink" ? "ink" : "stationery";
    return {
      id: o.id,
      date: o.date || "",
      total: +o.total || 0,
      customer: plainText(o.customer || ""),
      vendor: vendor,
      vendors: o.vendors || [],
      placedBySupplyOps: !!o.placed_by,
      hasAmazon: !!o.has_amazon_order,
      storeId: o.store && o.store.id ? +o.store.id : 0,
      status: status,
      statusLabel: LABEL[status] || plainText(o.status || ""),
      pillCls: "ccm-pill " + (LABEL[status] ? "p-" + status : "p-neutral"),
      policy: o.policy || null,
      reasons: o.policy && !o.policy.in_policy ? (o.policy.reasons || []).map(function (r) { return REASON[r] || r; }) : [],
      orderType: orderType,
      isInk: orderType === "ink",
    };
  }
  function normProduct(p) {
    return {
      id: p.id,
      name: plainText(p.name),
      sku: p.sku || "",
      // Live vendors can win a product without a vendor term, so fall back to the winner.
      vendor: p.vendor && p.vendor.name ? plainText(p.vendor.name) : ({ amazon: "Amazon", gt: "Grand & Toy" })[(p.cheapest || {}).vendor] || "",
      orderable: p.orderable !== false,
      assigned: (p.assigned_vendor_ids || []).length > 0,
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
          id: v.id, name: plainText(v.name), active: v.active !== false, email: v.email || "", phone: v.phone || "", address: v.address || "",
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
    if (id === "ccm-info") $("#ccm-info .ccm-modal").removeClass("ccm-modal--pol");
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  $(document).on("click", "[data-ccm-close]", function () {
    var id = $(this).closest("#ccm-drawer,#ccm-modal,#ccm-confirm,#ccm-info").attr("id");
    if (id === "ccm-modal" && $("#ccm-modal .ccm-modal").hasClass("is-busy")) return;
    if (id === "ccm-confirm" && $("#ccm-confirm .ccm-modal").hasClass("is-busy")) return;
    closeLayer(id);
  });
  $(document).on("keydown", "#ccm-drawer,#ccm-modal,#ccm-confirm,#ccm-info", function (e) {
    if (e.key === "Escape") {
      if (this.id === "ccm-modal" && $("#ccm-modal .ccm-modal").hasClass("is-busy")) return;
      if (this.id === "ccm-confirm" && $("#ccm-confirm .ccm-modal").hasClass("is-busy")) return;
      closeLayer(this.id);
    }
  });

  var confirmOk = null;
  var confirmBusyText = null;
  function setConfirmBusy(on, copy) {
    var $m = $("#ccm-confirm .ccm-modal");
    $m.toggleClass("is-busy", !!on);
    $("#cf-busy").prop("hidden", !on);
    if (copy) {
      $("#cf-busy-title").text(copy.title || "Working…");
      $("#cf-busy-sub").text(copy.sub || "Please wait a moment.");
    }
    $("#cf-ok, #cf-cancel, #ccm-confirm [data-ccm-close]").prop("disabled", !!on);
  }
  function confirmDialog(opts) {
    $("#cf-title").text(opts.title);
    $("#cf-sub").text(opts.sub || "This removes it for everyone and can’t be undone.");
    $("#cf-ok").text(opts.ok || "Delete").prop("disabled", false);
    $("#cf-cancel").prop("disabled", false);
    setConfirmBusy(false);
    confirmBusyText = opts.busyText || null;
    confirmOk = opts.onOk;
    openLayer("ccm-confirm");
  }
  /** Permanently delete an order (DB cleanup). onDone runs after a successful delete. */
  function deleteOrderDialog(id, onDone) {
    confirmDialog({
      title: "Delete order #" + id + " permanently?",
      sub: "This removes the order, its items, history, notes and email log from the system. It can’t be undone. " +
        "If the order was already sent to Amazon or Grand & Toy, this does not cancel it with the vendor.",
      ok: "Delete order",
      busyText: { title: "Deleting order #" + id + "…", sub: "Cleaning up all of its records." },
      onOk: function () {
        return req("DELETE", "orders/" + id, { confirm: id }).then(
          function () {
            toast("Order #" + id + " deleted");
            if (onDone) onDone();
          },
          function (x) { toast(errMsg(x, "Couldn’t delete order #" + id)); }
        );
      },
    });
  }

  $(document).on("click", "#cf-ok", function () {
    if ($("#ccm-confirm .ccm-modal").hasClass("is-busy")) return;
    var busy = typeof confirmBusyText === "function" ? confirmBusyText() : confirmBusyText;
    setConfirmBusy(true, busy || { title: "Working…", sub: "Please wait a moment." });
    $.when(confirmOk && confirmOk())
      .done(function () {
        setConfirmBusy(false);
        closeLayer("ccm-confirm");
      })
      .fail(function () {
        setConfirmBusy(false);
      });
  });

  var modalSave = null;
  var modalBusyText = null;
  function setModalBusy(on, copy) {
    var $form = $("#ccm-modal .ccm-modal");
    $form.toggleClass("is-busy", !!on);
    $("#md-busy").prop("hidden", !on);
    if (copy) {
      $("#md-busy-title").text(copy.title || "Saving…");
      $("#md-busy-sub").text(copy.sub || "Please wait a moment.");
    }
    $("#md-save, #ccm-modal [data-ccm-close]").prop("disabled", !!on);
  }
  /**
   * fields: [{k, l, type, req, full, ph, options:[{v,l}]}]
   * onSave(values) must return a promise; a rejection message is shown in the modal.
   * busyText(values) optional → {title, sub} shown while saving.
   */
  function formModal(opts) {
    $("#md-title").text(opts.title);
    $("#md-sub").text(opts.sub || "");
    $("#md-save").text(opts.saveLabel).prop("disabled", false);
    $("#md-err").prop("hidden", true).text("");
    setModalBusy(false);
    modalBusyText = opts.busyText || null;
    var vals = opts.values || {};
    $("#md-fields").html(
      opts.fields
        .map(function (f) {
          var v = vals[f.k] == null ? "" : String(vals[f.k]);
          var checked = !!vals[f.k] && vals[f.k] !== "0" && vals[f.k] !== "false";
          var control = f.options
            ? "<select class='ccm-select' name='" + f.k + "'>" +
              f.options.map(function (o) {
                return "<option value='" + esc(o.v) + "'" + (String(o.v) === v ? " selected" : "") + ">" + esc(o.l) + "</option>";
              }).join("") + "</select>"
            : f.type === "textarea"
            ? "<textarea class='ccm-input ccm-textarea' name='" + f.k + "' rows='3' placeholder='" + esc(f.ph || "") + "'>" + esc(v) + "</textarea>"
            : f.type === "checkbox"
            ? "<span class='ccm-check'><input type='checkbox' name='" + f.k + "' value='1'" + (checked ? " checked" : "") + ">" + (f.hint ? "<span><span class='ccm-check-l'>" + esc(f.l) + "</span><span class='ccm-check-h'>" + esc(f.hint) + "</span></span>" : "") + "</span>"
            : "<input class='ccm-input' name='" + f.k + "' type='" + (f.type || "text") + "'" + (f.type === "number" ? " min='0' step='0.01'" : "") + (f.noAutofill ? " autocomplete='off'" : "") + " placeholder='" + esc(f.ph || "") + "' value='" + esc(v) + "'>";
          // Checkbox already shows its own label inside .ccm-check; skip the outer field label text.
          if (f.type === "checkbox") {
            return "<label class='ccm-field'" + (f.full ? " style='grid-column: 1 / -1'" : "") + ">" + control + "</label>";
          }
          return "<label class='ccm-field'" + (f.full ? " style='grid-column: 1 / -1'" : "") + ">" + esc(f.l + (f.req ? " *" : "")) + control + "</label>";
        })
        .join("")
    );
    modalSave = function () {
      var out = {};
      opts.fields.forEach(function (f) {
        var $el = $("#md-fields [name='" + f.k + "']");
        out[f.k] = f.type === "checkbox" ? ($el.is(":checked") ? "1" : "") : $.trim($el.val() || "");
      });
      for (var i = 0; i < opts.fields.length; i++) {
        if (opts.fields[i].req && !out[opts.fields[i].k]) return $.Deferred().reject(opts.fields[i].l + " is required.");
      }
      var busy = typeof modalBusyText === "function" ? modalBusyText(out) : modalBusyText;
      setModalBusy(true, busy || { title: "Saving…", sub: "Please wait a moment." });
      return opts.onSave(out);
    };
    openLayer("ccm-modal");
  }
  $(document).on("submit", "#ccm-modal form", function (e) {
    e.preventDefault();
    if (!modalSave || $("#ccm-modal .ccm-modal").hasClass("is-busy")) return;
    $.when(modalSave())
      .done(function () {
        setModalBusy(false);
        closeLayer("ccm-modal");
      })
      .fail(function (m) {
        setModalBusy(false);
        $("#md-err").text(typeof m === "string" ? m : errMsg(m, "Couldn’t save.")).prop("hidden", false);
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
          var why = (x.responseJSON && x.responseJSON.data && x.responseJSON.data.reasons) || [];
          confirmDialog({
            title: why.indexOf("ink_review") > -1 ? "Approve this ink order?" : "Confirm exception approval?",
            sub: errMsg(x, "This order is outside the store’s ordering policy."),
            ok: "Approve & place order",
            busyText: {
              title: "Approving & placing order…",
              sub: "Sending this order to the supplier. This can take a few seconds.",
            },
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
  /**
   * Add a History line. One status change logs two events (WooCommerce's and the manager's),
   * so back-to-back repeats become one line, keeping the later one with any note.
   */
  function pushHistory(hist, h, label) {
    var item = { base: h.text, text: h.text + (h.note ? " — " + h.note : ""), label: label, kind: h.kind };
    var last = hist[hist.length - 1];
    if (last && last.base === h.text) {
      if (h.note) hist[hist.length - 1] = item;
      return;
    }
    hist.push(item);
  }

  var EVENT_TEXT = {
    status_change: function (p) {
      var s = STATUS[p.new] || "", was = STATUS[p.old] || "";
      // pending ↔ awaiting-approval while the order is being placed is not a real "reopen".
      if (s !== "approved" && s !== "rejected" && was !== "approved" && was !== "rejected") return null;
      var note = /^(status changed|status updated( \((supply )?manager\))?)\.?$/i.test($.trim(p.note || "")) ? "" : p.note;
      return {
        text: s === "approved" ? "Approved" : s === "rejected" ? "Rejected" : "Moved back to pending",
        kind: s === "approved" ? "approved" : s === "rejected" ? "rejected" : "edit",
        note: note,
      };
    },
    note: function (p) { return { text: "Note added", kind: "edit", note: p.note }; },
    edit: function () { return { text: "Order details edited", kind: "edit" }; },
    shipment_update: function (p) {
      var bits = [p.carrier, p.tracking ? "tracking " + p.tracking : "", p.edd ? "expected " + p.edd : ""].filter(Boolean).join(" · ");
      return { text: "Delivery details updated", kind: "edit", note: bits || "cleared" };
    },
    receiving_update: function () { return { text: "Receiving updated", kind: "edit" }; },
    return_requested: function (p) { return { text: "Return " + (p["return"] || "") + " requested by the store", kind: "edit", note: [p.reason, p.items ? p.items + " item" + (p.items === 1 ? "" : "s") : ""].filter(Boolean).join(" · ") }; },
    return_approved: function (p) { return { text: "Return " + (p["return"] || "") + " approved", kind: "approved", note: p.note }; },
    return_rejected: function (p) { return { text: "Return " + (p["return"] || "") + " rejected", kind: "rejected", note: p.note }; },
    return_closed: function (p) { return { text: "Return " + (p["return"] || "") + " closed", kind: "edit", note: p.note }; },
    manager_order: function () { return null; }, // Shown as the first History line.
    vendor_po: function (p) { return { text: "Purchase order emailed to " + ((p.vendors || []).join(", ") || "the vendor"), kind: "approved" }; },
    items_edit: function (p) {
      var ch = (p.changes || []).map(function (c) {
        return !c.to ? "removed " + c.name : !c.from ? "added " + c.to + " × " + c.name : c.name + " " + c.from + " → " + c.to;
      }).join("; ");
      return { text: "Items edited: total " + money(+p.before || 0) + " → " + money(+p.after || 0), kind: "edit", note: ch };
    },
  };

  function renderDrawer(o) {
    var n = normOrder(o);
    $("#dr-id").text("#" + n.id);
    $("#dr-status").attr("class", n.pillCls).text(n.statusLabel);
    $("#dr-ink-pill").remove();
    if (n.isInk) $("#dr-status").after("<span class='ccm-pill p-ink' id='dr-ink-pill' style='margin-left:8px'>Ink order</span>");
    $("#dr-placed").text(n.date ? fd(n.date) + " at " + ft(n.date) : "—");
    $("#dr-customer").text(n.customer || "—");
    var p = o.policy;
    $("#dr-policy")
      .prop("hidden", !p)
      .attr("class", "ccm-policy " + (p && p.in_policy ? "is-ok" : p && p.overridden ? "is-override" : "is-flag"))
      .html(
        !p ? "" :
        p.in_policy
          ? "<strong>Within store policy</strong><div>Inside the ordering window, within budget and the monthly order limit.</div>"
          : "<strong>" + (!p.overridden ? "Needs Supply Ops approval" : (p.reasons || []).indexOf("ink_review") > -1 ? "Approved by Supply Ops" : "Approved as an exception") + "</strong>" +
            "<ul>" + (p.messages || []).map(function (m) { return "<li>" + esc(m) + "</li>"; }).join("") + "</ul>"
      );
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
      if (h) pushHistory(hist, h, fdt(ev.created_at));
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
        : "<button type='button' class='ccm-btn primary' data-ccm-close>Done</button>"
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

  /**
   * Amazon vs Grand & Toy card (Reports + Dashboard). s = summary from reports/run.
   * Shares are of total approved spend; "Other" is any other vendor or unassigned lines.
   */
  function vendorSplit(s, opts) {
    opts = opts || {};
    var total = +s.spend || 0;
    var pct = function (v) { return total > 0 ? (v / total) * 100 : 0; };
    var part = function (cls, label, v) {
      return (
        "<div class='ccm-vs-part " + cls + "'><span class='ccm-vs-dot' aria-hidden='true'></span><div>" +
        "<div class='ccm-vs-l'>" + label + "</div><div class='ccm-vs-v'>" + money(v) + "</div>" +
        "<div class='ccm-muted ccm-small'>" + pct(v).toFixed(1) + "% of spend</div></div></div>"
      );
    };
    var lead = s.amazon === s.gt ? "" : (s.amazon > s.gt ? "Amazon" : "Grand & Toy") + " leads by " + money(Math.abs(s.amazon - s.gt));
    return (
      "<div class='ccm-card-h' style='margin-bottom:14px'><div><h2 class='ccm-card-t'>" + esc(opts.title || "Amazon vs Grand & Toy") + "</h2>" +
      "<p class='ccm-card-s'>" + esc(opts.sub || "Approved spend") + (lead ? " · " + esc(lead) : "") + "</p></div>" +
      (opts.link ? "<a class='ccm-link' href='" + esc(opts.link) + "'>" + esc(opts.linkLabel || "Open report") + " →</a>" : "") + "</div>" +
      "<div class='ccm-vs-parts'>" + part("is-amz", "Amazon", s.amazon) + part("is-gt", "Grand &amp; Toy", s.gt) + part("is-oth", "Other / unassigned", s.other) +
      "<div class='ccm-vs-part is-tot'><div><div class='ccm-vs-l'>Total spend</div><div class='ccm-vs-v'>" + money(total) + "</div><div class='ccm-muted ccm-small'>" + (s.orders || 0) + " approved orders</div></div></div></div>" +
      "<div class='ccm-vs-bar' role='img' aria-label='Amazon " + pct(s.amazon).toFixed(1) + "%, Grand and Toy " + pct(s.gt).toFixed(1) + "%, other " + pct(s.other).toFixed(1) + "%'>" +
      (total > 0
        ? "<i class='is-amz' style='width:" + pct(s.amazon) + "%'></i><i class='is-gt' style='width:" + pct(s.gt) + "%'></i><i class='is-oth' style='width:" + pct(s.other) + "%'></i>"
        : "") +
      "</div>" +
      (total > 0 && s.other / total > 0.25
        ? "<p class='ccm-vs-note'>" + pct(s.other).toFixed(0) + "% of spend has no Amazon / Grand &amp; Toy vendor. Assign vendors to products so this split is complete.</p>"
        : "")
    );
  }

  window.ccm = { toast: toast, openOrder: openOrder, setOrderStatus: setOrderStatus, vendorSplit: vendorSplit, get: get };

  /* ======================= screens ======================= */

  var screens = {};

  /* ---------- Pager: 20 rows per page for every table ----------
   * var P = pager("#x-pager", render);   in render():  var page = P.slice(rows, filterKey);
   * A new filterKey (search / tab / filters) goes back to page 1. P.from / P.to / P.total for the footer. */
  var PER_PAGE = 20;
  function pager(sel, onChange) {
    var st = { page: 1, key: null };
    var $el = $(sel);
    var P = { from: 0, to: 0, total: 0 };
    $el.on("click", "[data-pg]", function () {
      st.page = +$(this).data("pg");
      onChange();
      var $t = $el.closest(".ccm-tablewrap");
      if ($t.length && $t.offset().top < window.scrollY + 80) window.scrollTo({ top: $t.offset().top - 90, behavior: "smooth" });
    });
    function btn(n, label, cls, aria) {
      return "<button type='button' class='ccm-pg" + (cls ? " " + cls : "") + "' data-pg='" + n + "'" + (aria ? " aria-label='" + aria + "'" : "") + (cls === "is-on" ? " aria-current='page'" : "") + ">" + label + "</button>";
    }
    function draw(pages) {
      if (pages <= 1) return $el.empty();
      var p = st.page, nums = [1, p - 1, p, p + 1, pages].filter(function (x, i, a) { return x >= 1 && x <= pages && a.indexOf(x) === i; }).sort(function (a, b) { return a - b; });
      var html = p > 1 ? btn(p - 1, "‹ Prev", "is-step", "Previous page") : "<span class='ccm-pg is-step is-off'>‹ Prev</span>";
      nums.forEach(function (n, i) {
        if (i && n - nums[i - 1] > 1) html += "<span class='ccm-pg-gap'>…</span>";
        html += btn(n, n, n === p ? "is-on" : "", "Page " + n);
      });
      html += p < pages ? btn(p + 1, "Next ›", "is-step", "Next page") : "<span class='ccm-pg is-step is-off'>Next ›</span>";
      $el.html("<nav class='ccm-pg-nav' aria-label='Pages'>" + html + "</nav>");
    }
    P.slice = function (rows, key) {
      key = JSON.stringify(key == null ? "" : key);
      if (key !== st.key) { st.key = key; st.page = 1; }
      var n = rows.length, pages = Math.max(1, Math.ceil(n / PER_PAGE));
      if (st.page > pages) st.page = pages;
      var a = (st.page - 1) * PER_PAGE;
      P.total = n; P.from = n ? a + 1 : 0; P.to = Math.min(n, a + PER_PAGE);
      draw(pages);
      return rows.slice(a, a + PER_PAGE);
    };
    P.label = function (noun) { // "Showing 21–40 of 223 stores"
      return P.total ? "Showing " + P.from + "–" + P.to + " of " + P.total + " " + noun : "No " + noun;
    };
    return P;
  }

  // Row-set fingerprint: when filters change the visible rows, go back to page 1.
  function pgKey(rows) { return rows.length + ':' + (rows[0] ? rows[0].id : '') + ':' + (rows.length ? rows[rows.length - 1].id : ''); }

  /* ---------- Orders ---------- */
  screens.orders = function () {
    var S = { orders: [], status: "all", q: "", vendor: "all", month: "all", week: "all", weeks: [], type: "all" };
    var P = pager("#o-pager", function () { render(); });
    var want = new URLSearchParams(location.search).get("status") || "";
    var wantType = new URLSearchParams(location.search).get("type") || "";
    if (/awaiting|pending|on-hold/.test(want)) S.status = "pending";
    else if (LABEL[STATUS[want]]) S.status = STATUS[want];
    if (wantType === "ink" || wantType === "stationery") S.type = wantType;

    /* The ordering weeks come from the province days in Ordering policy. Provinces sharing days are one option,
     * and days no province uses get their own option so no order falls out of every week. */
    function ordersWeeks(rows) {
      var byDays = {}, used = {}, weeks = [];
      rows.forEach(function (w) {
        var k = w.start + "-" + w.end;
        (byDays[k] = byDays[k] || { key: k, start: w.start, end: w.end, names: [] }).names.push(w.name);
        for (var d = w.start; d <= w.end; d++) used[d] = true;
      });
      Object.keys(byDays).map(function (k) { return byDays[k]; })
        .sort(function (a, b) { return a.start - b.start || a.end - b.end; })
        .forEach(function (w) {
          weeks.push({ key: w.key, start: w.start, end: w.end, label: ordinal(w.start) + "–" + ordinal(w.end) + " · " + w.names.join(", ") });
        });
      var left = [];
      for (var d = 1; d <= 31; d++) if (!used[d]) left.push(d);
      if (left.length) {
        var joined = left[left.length - 1] - left[0] === left.length - 1;
        weeks.push({ key: "other", label: "Other days" + (joined ? " · " + ordinal(left[0]) + "–" + ordinal(left[left.length - 1]) : ""), used: used });
      }
      return weeks;
    }
    function inWeek(date) {
      if (S.week === "all") return true;
      var day = +String(date).slice(8, 10);
      return S.weeks.some(function (w) {
        if (w.key !== S.week) return false;
        return w.key === "other" ? !w.used[day] : day >= w.start && day <= w.end;
      });
    }
    function thisMonth() { return ymd(new Date()).slice(0, 7); }
    function monthOptions() {
      var seen = {}; seen[thisMonth()] = true;
      S.orders.forEach(function (o) { if (o.date) seen[String(o.date).slice(0, 7)] = true; });
      $("#o-month").find("option").slice(1).remove();
      Object.keys(seen).sort().reverse().forEach(function (m) {
        var p = m.split("-"), label = new Date(+p[0], +p[1] - 1, 1).toLocaleString("en-CA", { month: "long", year: "numeric" });
        $("#o-month").append($("<option>").val(m).text(label));
      });
      $("#o-month").val(S.month);
      dropdown.refresh("#o-month");
    }

    function base0() {
      var q = S.q.toLowerCase();
      return S.orders.filter(function (o) {
        if (S.type === "ink" && !o.isInk) return false;
        if (S.type === "stationery" && o.isInk) return false;
        if (S.vendor === "none" && o.vendor) return false;
        if (S.vendor !== "all" && S.vendor !== "none" && o.vendor !== S.vendor) return false;
        if (S.month !== "all" && String(o.date).slice(0, 7) !== S.month) return false;
        if (!inWeek(o.date)) return false;
        if (q && (String(o.id) + " " + o.customer).toLowerCase().indexOf(q) < 0) return false;
        return true;
      });
    }
    function view() {
      return base0().filter(function (o) { return S.status === "all" || o.status === S.status; });
    }

    function render() {
      var b = base0(), rows = view(), page = P.slice(rows, [S.status, S.q, S.vendor, S.month, S.week, S.type]);
      $("#o-tabs").html(
        [["all", "All"], ["pending", "Awaiting approval"], ["approved", "Approved"], ["rejected", "Rejected"]]
          .map(function (t) {
            var n = t[0] === "all" ? b.length : b.filter(function (o) { return o.status === t[0]; }).length;
            var on = S.status === t[0];
            return "<button type='button' class='ccm-tab" + (on ? " on" : "") + "' role='tab' aria-selected='" + on + "' data-status='" + t[0] + "'>" + t[1] + " <span class='ccm-count'>" + n + "</span></button>";
          })
          .join("")
      );
      if ($("#o-type").length) $("#o-type").val(S.type);
      $("#o-rows").html(
        page.map(function (o) {
          return (
            "<tr>" +
            "<td><a class='ccm-idlink' href='" + base + "orders/view/" + o.id + "' data-ccm-order='" + o.id + "'>#" + o.id + "</a></td>" +
            "<td>" + esc(o.date ? fd(o.date) : "") + " <span class='ccm-muted ccm-small'>" + esc(o.date ? ft(o.date) : "") + "</span></td>" +
            "<td>" + esc(o.customer) + "</td>" +
            "<td><span class='ccm-pill " + (o.vendor ? "p-neutral" : "p-pending") + "'>" + esc(o.vendor || "Not assigned") + "</span></td>" +
            "<td class='num ccm-strong'>" + money(o.total) + "</td>" +
            "<td><span class='" + o.pillCls + "'>" + esc(o.statusLabel) + "</span>" +
            (o.placedBySupplyOps ? "<span class='ccm-manual-tag'>Manual order</span>" : "") +
            "</td>" +
            "<td class='num'><div class='ccm-rowacts'>" +
            "<a class='ccm-btn sm' href='" + base + "orders/view/" + o.id + "' data-ccm-order='" + o.id + "'>View details</a>" +
            "<button type='button' class='ccm-btn sm danger' data-o-del='" + o.id + "' aria-label='Delete order #" + o.id + "'>Delete</button>" +
            "</div></td>" +
            "</tr>"
          );
        }).join("")
      );
      $("#o-empty").prop("hidden", rows.length > 0);
      $("#o-foot").text(
        P.label("orders") + " · " +
          money(rows.reduce(function (a, o) { return a + o.total; }, 0)) + " total"
      );
    }

    function load() {
      return loadOrders().done(function (orders) {
        S.orders = orders;
        var names = orders.map(function (o) { return o.vendor; }).filter(function (v, i, a) { return v && a.indexOf(v) === i; }).sort();
        setOptions($("#o-vendor"), names, 2);
        $("#o-vendor").val(S.vendor);
        monthOptions();
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
    $("#o-month").on("change", function () { S.month = this.value; render(); });
    $("#o-week").on("change", function () {
      S.week = this.value;
      // A week means this month unless another month is picked.
      if (S.week !== "all" && S.month === "all") { S.month = thisMonth(); $("#o-month").val(S.month); dropdown.refresh("#o-month"); }
      render();
    });
    $(document).off("change.oType").on("change.oType", "#o-type", function () { S.type = this.value || "all"; render(); });
    $("#o-rows").on("click", "[data-o-del]", function (e) {
      e.preventDefault();
      e.stopPropagation();
      var oid = +$(this).data("o-del");
      deleteOrderDialog(oid, function () {
        S.orders = S.orders.filter(function (o) { return o.id !== oid; });
        render();
      });
    });
    $("#o-clear").on("click", function () {
      S.q = ""; S.vendor = "all"; S.month = "all"; S.week = "all"; S.status = "all"; S.type = "all";
      $("#o-q").val(""); $("#o-vendor").val("all"); $("#o-month").val("all"); $("#o-week").val("all"); $("#o-type").val("all");
      ["#o-vendor", "#o-month", "#o-week", "#o-type"].forEach(function (id) { dropdown.refresh(id); });
      render();
    });
    $("[data-ccm-export]").on("click", function () {
      csv("orders", ["Order", "Type", "Date", "Requested by", "Vendor", "Total", "Status"], view().map(function (o) {
        return [o.id, o.isInk ? "Ink" : "Stationery", o.date, o.customer, o.vendor || "Not assigned", o.total.toFixed(2), o.statusLabel];
      }));
    });
    window.ccmReload = function () { load(); };
    load();
    get("policy").done(function (pol) {
      S.weeks = ordersWeeks(pol.province_weeks || []);
      S.weeks.forEach(function (w) { $("#o-week").append($("<option>").val(w.key).text(w.label)); });
      dropdown.refresh("#o-week");
    });
  };

  /* ---------- Products ---------- */
  function productFlags(p) {
    var prices = [p.amz, p.gt].filter(function (x) { return x != null && x > 0; });
    var best = prices.length > 1 ? Math.min.apply(null, prices) : null;
    var flags = [];
    if (!p.orderable) flags.push([p.assigned ? "No vendor price" : "No vendor", "p-rejected"]);
    if (/test|product 1/i.test(p.name)) flags.push(["Test item", "p-neutral"]);
    return { best: best, flags: flags, compare: prices.length > 1 };
  }

  screens.products = function () {
    var params = new URLSearchParams(location.search);
    var S = { products: [], q: params.get("search") || "", vendor: "all", filter: "all" };
    var P = pager("#p-pager", function () { render(); });
    var wantVendor = params.get("vendor") || "";
    $("#p-q").val(S.q);

    function view() {
      var q = S.q.toLowerCase();
      return S.products.filter(function (p) {
        if (q && (p.name + " " + p.sku).toLowerCase().indexOf(q) < 0) return false;
        if (S.vendor === "none" && p.vendor) return false;
        if (S.vendor !== "all" && S.vendor !== "none" && p.vendor !== S.vendor) return false;
        if (S.filter === "issues" && p.orderable) return false;
        if (S.filter === "compare" && !productFlags(p).compare) return false;
        return true;
      });
    }
    function render() {
      var rows = view(), page = P.slice(rows, pgKey(rows));
      $("#p-rows").html(
        page.map(function (p) {
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
            "<td class='num'>" + cell(p.amz) + "</td><td class='num'>" + cell(p.gt) + "</td>" +
            "<td><div class='ccm-flags'>" + f.flags.map(function (x) { return "<span class='ccm-pill " + x[1] + "'>" + x[0] + "</span>"; }).join("") + "</div></td>" +
            "<td class='num'><span class='ccm-actions' style='justify-content:flex-end;flex-wrap:nowrap'>" +
            "<a class='ccm-btn sm' href='" + base + "products/edit/" + p.id + "'>Edit</a>" +
            "<button type='button' class='ccm-btn sm danger' data-del='" + p.id + "'>Delete</button></span></td>" +
            "</tr>"
          );
        }).join("")
      );
      $("#p-empty").prop("hidden", rows.length > 0);
      $("#p-foot").text(P.label("products") + (rows.length !== S.products.length ? " (filtered from " + S.products.length + ")" : ""));
    }

    $.when(loadProducts(), loadVendors())
      .done(function (products, vendors) {
        S.products = products;
        var names = vendors.filter(function (v) { return v.active; }).map(function (v) { return v.name; }).sort();
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
        $("#p-rows").html("<tr><td colspan='7' class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load products")) + "</td></tr>");
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
      csv("products", ["ID", "Product", "SKU", "Vendor", "Amazon", "Grand & Toy", "Can be ordered"], view().map(function (p) {
        return [p.id, p.name, p.sku, p.vendor, p.amz, p.gt, p.orderable ? "Yes" : "No"];
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
      { k: "notes", l: "Notes", type: "textarea", full: true, ph: "Ordering instructions, delivery days, minimums…" },
    ];
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    // Client-side checks so mistakes show in the form before saving.
    function checkVendor(f) {
      if (f.email && !EMAIL_RE.test(f.email)) return "Order email is not a valid email address.";
      if (f.budget_default != null && f.budget_default !== "" && (isNaN(+f.budget_default) || +f.budget_default < 0)) return "Default monthly budget must be a positive number.";
      return "";
    }
    // Vendor budgets are hidden from Supply Manager; only send budget_default when a form has it,
    // so saving details never clears a stored budget.
    function vendorPayload(f) {
      var o = $.extend({}, f);
      if ("budget_default" in o) o.budget_default = o.budget_default === "" ? null : +o.budget_default;
      return o;
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
    // Live-price vendors (Amazon, Grand & Toy) only: one plain line instead of fetch statistics.
    function feedRow(v) {
      var f = v.feed;
      if (!f) return "";
      var more = WCSSM.is_admin ? " <button type='button' class='ccm-linkbtn ccm-vapi' data-api='" + v.id + "'>Details</button>" : "";
      if (!f.last_fetch) return row("Prices", "<span class='ccm-muted'>Not fetched yet</span>" + more);
      if (f.errors && !f.ok) return row("Prices", "<span class='ccm-err-text'>Not updating</span>" + more);
      return row("Prices", "Updated " + esc(fdt(f.last_fetch)) + more);
    }

    function render() {
      $("#v-cards").html(
        S.vendors.slice().sort(function (a, b) { return b.active - a.active; }).map(function (v) {
          var vo30 = ordersOf(v);
          var products = S.products.filter(function (p) { return p.vendor === v.name; }).length;
          var last = vo30.length ? vo30[0].date : "";
          return (
            "<article class='ccm-card ccm-vcard" + (v.active ? "" : " is-inactive") + "'>" +
            "<div class='ccm-vhead'><div class='ccm-vlogo' aria-hidden='true'>" + esc(initials(v.name)) + "</div>" +
            "<div style='flex:1;min-width:0'><div class='ccm-strong' style='font-size:16px'>" + esc(v.name) + "</div></div>" +
            (v.active
              ? "<span class='ccm-pill " + (v.email ? "p-approved" : "p-pending") + "'>" + (v.email ? "Ready" : "Missing contact") + "</span>"
              : "<span class='ccm-pill p-neutral'>Inactive</span>") + "</div>" +
            "<div class='ccm-vstats'>" +
            "<div><div class='ccm-vstat-v'>" + products + "</div><div class='ccm-vstat-l'>Products</div></div>" +
            "<div><div class='ccm-vstat-v'>" + vo30.length + "</div><div class='ccm-vstat-l'>Orders (30d)</div></div>" +
            "<div><div class='ccm-vstat-v'>" + moneyShort(vo30.reduce(function (a, o) { return a + o.total; }, 0)) + "</div><div class='ccm-vstat-l'>Spend (30d)</div></div></div>" +
            "<dl class='ccm-vrows'>" +
            row("Email", v.email ? esc(v.email) : "<span class='ccm-muted'>Not set</span>") +
            row("Last order", last ? esc(fdt(last)) : "<span class='ccm-muted'>None in 30 days</span>") +
            feedRow(v) + "</dl>" +
            "<div class='ccm-actions ccm-vacts'><button type='button' class='ccm-btn sm' data-edit='" + v.id + "'>Edit details</button>" +
            (v.active ? "<a class='ccm-btn sm' href='" + base + "products?vendor=" + encodeURIComponent(v.name) + "'>View products</a>" : "") +
            "<button type='button' class='ccm-btn sm' data-status='" + v.id + "'>" + (v.active ? "Deactivate" : "Activate") + "</button>" +
            "<button type='button' class='ccm-btn sm danger' data-del='" + v.id + "'>Delete</button></div>" +
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
    window.ccmReload = function () { loadAll(); };
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
      if (!WCSSM.is_admin) return;
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
        if (!WCSSM.is_admin) return;
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
      .on("click", "[data-status]", function () {
        var v = find(+$(this).data("status"));
        var on = !v.active;
        confirmDialog({
          title: (on ? "Activate " : "Deactivate ") + v.name + "?",
          sub: on
            ? "Its products and prices come back for stores and Supply Managers."
            : "It will be removed from product forms, filters and pricing. Products with no other active vendor are hidden from stores and Supply Managers until you activate it again. Products that also have an active vendor stay available through that vendor.",
          ok: on ? "Activate" : "Deactivate",
          onOk: function () {
            return req("POST", "vendors/" + v.id + "/status", { active: on }).then(
              function () { toast(v.name + (on ? " activated" : " deactivated")); reloadVendors(); },
              function (x) { toast(errMsg(x, "Couldn’t update vendor")); }
            );
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

  /* ---------- New order (Supply Ops, any store + any active vendor) ---------- */
  screens["orders-new"] = function () {
    var S = { stores: [], vendors: [], vendor: null, items: [], cart: [] };
    var timer = null, seq = 0, policyTimer = null, policySeq = 0;

    function storeSel() {
      var id = +$("#no-store").val();
      return S.stores.filter(function (x) { return x.id === id; })[0] || null;
    }
    function cartQty(id) {
      var l = S.cart.filter(function (x) { return x.id === id; })[0];
      return l ? l.qty : 0;
    }
    function renderResults() {
      if (!S.items.length) {
        $("#no-results").html("<div class='ccm-empty'><strong>No products found</strong>Assign products to this vendor in Products.</div>");
        return;
      }
      $("#no-results").html(S.items.map(function (p) {
        var inCart = cartQty(p.id);
        return "<div class='ccm-no-row'>" +
          (p.image ? "<img class='ccm-no-img' src='" + esc(p.image) + "' alt=''>" : "<span class='ccm-no-img' aria-hidden='true'></span>") +
          "<div class='ccm-no-info'><div class='ccm-strong'>" + esc(p.name) + "</div><div class='ccm-muted'>" + (p.sku ? "SKU " + esc(p.sku) : "No SKU") + "</div></div>" +
          "<div class='ccm-no-price'>" + (p.available ? money(p.price) : "<span class='ccm-muted'>No price</span>") + "</div>" +
          "<button type='button' class='ccm-btn sm " + (inCart ? "" : "primary") + "' data-add='" + p.id + "'" + (p.available ? "" : " disabled") + ">" + (inCart ? "In cart (" + inCart + ")" : "Add to cart") + "</button>" +
          "</div>";
      }).join(""));
    }
    function renderCart() {
      var n = 0, sub = 0;
      $("#no-lines").html(S.cart.map(function (l, i) {
        n += l.qty; sub += l.qty * l.price;
        return "<div class='ccm-no-line'>" +
          "<span class='ccm-no-num'>" + (i + 1) + "</span>" +
          "<div class='ccm-no-info'><div class='ccm-strong'>" + esc(l.name) + "</div>" +
          (l.ref ? "<div class='ccm-muted'>" + esc(l.refLabel) + " " + esc(l.ref) + "</div>" : "") +
          "<div class='ccm-no-meta'><span>" + money(l.price) + " each</span><span class='ccm-no-avail'>Available</span></div>" +
          "<div class='ccm-no-ctrl'><div class='ccm-no-step'>" +
          "<button type='button' data-step='-1' data-id='" + l.id + "' aria-label='Decrease quantity of " + esc(l.name) + "'" + (l.qty < 2 ? " disabled" : "") + ">−</button>" +
          "<input type='number' min='1' max='999' value='" + l.qty + "' data-qty='" + l.id + "' aria-label='Quantity of " + esc(l.name) + "'>" +
          "<button type='button' data-step='1' data-id='" + l.id + "' aria-label='Increase quantity of " + esc(l.name) + "'>+</button></div>" +
          "<b class='ccm-no-ltotal'>" + money(l.qty * l.price) + "</b>" +
          "<button type='button' class='ccm-linkbtn' data-rm='" + l.id + "'>Remove</button></div></div></div>";
      }).join("") || "<p class='ccm-muted'>Your cart is empty.</p>");
      $("#no-count").text(n ? n + (n === 1 ? " item" : " items") : "");
      $("#no-items").text(n);
      $("#no-sub").text(money(sub));
      var live = S.vendor && S.vendor.key;
      $("#no-note").text(live ? "Tax and shipping are calculated at checkout." : "This vendor has no online checkout. The order is approved here, and the vendor is emailed if it has an email address.");
      var st = storeSel();
      $("#no-go").text(live ? "Continue to checkout" : "Place order").prop("disabled", !S.cart.length || !st || !st.ready);
      $("#no-store-warn").prop("hidden", !st || st.ready);
      loadPolicy();
    }
    function loadPolicy() {
      var st = storeSel();
      S.policy = null;
      clearTimeout(policyTimer);
      if (!st) { $("#no-policy").prop("hidden", true).empty(); return; }
      var mine = ++policySeq;
      policyTimer = setTimeout(function () {
        get("new-order/policy", {
          store: st.id,
          amount: S.cart.reduce(function (a, l) { return a + l.qty * l.price; }, 0).toFixed(2),
          ids: S.cart.map(function (l) { return l.id; }).join(","),
        }).done(function (p) {
          if (mine !== policySeq) return;
          S.policy = p;
          renderPolicy(st, p);
        });
      }, 200);
    }
    function renderPolicy(st, p) {
      var after = p.used_amount + p.order_amount, over = p.reasons.indexOf("over_budget") > -1;
      var line = function (label, val, bad) {
        return "<div><dt>" + esc(label) + "</dt><dd" + (bad ? " class='is-bad'" : "") + ">" + val + "</dd></div>";
      };
      var budget = p.ink ? "Ink is not counted" : p.budget > 0 ? esc(money(after)) + " of " + esc(money(p.budget)) : "No limit";
      var orders = p.quota > 0 ? (p.used_orders + 1) + " of " + p.quota + " with this order" : "No limit";
      $("#no-policy").prop("hidden", false).html(
        "<h3>" + esc(st.name) + " this month</h3><dl class='ccm-no-sum'>" +
        line("Budget after this order", budget, over) +
        line("Orders", esc(orders), p.reasons.indexOf("over_quota") > -1) +
        line("Ordering window", esc(p.window_label), p.reasons.indexOf("outside_window") > -1) + "</dl>" +
        (p.in_policy ? "" : "<p class='ccm-no-warn2'>" + p.messages.map(esc).join("<br>") + "</p>")
      );
    }
    function search() {
      if (!S.vendor) return;
      var mine = ++seq;
      get("new-order/products", { vendor: S.vendor.id, search: $.trim($("#no-q").val() || "") })
        .done(function (d) {
          if (mine !== seq) return;
          S.items = d.items || [];
          renderResults();
        })
        .fail(function (x) { $("#no-results").html("<div class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load products")) + "</div>"); });
    }
    function pickVendor() {
      var id = +$("#no-vendor").val();
      S.vendor = S.vendors.filter(function (v) { return v.id === id; })[0] || null;
      S.cart = []; S.items = [];
      $("#no-q").val("");
      $("#no-work").prop("hidden", !S.vendor);
      $("#no-vendor").data("prev", id || "");
      renderCart();
      if (S.vendor) {
        $("#no-results").html("<div class='ccm-empty'>Loading products…</div>");
        search();
      }
    }

    get("new-order/meta").done(function (d) {
      S.stores = d.stores || []; S.vendors = d.vendors || [];
      $("#no-store").html("<option value=''>Choose a store</option>" + S.stores.map(function (x) {
        return "<option value='" + x.id + "'>" + esc(x.name) + (x.code ? " (" + esc(x.code) + ")" : "") + "</option>";
      }).join(""));
      $("#no-vendor").html("<option value=''>Choose a vendor</option>" + S.vendors.map(function (v) {
        return "<option value='" + v.id + "'>" + esc(v.name) + "</option>";
      }).join(""));
      dropdown.refresh("#no-store");
      dropdown.refresh("#no-vendor");
    }).fail(function (x) { toast(errMsg(x, "Couldn’t load stores and vendors")); });

    $("#no-store").on("change", renderCart);
    $("#no-vendor").on("change", function () {
      var $sel = $(this), next = $sel.val();
      if (!S.cart.length) { pickVendor(); return; }
      // One order goes to one vendor, so changing vendor starts a new cart.
      $sel.val($sel.data("prev"));
      dropdown.refresh($sel);
      confirmDialog({
        title: "Change vendor?",
        sub: "One order goes to one vendor, so this clears your cart.",
        ok: "Change vendor",
        onOk: function () {
          $sel.val(next); dropdown.refresh($sel); pickVendor();
          return $.Deferred().resolve().promise();
        },
      });
    });
    $("#no-q").on("input", function () {
      clearTimeout(timer);
      timer = setTimeout(search, 250);
    });
    $(document)
      .on("click", "[data-add]", function () {
        var id = +$(this).data("add");
        var p = S.items.filter(function (x) { return x.id === id; })[0];
        if (!p) return;
        var l = S.cart.filter(function (x) { return x.id === id; })[0];
        if (l) l.qty++;
        else S.cart.push({ id: p.id, name: p.name, price: +p.price, qty: 1, ref: p.ref || "", refLabel: p.ref_label || "" });
        renderResults(); renderCart();
      })
      .on("change", "[data-qty]", function () {
        var id = +$(this).data("qty");
        var q = Math.max(1, Math.min(999, parseInt(this.value, 10) || 1));
        S.cart.forEach(function (l) { if (l.id === id) l.qty = q; });
        renderResults(); renderCart();
      })
      .on("click", "[data-step]", function () {
        var id = +$(this).data("id"), by = +$(this).data("step");
        S.cart.forEach(function (l) { if (l.id === id) l.qty = Math.max(1, Math.min(999, l.qty + by)); });
        renderResults(); renderCart();
      })
      .on("click", "[data-rm]", function () {
        var id = +$(this).data("rm");
        var l = S.cart.filter(function (x) { return x.id === id; })[0];
        confirmDialog({
          title: "Remove " + (l ? l.name : "this item") + "?",
          sub: "This takes it out of your cart.",
          ok: "Remove",
          onOk: function () {
            S.cart = S.cart.filter(function (x) { return x.id !== id; });
            renderResults(); renderCart();
            return $.Deferred().resolve().promise();
          },
        });
      })
      .on("click", "#no-go", function () {
        var $b = $(this), label = $b.text();
        var go = function () {
          $b.prop("disabled", true).text("Please wait…");
          return req("POST", "new-order/checkout", {
            store_id: +$("#no-store").val(),
            vendor_id: S.vendor.id,
            items: S.cart.map(function (l) { return { product_id: l.id, qty: l.qty }; }),
          }).done(function (d) { window.location.href = d.redirect; })
            .fail(function (x) {
              toast(errMsg(x, "Couldn’t create the order"));
              $b.prop("disabled", false).text(label);
            });
        };
        if (S.policy && !S.policy.in_policy) {
          confirmDialog({
            title: "Outside this store’s policy",
            sub: S.policy.messages.join(" ") + " You can still place it as Supply Ops.",
            ok: "Place anyway",
            onOk: function () { go(); return $.Deferred().resolve().promise(); },
          });
          return;
        }
        go();
      });
    renderCart();
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

  /* Ordering window wording, shared by the Ordering policy pop-up and the Edit store page. */
  function fmtDate(v) {
    if (!v) return "";
    var p = String(v).split("-"); // "7 Nov 2026", same as the rest of the portal
    return +p[2] + " " + MONTHS[+p[1] - 1] + " " + p[0];
  }
  function ordinal(n) {
    var s = ["th", "st", "nd", "rd"], v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
  }
  function windowSummary(from, to, repeat, today) {
    if (!from && !to) return { tone: "ok", text: "Open every day — stores can order any day of the month." };
    if (!from || !to) return { tone: "warn", text: "Pick both a start date and an end date." };
    if (to < from) return { tone: "bad", text: "The end date must be on or after the start date." };
    var inside, text;
    if (repeat) {
      var a = +from.slice(8, 10), b = +to.slice(8, 10), d = +today.slice(8, 10);
      inside = a <= b ? d >= a && d <= b : d >= a || d <= b;
      text = "Open from the " + ordinal(a) + " to the " + ordinal(b) + " of every month.";
    } else {
      inside = today >= from && today <= to;
      text = "Open from " + fmtDate(from) + " to " + fmtDate(to) + " only. After that, every order needs approval until you set new dates.";
    }
    return { tone: inside ? "ok" : "warn", text: text + " Today is " + (inside ? "inside" : "outside") + " the window." };
  }
  screens.stores = function () {
    var S = { stores: [], spend: {}, flags: {}, q: "", prov: "all", filter: "all" };
    var P = pager("#s-pager", function () { render(); });

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
      var page = P.slice(rows, pgKey(rows));
      $("#s-rows").html(
        page.map(function (s) {
          var spent = S.spend[s.id] || 0;
          var pct = s.budget > 0 ? Math.min(100, Math.round((spent / s.budget) * 100)) : 0;
          return (
            "<tr>" +
            "<td><div class='ccm-strong'>" + esc(s.name) + "</div><div class='ccm-muted ccm-small'>Code " + esc(s.code || "—") + " · ID " + s.id + "</div></td>" +
            "<td>" + esc(s.city) + "<div class='ccm-muted ccm-small'>" + esc(s.state) + "</div></td>" +
            "<td class='num'>" + (s.quota || 0) + "</td>" +
            "<td class='num'>" + (s.budget ? moneyShort(s.budget) : "—") + (s.isDefault ? "<div class='ccm-muted ccm-small'>Default</div>" : "") + "</td>" +
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
      $("#s-foot").text(P.label("stores") + (rows.length !== S.stores.length ? " (filtered from " + S.stores.length + ")" : ""));
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

    function loadAll() {
      S.spend = {};
      return $.when(loadStores(), loadOrders({ date_from: ymd(monthStart()) }))
      .done(function (stores, orders) {
        S.stores = stores.map(function (s) {
          // Show what applies: the store's own value, or the policy default (tagged "Default").
          return $.extend({}, s, {
            name: plainText(s.name), user_name: plainText(s.user_name || ""),
            budget: s.budget_effective != null ? +s.budget_effective : +s.budget || 0,
            quota: s.quota_effective != null ? +s.quota_effective : s.quota,
            isDefault: !!s.limits_default,
          });
        });
        S.flags = storeFlags(S.stores);
        var ordered = {};
        orders.forEach(function (o) {
          if (!o.storeId) return;
          ordered[o.storeId] = true;
          // Ink doesn't use the stationery budget this column is compared with.
          if (o.status !== "rejected" && !o.isInk) S.spend[o.storeId] = (S.spend[o.storeId] || 0) + o.total;
        });
        summary(Object.keys(ordered).length);
        render();
      })
      .fail(function (x) {
        $("#s-rows").html("<tr><td colspan='8' class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load stores")) + "</td></tr>");
      });
    }
    loadAll();
    window.ccmReload = function () { loadAll(); };

    // Store ordering policy (WCSS_Order_Policy): limits, date window, over-limit handling,
    // and optionally applying the limits to stores picked by province / search / tick.
    function polCard(n, title, desc, body) {
      return (
        "<section class='ccm-pol-card'><header class='ccm-pol-ch'><span class='ccm-pol-num' aria-hidden='true'>" + n + "</span>" +
        "<div><h3>" + title + "</h3><p>" + desc + "</p></div></header>" + body + "</section>"
      );
    }
    function polSwitch(id, title, desc) {
      return (
        "<label class='ccm-pol-switch' for='" + id + "'><input type='checkbox' id='" + id + "'>" +
        "<span class='ccm-pol-track' aria-hidden='true'></span>" +
        "<span class='ccm-pol-switch-t'><b>" + title + "</b><small>" + desc + "</small></span></label>"
      );
    }

    $("#s-policy").on("click", function () {
      get("policy").done(function (pol) {
        var P = { sel: {}, prov: "all", q: "" };
        var provs = S.stores.map(function (s) { return s.state; }).filter(function (x, i, a) { return x && a.indexOf(x) === i; }).sort();
        var html =
          "<form class='ccm-pol' id='pol-form' novalidate>" +
          polCard(1, "Monthly limits", "The default for every store that doesn’t have its own limit.",
            "<div class='ccm-pol-grid'>" +
            "<label class='ccm-pol-field'><span>Monthly budget</span><span class='ccm-pol-money'><i>CA$</i><input class='ccm-input' type='number' min='0' step='0.01' id='pol-budget' placeholder='125'></span></label>" +
            "<label class='ccm-pol-field'><span>Orders per month</span><input class='ccm-input' type='number' min='0' step='1' id='pol-quota' placeholder='1'></label>" +
            "</div>") +
          polCard(2, "Ordering window", "Stores can order without approval between these dates.",
            "<div class='ccm-pol-grid'>" +
            "<label class='ccm-pol-field'><span>Opens on</span><input class='ccm-input' type='date' id='pol-from'></label>" +
            "<label class='ccm-pol-field'><span>Closes on</span><input class='ccm-input' type='date' id='pol-to'></label>" +
            "</div>" +
            "<div class='ccm-pol-row'>" + polSwitch("pol-repeat", "Repeat every month", "Use the same days each month — no need to update the dates.") +
            "<button type='button' class='ccm-pol-ghost' id='pol-allmonth'><svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' aria-hidden='true'><rect x='3' y='5' width='18' height='16' rx='2'/><path d='M3 10h18M8 3v4M16 3v4'/></svg>Open all month</button></div>" +
            "<div class='ccm-pol-sum' id='pol-sum' role='status'></div>") +
          polCard(3, "Province ordering weeks", "Give each province its own days of the month to order.",
            polSwitch("pol-prov-on", "Use province weeks", "Each store follows its province’s days. A store with its own dates keeps them.") +
            "<div class='ccm-pol-weeks' id='pol-weeks'>" +
            "<div class='ccm-pol-week is-head' aria-hidden='true'><span>Province</span><span>First day</span><span>Last day</span></div>" +
            (pol.province_weeks || []).map(function (w) {
              return "<div class='ccm-pol-week'><span class='ccm-pol-week-n'><b>" + esc(w.name) + "</b><small>" + w.stores + (w.stores === 1 ? " store" : " stores") + "</small></span>" +
                "<input class='ccm-input' type='number' min='1' max='31' step='1' id='pol-w-" + w.code + "-s' value='" + w.start + "' aria-label='First day for " + esc(w.name) + "'>" +
                "<input class='ccm-input' type='number' min='1' max='31' step='1' id='pol-w-" + w.code + "-e' value='" + w.end + "' aria-label='Last day for " + esc(w.name) + "'></div>";
            }).join("") + "</div>" +
            "<div class='ccm-pol-sum' id='pol-prov-sum' role='status'></div>" +
            ((pol.province_weeks_other || []).length ? "<div class='ccm-pol-sum is-warn'>" + esc(pol.province_weeks_other.length + (pol.province_weeks_other.length === 1 ? " store doesn’t" : " stores don’t") + " match a province, so they follow the Ordering window above: " + pol.province_weeks_other.map(function (o) { return o.name + " (" + (o.state || "no province") + ")"; }).join(", ") + ". Correct the province on the store.") + "</div>" : "")) +
          polCard(4, "Reminder email", "Tell stores when their ordering days start.",
            polSwitch("pol-remind", "Email stores when their week opens", "Every user of a store gets one email on the first day of its ordering days, with the orders and budget left. Stores open all month, or with no orders left, aren’t emailed.") +
            "<div class='ccm-pol-row'><span class='ccm-pol-sum' id='pol-remind-sum' role='status'></span>" +
            "<button type='button' class='ccm-pol-ghost' id='pol-remind-preview'><svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' aria-hidden='true'><rect x='3' y='5' width='18' height='14' rx='2'/><path d='m3 7 9 6 9-6'/></svg>Email me a preview</button></div>") +
          polCard(5, "If an order breaks a rule", "Over budget, over the order limit, or outside the ordering window.",
            "<div class='ccm-pol-choices' role='radiogroup' aria-label='If an order breaks a rule'>" +
            "<label class='ccm-pol-opt'><input type='radio' name='pol-mode' value='review'><span class='ccm-pol-radio' aria-hidden='true'></span>" +
            "<span class='ccm-pol-opt-t'><b>Send for approval <em>Recommended</em></b><small>The store can still place the order. It waits in Orders until Supply Ops approves or rejects it. Nothing goes to the supplier before that.</small></span></label>" +
            "<label class='ccm-pol-opt'><input type='radio' name='pol-mode' value='block'><span class='ccm-pol-radio' aria-hidden='true'></span>" +
            "<span class='ccm-pol-opt-t'><b>Block at checkout</b><small>The store can’t place the order. Checkout shows the reason, so they can change the cart or wait for the next window.</small></span></label>" +
            "</div>") +
          polCard(6, "Update stores <span class='ccm-pol-opt-tag'>Optional</span>", "Copy this budget and order limit to the stores you choose. Other stores keep their own limits.",
            polSwitch("pol-apply", "Update selected stores", "Choose stores by province, search, or select all.") +
            "<div class='ccm-pol-picker' id='pol-picker' hidden>" +
            "<div class='ccm-pol-tools'>" +
            "<label class='ccm-pol-field'><span>Province</span><select class='ccm-select' id='pol-prov'><option value='all'>All provinces</option>" + provs.map(function (p) { return "<option value='" + esc(p) + "'>" + esc(p) + "</option>"; }).join("") + "</select></label>" +
            "<label class='ccm-pol-field ccm-grow'><span>Search</span><input class='ccm-input' type='search' id='pol-q' placeholder='Store name, code or city…'></label>" +
            "</div>" +
            "<div class='ccm-pol-listhead'><label class='ccm-pol-all'><input type='checkbox' id='pol-all'><span id='pol-all-l'>Select all shown</span></label>" +
            "<span class='ccm-pol-count' id='pol-count'></span><button type='button' class='ccm-pol-link' id='pol-clear'>Clear</button></div>" +
            "<div class='ccm-pol-list' id='pol-list' role='group' aria-label='Stores'></div>" +
            "</div>") +
          "<div class='ccm-pol-err' role='alert' id='pol-err' hidden></div>" +
          "</form>";

        $("#ccm-info .ccm-modal").addClass("ccm-modal--pol");
        $("#if-title").text("Ordering policy");
        $("#if-sub").text("The rules every store order is checked against. Orders that pass all of them are approved and sent to the supplier automatically.");
        $("#if-body").html(html);
        $("#if-foot").html(
          "<span class='ccm-pol-foot-note' id='pol-note'></span>" +
          "<button type='button' class='ccm-btn ccm-pol-cancel' data-ccm-close>Cancel</button>" +
          "<button type='button' class='ccm-btn ccm-pol-save' id='pol-save'>Save policy</button>"
        );

        $("#pol-budget").val(pol.default_monthly_budget);
        $("#pol-quota").val(pol.default_monthly_quota);
        $("#pol-from").val(pol.order_window_from || "");
        $("#pol-to").val(pol.order_window_to || "");
        $("#pol-repeat").prop("checked", pol.order_window_repeat !== false);
        $("#pol-prov-on").prop("checked", !!pol.province_weeks_on);
        var wr = pol.week_reminder || {};
        $("#pol-remind").prop("checked", !!wr.on);
        function paintRemind() {
          var on = $("#pol-remind").is(":checked");
          var ls = wr.last_sent;
          var last = !wr.last ? "Not run yet." : "Last checked " + wr.last.label + ". " +
            (ls ? "Last sent " + ls.label + ": " + ls.emails + " email" + (ls.emails === 1 ? "" : "s") + " to " + ls.stores + " store" + (ls.stores === 1 ? "" : "s") + "." : "No reminders sent yet.");
          var txt = !on ? "Off. No reminder emails are sent." : (wr.on ? "On. Checked every day at 7:00 AM" + (wr.next ? " (next " + wr.next + ")" : "") + ". " + last : "Turns on when you save. Checked every day at 7:00 AM.");
          $("#pol-remind-sum").attr("class", "ccm-pol-sum " + (on ? "is-ok" : "is-off")).text(txt);
        }
        $("#pol-remind").on("change", paintRemind);
        paintRemind();
        $("#pol-remind-preview").on("click", function () {
          var $p = $(this).prop("disabled", true);
          req("POST", "policy/week-reminder/preview", {})
            .done(function (r) { toast("Preview sent to " + r.to + " (" + r.store + ")"); })
            .fail(function (x) { toast(errMsg(x, "Couldn’t send the preview")); })
            .always(function () { $p.prop("disabled", false); });
        });
        $("input[name=pol-mode][value=" + (pol.limit_mode === "block" ? "block" : "review") + "]").prop("checked", true);

        function mode() { return $("input[name=pol-mode]:checked").val() || "review"; }
        function weekDays() {
          return (pol.province_weeks || []).map(function (w) {
            return { code: w.code, name: w.name, start: +$("#pol-w-" + w.code + "-s").val(), end: +$("#pol-w-" + w.code + "-e").val() };
          });
        }
        function weekBad(w) {
          return !(w.start >= 1 && w.end <= 31 && w.start <= w.end && Math.floor(w.start) === w.start && Math.floor(w.end) === w.end);
        }
        function paintWeeks() {
          var on = $("#pol-prov-on").is(":checked"), weeks = weekDays();
          $("#pol-weeks").prop("hidden", !on);
          var $s = $("#pol-prov-sum");
          if (!on) { $s.attr("class", "ccm-pol-sum is-ok").text("Off. Stores follow the Ordering window above."); return; }
          var bad = weeks.filter(weekBad)[0];
          if (bad) { $s.attr("class", "ccm-pol-sum is-bad").text(bad.name + ": choose days from 1 to 31, with the first day before the last."); return; }
          var today = +String(pol.today || ymd(new Date())).slice(8, 10);
          var open = weeks.filter(function (w) { return today >= w.start && today <= w.end; }).map(function (w) { return w.name; });
          var gaps = [], d;
          for (d = 1; d <= 31; d++) {
            var hit = weeks.some(function (w) { return d >= w.start && d <= w.end; });
            if (!hit) {
              var last = gaps[gaps.length - 1];
              if (last && last[1] === d - 1) last[1] = d; else gaps.push([d, d]);
            }
          }
          var text = (open.length ? "Open today: " + open.join(", ") + "." : "No province is open today.") +
            (gaps.length ? " Closed for every province: " + gaps.map(function (g) { return g[0] === g[1] ? ordinal(g[0]) : ordinal(g[0]) + " to " + ordinal(g[1]); }).join(", ") + "." : "");
          $s.attr("class", "ccm-pol-sum is-" + (open.length ? "ok" : "warn")).text(text);
        }
        function shown() {
          var q = P.q.toLowerCase();
          return S.stores.filter(function (s) {
            if (P.prov !== "all" && s.state !== P.prov) return false;
            return !q || (s.name + " " + s.code + " " + s.city).toLowerCase().indexOf(q) >= 0;
          });
        }
        function selCount() { return Object.keys(P.sel).length; }
        function paintSummary() {
          var w = windowSummary($("#pol-from").val(), $("#pol-to").val(), $("#pol-repeat").is(":checked"), pol.today || ymd(new Date()));
          $("#pol-sum").attr("class", "ccm-pol-sum is-" + w.tone).text(w.text);
          $(".ccm-pol-opt").each(function () { $(this).toggleClass("is-on", $(this).find("input").is(":checked")); });
        }
        function paintList() {
          var list = shown();
          $("#pol-list").html(list.length ? list.map(function (s) {
            var cur = (s.budget ? moneyShort(s.budget) : "No budget") + " · " + (s.quota || 0) + "/mo";
            return "<label class='ccm-pol-store'><input type='checkbox' value='" + s.id + "'" + (P.sel[s.id] ? " checked" : "") + ">" +
              "<span class='ccm-pol-store-n'><b>" + esc(s.name) + "</b><small>" + esc([s.code ? "#" + s.code : "", s.city, s.state].filter(Boolean).join(" · ")) + "</small></span>" +
              "<span class='ccm-pol-store-c'>" + esc(cur) + "</span></label>";
          }).join("") : "<div class='ccm-pol-empty'><b>No stores match</b>Try another province or search.</div>");
          var allOn = list.length && list.every(function (s) { return P.sel[s.id]; });
          var someOn = list.some(function (s) { return P.sel[s.id]; });
          $("#pol-all").prop("checked", !!allOn).prop("indeterminate", !allOn && someOn).prop("disabled", !list.length);
          $("#pol-all-l").text("Select all shown (" + list.length + ")");
          $("#pol-count").html("<b>" + selCount() + "</b> of " + S.stores.length + " selected");
          paintSave();
        }
        function paintSave() {
          var n = $("#pol-apply").is(":checked") ? selCount() : 0;
          $("#pol-save").text(n ? "Save and update " + n + " store" + (n === 1 ? "" : "s") : "Save policy");
          $("#pol-note").text(n ? n + " store" + (n === 1 ? "" : "s") + " will get this budget and order limit." : "No store limits will be changed.");
        }
        function err(m) {
          $("#pol-err").text(m).prop("hidden", !m);
          // Scroll only the pop-up body (scrollIntoView would also shift the fixed pop-up itself).
          if (m) { var b = $("#if-body")[0]; b.scrollTo({ top: b.scrollHeight, behavior: "smooth" }); }
        }

        $("#pol-from,#pol-to,#pol-repeat").on("input change", function () { err(""); paintSummary(); });
        $("#pol-prov-on").on("change", function () { err(""); paintWeeks(); });
        $("#pol-weeks").on("input change", "input", function () { err(""); paintWeeks(); });
        $("input[name=pol-mode]").on("change", paintSummary);
        $("#pol-allmonth").on("click", function () { $("#pol-from,#pol-to").val(""); err(""); paintSummary(); });
        $("#pol-apply").on("change", function () { $("#pol-picker").prop("hidden", !this.checked); err(""); paintList(); });
        dropdown($("#pol-prov")[0]);
        $("#pol-prov").on("change", function () { P.prov = this.value; paintList(); });
        $("#pol-q").on("input", function () { P.q = $.trim(this.value); paintList(); });
        $("#pol-all").on("change", function () {
          var on = this.checked;
          shown().forEach(function (s) { if (on) P.sel[s.id] = 1; else delete P.sel[s.id]; });
          paintList();
        });
        $("#pol-clear").on("click", function () { P.sel = {}; paintList(); });
        $("#pol-list").on("change", "input[type=checkbox]", function () {
          if (this.checked) P.sel[this.value] = 1; else delete P.sel[this.value];
          err("");
          paintList();
        });

        $("#pol-save").on("click", function () {
          var budget = $("#pol-budget").val(), quota = $("#pol-quota").val();
          var from = $("#pol-from").val(), to = $("#pol-to").val();
          var apply = $("#pol-apply").is(":checked"), ids = Object.keys(P.sel).map(Number);
          if (budget === "" || +budget < 0) return err("Monthly budget must be 0 or more.");
          if (quota === "" || +quota < 0 || Math.floor(+quota) !== +quota) return err("Orders per month must be a whole number, 0 or more.");
          if (!from !== !to) return err("Pick both a start date and an end date, or click “Open all month”.");
          if (from && to < from) return err("The end date must be on or after the start date.");
          if (apply && !ids.length) return err("Select at least one store, or turn off “Update selected stores”.");
          var weeks = {}, wkBad = weekDays().filter(weekBad)[0];
          if ($("#pol-prov-on").is(":checked") && wkBad) return err(wkBad.name + ": choose days from 1 to 31, with the first day before the last.");
          if (!wkBad) weekDays().forEach(function (w) { weeks[w.code] = { start: w.start, end: w.end }; });
          err("");
          var $b = $(this).prop("disabled", true).text(apply ? "Saving and updating " + ids.length + " store" + (ids.length === 1 ? "" : "s") + "…" : "Saving…");
          req("PUT", "policy", {
            default_monthly_budget: +budget,
            default_monthly_quota: +quota,
            order_window_from: from,
            order_window_to: to,
            order_window_repeat: $("#pol-repeat").is(":checked") ? 1 : 0,
            limit_mode: mode(),
            province_weeks_on: $("#pol-prov-on").is(":checked") ? 1 : 0,
            week_reminder_on: $("#pol-remind").is(":checked") ? 1 : 0,
            province_weeks: wkBad ? undefined : weeks,
          })
            .then(function () {
              if (!apply) return { stores: 0 };
              return req("POST", "stores/apply-limits", { budget: +budget, quota: +quota, store_ids: ids });
            })
            .done(function (r) {
              closeLayer("ccm-info");
              toast(r.stores ? "Policy saved · updated " + r.stores + " store" + (r.stores === 1 ? "" : "s") : "Ordering policy saved");
              if (r.stores) loadAll();
            })
            .fail(function (x) { err(errMsg(x, "Couldn’t save the ordering policy.")); $b.prop("disabled", false); paintSave(); });
        });

        paintSummary();
        paintWeeks();
        paintSave();
        openLayer("ccm-info");
      }).fail(function (x) { toast(errMsg(x, "Couldn’t load the ordering policy")); });
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
    var P = pager("#u-pager", function () { render(); });

    function view() {
      var q = S.q.toLowerCase();
      return S.users.filter(function (u) {
        return !q || (u.name + " " + u.email + " " + u.store_name).toLowerCase().indexOf(q) >= 0;
      });
    }
    function render() {
      var rows = view();
      var page = P.slice(rows, pgKey(rows));
      $("#u-rows").html(
        page.map(function (u) {
          return (
            "<tr>" +
            "<td><div style='display:flex;gap:10px;align-items:center'><div class='ccm-avatar sm' aria-hidden='true'>" + esc(initials(u.name)) + "</div><div><div class='ccm-strong'>" + esc(u.name) + "</div><div class='ccm-muted ccm-small'>ID " + u.id + "</div></div></div></td>" +
            "<td>" + esc(u.email) + "</td>" +
            "<td>" + (u.role === "shop_manager" ? "<span class='ccm-muted'>—</span>" : esc(u.store_name || "Not assigned")) + "</td>" +
            "<td><span class='ccm-pill " + (u.role === "shop_manager" ? "p-neutral" : "p-info") + "'>" + (u.role === "shop_manager" ? "Supply Manager" : "Store employee") + "</span></td>" +
            "<td><span class='ccm-pill " + (u.active ? "p-approved" : "p-rejected") + "'>" + (u.active ? "Active" : "Inactive") + "</span></td>" +
            "<td class='num'><span class='ccm-actions' style='justify-content:flex-end;flex-wrap:nowrap'>" +
            "<button type='button' class='ccm-btn sm' data-edit='" + u.id + "'>Edit</button>" +
            "<button type='button' class='ccm-btn sm danger' data-del='" + u.id + "'>Remove</button></span></td>" +
            "</tr>"
          );
        }).join("")
      );
      $("#u-empty").prop("hidden", rows.length > 0);
      $("#u-foot").text(P.label("users"));
    }
    function loadUsers() {
      return get("users", { all: 1, include_managers: 1 }).done(function (list) {
        S.users = (list || []).map(function (u) {
          return $.extend({}, u, { name: plainText(u.name), store_name: plainText(u.store_name || "") });
        }).sort(function (a, b) { return a.email.localeCompare(b.email); });
        render();
      });
    }
    function fields(edit, user) {
      var f = [
        { k: "name", l: "Full name", req: true, full: true },
        { k: "email", l: "Email", type: "email", req: true, full: true },
      ];
      // Visible while typing: the manager has to pass it on, and a hidden typo would lock the employee out.
      if (!edit) f.push({ k: "password", l: "Password", req: true, full: true, ph: "At least 8 characters", noAutofill: true });
      // Only administrators can change a Supply Manager's password (the server enforces this too).
      else if (WCSSM.is_admin || user.role !== "shop_manager") f.push({ k: "password", l: "New password", full: true, ph: "Leave blank to keep the current password", noAutofill: true });
      f.push(
        {
          k: "store_id", l: "Assigned store", full: true,
          options: [{ v: "", l: "Choose a store" }].concat(S.stores.map(function (s) {
            return { v: s.id, l: s.name + (s.code ? " (" + s.code + ")" : "") };
          })),
        }
      );
      if (!edit) return f;
      // Only administrators can grant or remove Supply Manager access.
      if (WCSSM.is_admin) {
        f.push({ k: "role", l: "Role", options: [
          { v: "store_employee", l: "Store employee" },
          { v: "shop_manager", l: "Supply Manager" },
        ] });
      }
      f.push({ k: "active", l: "Status", options: [
        { v: "1", l: "Active" },
        { v: "0", l: "Inactive" },
      ] });
      return f;
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
        title: "Add store employee", sub: "Each employee orders for one store. Give them this password yourself; it isn’t emailed.", saveLabel: "Add store employee", fields: fields(),
        onSave: function (f) {
          if (f.password.length < 8) return $.Deferred().reject("Password must be at least 8 characters.");
          var parts = f.name.split(/\s+/);
          return req("POST", "users", { first_name: parts.shift(), last_name: parts.join(" "), email: f.email, password: f.password })
            .then(function (u) {
              var linked = f.store_id ? req("PUT", "users/" + u.id, { name: f.name, email: f.email, store_id: +f.store_id }) : $.when();
              return linked.then(function () { return u; });
            })
            .then(function (u) {
              toast("User added: " + f.name + ". Give them their password.");
              loadUsers();
            });
        },
      });
    });
    $("#u-rows")
      .on("click", "[data-edit]", function () {
        var u = find(+$(this).data("edit"));
        formModal({
          title: "Edit user",
          sub: "Store employees order for one store. Supply Managers run this portal and don’t need a store. Inactive users keep their history but can’t sign in.",
          saveLabel: "Save changes", fields: fields(true, u),
          values: { name: u.name, email: u.email, store_id: u.store_id || "", role: u.role, active: u.active ? "1" : "0" },
          onSave: function (f) {
            var body = { name: f.name, email: f.email, store_id: +f.store_id || 0, active: f.active === "1" };
            if (f.role) body.role = f.role;
            if (f.password) {
              if (f.password.length < 8) return $.Deferred().reject("Password must be at least 8 characters.");
              body.password = f.password;
            }
            return req("PUT", "users/" + u.id, body).then(function () {
              toast("User updated: " + f.name + (f.password ? ". Give them the new password." : ""));
              loadUsers();
            });
          },
        });
        // The pop-up's dropdowns open below the field, like the rest of the portal.
        $("#md-fields select.ccm-select").each(function () { dropdown(this); });
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

  /* ---------- Supplier catalogs (Amazon, Grand & Toy): same screen for both ----------
   * cfg: id prefix, vendor label, price key on the product, how to load its products. */
  function supplierScreen(cfg) {
    var S = { products: [] };
    var P = pager("#" + cfg.id + "-pager", function () { render(); });
    function render() {
      $("#" + cfg.id + "-rows").html(
        P.slice(S.products, pgKey(S.products)).map(function (p) {
          var price = p[cfg.priceKey], ok = price != null && Number(price) > 0;
          return (
            "<tr><td class='ccm-strong'>" + esc(p.name) + "</td><td>" + esc(p.sku || "—") + "</td>" +
            "<td class='num'>" + (ok ? money(price) : "—") + "</td>" +
            "<td><span class='ccm-pill " + (ok ? "p-approved" : "p-pending") + "'>" + (ok ? "Up to date" : "Not synced") + "</span></td>" +
            "<td class='num'><a class='ccm-btn sm' href='" + base + "products/edit/" + p.id + "'>Edit</a></td></tr>"
          );
        }).join("") || "<tr><td colspan='5' class='ccm-empty'><strong>No " + esc(cfg.label) + " products</strong>" + esc(cfg.empty) + "</td></tr>"
      );
      $("#" + cfg.id + "-count").text(S.products.length);
      $("#" + cfg.id + "-foot").text(P.label("products"));
      $("#" + cfg.id + "-synced").text(S.products.filter(function (p) { return p[cfg.priceKey] != null && Number(p[cfg.priceKey]) > 0; }).length);
    }
    function lastSync(d) {
      if (!d || !d.last_sync) return "Never";
      return d.last_sync.slice(0, 10) === ymd(new Date()) ? "Today, " + d.last_sync.slice(11, 16) : fdt(d.last_sync);
    }
    function load() {
      cfg.load().done(function (p) { S.products = p; render(); })
        .fail(function (x) { $("#" + cfg.id + "-rows").html("<tr><td colspan='5' class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load products")) + "</td></tr>"); });
      get("prices/sync").done(function (d) { $("#" + cfg.id + "-last").text(lastSync(d)); });
    }
    // One sync job refreshes Amazon and Grand & Toy prices together.
    $("#" + cfg.id + "-sync").on("click", function () {
      var $b = $(this);
      if ($b.prop("disabled")) return;
      $b.prop("disabled", true).text("Syncing…");
      req("POST", "prices/sync")
        .done(function (d) {
          $("#" + cfg.id + "-last").text(lastSync(d));
          toast(d.message || "Price sync finished");
          load();
        })
        .fail(function (x) { toast(errMsg(x, "Price sync failed")); })
        .always(function () { $b.prop("disabled", false).text("Sync prices now"); });
    });
    load();
  }

  /* ---------- Amazon catalog ---------- */
  screens.amazon = function () {
    supplierScreen({
      id: "az", label: "Amazon", priceKey: "amz",
      empty: "Set Amazon as the vendor on a product, or link it to an Amazon item, to see it here.",
      // Assigned to Amazon, or linked to an Amazon item (has an Amazon price).
      load: function () {
        return loadProducts().then(function (all) {
          return all.filter(function (p) { return p.vendor === "Amazon" || p.amz != null; });
        });
      },
    });
  };

  /* ---------- Grand & Toy ---------- */
  screens.gt = function () {
    supplierScreen({
      id: "gt", label: "Grand & Toy", priceKey: "gt",
      empty: "Set Grand & Toy as the vendor on a product to see it here.",
      load: function () { return loadProducts({ vendor: "grand-toy" }); },
    });
  };

  /* ---------- Emails: recipients, manual send, templates, log ---------- */
  screens.emails = function () {
    var S = { events: [], saved: [], list: [], max: 50, logPage: 1, logTotal: 0, open: {}, q: "" };
    var me = String($("[data-ccm-screen]").data("me") || "");
    var DEFAULT_EVENTS = ["order_new", "order_needs_approval", "order_approved", "order_rejected", "order_reopened", "vendor_failed"];
    var EMAIL_RE = /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]+$/;

    function clone(x) { return JSON.parse(JSON.stringify(x)); }
    function dirty() { return JSON.stringify(S.list) !== JSON.stringify(S.saved); }
    function groups() {
      var out = [];
      S.events.forEach(function (e) {
        var g = out.filter(function (x) { return x.name === e.group; })[0];
        if (!g) out.push((g = { name: e.group, events: [] }));
        g.events.push(e);
      });
      return out;
    }
    function splitEmails(s) {
      return String(s || "").split(/[,;\s]+/).map(function (x) { return x.trim(); }).filter(Boolean);
    }
    function frame(html) {
      var $f = $("<iframe class='ccm-eframe' title='Email preview' sandbox='allow-popups allow-popups-to-escape-sandbox'></iframe>");
      $f.attr("srcdoc", html);
      return $f;
    }
    function showEmail(title, sub, html) {
      $("#if-title").text(title);
      $("#if-sub").text(sub || "");
      $("#if-body").empty().append(frame(html));
      $("#if-foot").html("<button type='button' class='ccm-btn primary' data-ccm-close>Close</button>");
      openLayer("ccm-info");
    }

    /* tabs */
    function tab(name) {
      $("[data-etab]").each(function () {
        var on = $(this).data("etab") === name;
        $(this).toggleClass("on", on).attr("aria-selected", on ? "true" : "false");
      });
      $("[data-epanel]").each(function () { $(this).prop("hidden", $(this).data("epanel") !== name); });
      if (name === "log") loadLog();
      try { localStorage.setItem("ccm-email-tab", name); } catch (e) {}
    }
    $(document).on("click", "[data-etab]", function () { tab($(this).data("etab")); });

    /* ----- recipients ----- */
    function key(r) { return String(r.email).toLowerCase(); }
    function avatar(r) {
      if (r.name) return initials(r.name);
      return (String(r.email).split("@")[0].replace(/[^A-Za-z0-9]/g, "").slice(0, 2) || "?").toUpperCase();
    }
    function render() {
      var gs = groups();
      $("#em-count").text(S.list.length);
      var q = S.q.toLowerCase();
      var shown = S.list.map(function (r, i) { return { r: r, i: i }; }).filter(function (x) {
        return !q || (x.r.email + " " + (x.r.name || "")).toLowerCase().indexOf(q) >= 0;
      });
      $("#em-listbar").prop("hidden", S.list.length < 2);
      $("#em-listinfo").text(q ? shown.length + " of " + S.list.length + " addresses" : S.list.length + " addresses");
      if (!S.list.length) {
        $("#em-list").html("<div class='ccm-card ccm-empty'><strong>No email addresses yet</strong>Add an address above, tick the emails it should get, then save.</div>");
      } else if (!shown.length) {
        $("#em-list").html("<div class='ccm-card ccm-empty'><strong>No matching addresses</strong>Try another name or email.</div>");
      } else {
        $("#em-list").html(shown.map(function (x) {
          var r = x.r, i = x.i;
          var on = r.events.length;
          var open = !!S.open[key(r)];
          var sum = gs.map(function (g) {
            var n = g.events.filter(function (e) { return r.events.indexOf(e.key) >= 0; }).length;
            return "<span class='ccm-esum" + (n ? "" : " is-none") + "'><b>" + esc(g.name) + "</b> " + n + "/" + g.events.length + "</span>";
          }).join("");
          var head =
            "<div class='ccm-erow-h'>" +
            "<div class='ccm-erow-id'><div class='ccm-avatar sm' aria-hidden='true'>" + esc(avatar(r)) + "</div>" +
            "<div style='min-width:0'><div class='ccm-strong ccm-ellipsis' title='" + esc(r.email) + "'>" + esc(r.email) + "</div><div class='ccm-muted ccm-small'>" + esc(r.name || "No name") + " · " + on + " of " + S.events.length + " emails on</div></div></div>" +
            "<div class='ccm-erow-act'>" +
            "<label class='ccm-switch'><input type='checkbox' data-active='" + i + "'" + (r.active ? " checked" : "") + "><span aria-hidden='true'></span>" + (r.active ? "Active" : "Paused") + "</label>" +
            "<button type='button' class='ccm-btn sm' data-etest='" + i + "'>Send test</button>" +
            "<button type='button' class='ccm-btn sm' data-eedit='" + i + "'>Edit</button>" +
            "<button type='button' class='ccm-btn sm danger' data-edel='" + i + "'>Remove</button>" +
            "</div></div>" +
            "<div class='ccm-erow-sum'><div class='ccm-esums'>" + sum + "</div>" +
            "<button type='button' class='ccm-btn sm ccm-etoggle' data-etoggle='" + i + "' aria-expanded='" + open + "' aria-controls='eg-" + i + "'>" +
            (open ? "Hide notifications" : "Choose notifications") + "<svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.2' stroke-linecap='round' aria-hidden='true'><path d='" + (open ? "m6 15 6-6 6 6" : "m6 9 6 6 6-6") + "'/></svg></button></div>";
          var body = "<div class='ccm-egroups' id='eg-" + i + "'" + (open ? "" : " hidden") + ">" + gs.map(function (g) {
            var all = g.events.every(function (e) { return r.events.indexOf(e.key) >= 0; });
            return (
              "<fieldset class='ccm-egroup'><legend class='ccm-egroup-h'><span>" + esc(g.name) + "</span>" +
              "<button type='button' class='ccm-linkbtn' data-eall='" + i + "' data-group='" + esc(g.name) + "' data-on='" + (all ? 0 : 1) + "'>" + (all ? "None" : "All") + "</button></legend>" +
              g.events.map(function (e) {
                var id = "ev-" + i + "-" + e.key;
                return (
                  "<label class='ccm-check' for='" + id + "'><input type='checkbox' id='" + id + "' data-ev='" + i + "' value='" + e.key + "'" + (r.events.indexOf(e.key) >= 0 ? " checked" : "") + ">" +
                  "<span><span class='ccm-check-l'>" + esc(e.label) + "</span><span class='ccm-check-h'>" + esc(e.help) + "</span></span></label>"
                );
              }).join("") + "</fieldset>"
            );
          }).join("") + "</div>";
          return "<article class='ccm-card ccm-erow" + (r.active ? "" : " is-off") + (open ? " is-open" : "") + "'>" + head + body + "</article>";
        }).join(""));
      }
      $("#em-expand").text(S.list.length && S.list.every(function (r) { return S.open[key(r)]; }) ? "Collapse all" : "Expand all");
      var d = dirty();
      $("#em-save,#em-discard").prop("disabled", !d);
      $("#em-savebar").toggleClass("is-dirty", d);
      $("#em-savestate").text(d ? "You have unsaved changes" : "All changes saved");
      renderChips();
    }

    function load() {
      return get("emails/settings").done(function (d) {
        S.events = d.events || [];
        S.max = d.max || 50;
        S.saved = clone(d.recipients || []);
        S.list = clone(S.saved);
        render();
        renderTemplates();
      }).fail(function (x) {
        $("#em-list").html("<div class='ccm-card ccm-empty'><strong>Couldn’t load email settings</strong>" + esc(errMsg(x, "Try again.")) + "</div>");
      });
    }

    $("#em-add").on("submit", function (e) {
      e.preventDefault();
      var emails = splitEmails($("#em-new").val());
      var name = $.trim($("#em-newname").val());
      var $err = $("#em-add-err").prop("hidden", true);
      if (!emails.length) return $err.text("Enter at least one email address.").prop("hidden", false);
      var bad = emails.filter(function (x) { return !EMAIL_RE.test(x); });
      if (bad.length) return $err.text("Not a valid email address: " + bad.join(", ")).prop("hidden", false);
      var have = S.list.map(function (r) { return r.email.toLowerCase(); });
      var dup = emails.filter(function (x) { return have.indexOf(x.toLowerCase()) >= 0; });
      if (dup.length) return $err.text("Already in the list: " + dup.join(", ")).prop("hidden", false);
      if (S.list.length + emails.length > S.max) return $err.text("You can add up to " + S.max + " addresses.").prop("hidden", false);
      emails.forEach(function (x) {
        S.list.unshift({ email: x, name: emails.length === 1 ? name : "", active: true, events: DEFAULT_EVENTS.slice() });
        S.open[x.toLowerCase()] = true; // new addresses open so their notifications can be chosen
      });
      S.q = ""; $("#em-search").val("");
      $("#em-new,#em-newname").val("");
      render();
      toast(emails.length + (emails.length === 1 ? " address added" : " addresses added") + " — save to keep");
    });

    $(document).on("click", "[data-etoggle]", function () {
      var r = S.list[+$(this).data("etoggle")];
      S.open[key(r)] = !S.open[key(r)];
      render();
      $("[data-etoggle='" + $(this).data("etoggle") + "']").trigger("focus");
    });
    $("#em-expand").on("click", function () {
      var all = S.list.every(function (r) { return S.open[key(r)]; });
      S.list.forEach(function (r) { S.open[key(r)] = !all; });
      render();
    });
    $("#em-search").on("input", function () { S.q = $.trim(this.value); render(); });

    $(document).on("change", "[data-ev]", function () {
      var r = S.list[+$(this).data("ev")];
      var k = this.value;
      r.events = r.events.filter(function (x) { return x !== k; });
      if (this.checked) r.events.push(k);
      r.events = S.events.map(function (e) { return e.key; }).filter(function (key) { return r.events.indexOf(key) >= 0; });
      render();
      $("#" + this.id).trigger("focus");
    });
    $(document).on("click", "[data-eall]", function () {
      var r = S.list[+$(this).data("eall")];
      var g = String($(this).data("group"));
      var on = +$(this).data("on") === 1;
      var keys = S.events.filter(function (e) { return e.group === g; }).map(function (e) { return e.key; });
      var set = r.events.filter(function (k) { return keys.indexOf(k) < 0; });
      if (on) set = set.concat(keys);
      r.events = S.events.map(function (e) { return e.key; }).filter(function (k) { return set.indexOf(k) >= 0; });
      render();
    });
    $(document).on("change", "[data-active]", function () {
      S.list[+$(this).data("active")].active = this.checked;
      render();
    });
    $(document).on("click", "[data-edel]", function () {
      var i = +$(this).data("edel");
      var r = S.list[i];
      confirmDialog({
        title: "Remove " + r.email + "?",
        sub: "This address will stop getting Supply Manager emails after you save.",
        ok: "Remove",
        onOk: function () { S.list.splice(i, 1); render(); },
      });
    });
    $(document).on("click", "[data-eedit]", function () {
      var i = +$(this).data("eedit");
      var r = S.list[i];
      formModal({
        title: "Edit recipient",
        sub: "Change the address or the name shown in the list.",
        saveLabel: "Update",
        values: { email: r.email, name: r.name },
        fields: [
          { k: "email", l: "Email address", type: "email", req: true, full: true },
          { k: "name", l: "Name (optional)", full: true },
        ],
        onSave: function (v) {
          var email = $.trim(v.email);
          if (!EMAIL_RE.test(email)) return $.Deferred().reject("Enter a valid email address.").promise();
          var taken = S.list.some(function (x, j) { return j !== i && x.email.toLowerCase() === email.toLowerCase(); });
          if (taken) return $.Deferred().reject(email + " is already in the list.").promise();
          if (S.open[key(r)]) { delete S.open[key(r)]; S.open[email.toLowerCase()] = true; }
          r.email = email;
          r.name = $.trim(v.name || "");
          render();
          return $.Deferred().resolve().promise();
        },
      });
    });
    $(document).on("click", "[data-etest]", function () {
      var r = S.list[+$(this).data("etest")];
      if (!r.events.length) return toast("Tick at least one email for " + r.email + " first");
      var $b = $(this).prop("disabled", true).text("Sending…");
      var jobs = r.events.map(function (ev) { return req("POST", "emails/test", { event: ev, to: r.email }); });
      $.when.apply($, jobs)
        .done(function () { toast(r.events.length + " test email" + (r.events.length === 1 ? "" : "s") + " sent to " + r.email); })
        .fail(function (x) { toast(errMsg(x, "Test email failed")); })
        .always(function () { $b.prop("disabled", false).text("Send test"); });
    });

    $("#em-save").on("click", function () {
      var $b = $(this).prop("disabled", true).text("Saving…");
      req("PUT", "emails/recipients", { recipients: S.list })
        .done(function (d) {
          S.saved = clone(d.recipients || []);
          S.list = clone(S.saved);
          render();
          toast("Email settings saved");
        })
        .fail(function (x) {
          toast(errMsg(x, "Couldn’t save email settings"));
          $b.prop("disabled", false);
        })
        .always(function () { $b.text("Save changes"); });
    });
    $("#em-discard").on("click", function () {
      S.list = clone(S.saved);
      render();
      toast("Changes discarded");
    });
    $(window).on("beforeunload", function () {
      if (dirty()) return "You have unsaved email settings.";
    });

    /* ----- compose ----- */
    function renderChips() {
      var active = S.saved.filter(function (r) { return r.active; });
      $("#em-tochips").html(
        active.length
          ? "<span class='ccm-muted ccm-small'>Quick add:</span>" +
              "<button type='button' class='ccm-chip' data-chip='*'>All active recipients (" + active.length + ")</button>" +
              active.slice(0, 8).map(function (r) { return "<button type='button' class='ccm-chip' data-chip='" + esc(r.email) + "' title='" + esc(r.name || r.email) + "'>" + esc(r.email) + "</button>"; }).join("")
          : ""
      );
    }
    $(document).on("click", "[data-chip]", function () {
      var add = $(this).data("chip") === "*" ? S.saved.filter(function (r) { return r.active; }).map(function (r) { return r.email; }) : [String($(this).data("chip"))];
      var cur = splitEmails($("#em-to").val());
      add.forEach(function (x) { if (cur.map(function (c) { return c.toLowerCase(); }).indexOf(x.toLowerCase()) < 0) cur.push(x); });
      $("#em-to").val(cur.join(", ")).trigger("focus");
    });

    $(".ccm-editor-bar").on("mousedown", "button", function (e) { e.preventDefault(); });
    $(".ccm-editor-bar").on("click", "button", function () {
      var cmd = $(this).data("cmd");
      $("#em-body").trigger("focus");
      if (cmd === "createLink") {
        var url = window.prompt("Link address (https://…)", "https://");
        if (!url || url === "https://") return;
        if (!/^(https?:|mailto:)/i.test(url)) url = "https://" + url;
        document.execCommand("createLink", false, url);
      } else {
        document.execCommand(cmd, false, null);
      }
    });
    $("#em-body").on("paste", function (e) {
      // Paste as plain text so Word/Outlook styling doesn't leak into the email.
      var t = (e.originalEvent.clipboardData || window.clipboardData).getData("text/plain");
      e.preventDefault();
      document.execCommand("insertText", false, t);
    });

    $("#em-to,#em-subject").on("input", function () { if ($("#em-result").hasClass("is-err")) $("#em-result").prop("hidden", true); });
    $("#em-body").on("input", function () { if ($("#em-result").hasClass("is-err")) $("#em-result").prop("hidden", true); });
    function result(kind, html) {
      $("#em-result").attr("class", "ccm-result is-" + kind).html(html).prop("hidden", false);
    }
    $("#em-preview").on("click", function () {
      req("POST", "emails/send", { preview: 1, subject: $("#em-subject").val(), message: $("#em-body").html() })
        .done(function (d) { showEmail("Preview", d.subject || "(No subject)", d.html); })
        .fail(function (x) { toast(errMsg(x, "Preview failed")); });
    });
    $("#em-compose").on("submit", function (e) {
      e.preventDefault();
      var to = splitEmails($("#em-to").val());
      var subject = $.trim($("#em-subject").val());
      var text = $.trim($("#em-body").text());
      var bad = to.filter(function (x) { return !EMAIL_RE.test(x); });
      if (!to.length) return result("err", "Add at least one recipient.");
      if (bad.length) return result("err", "Not a valid email address: " + esc(bad.join(", ")));
      if (!subject) return result("err", "Add a subject.");
      if (!text) return result("err", "Write a message.");
      var $b = $("#em-send").prop("disabled", true).text("Sending…");
      $("#em-result").prop("hidden", true);
      req("POST", "emails/send", { to: to.join(","), subject: subject, message: $("#em-body").html() })
        .done(function (d) {
          var failed = d.failed || [];
          result(failed.length ? "warn" : "ok",
            "<strong>Sent to " + d.sent.length + " address" + (d.sent.length === 1 ? "" : "es") + ".</strong> " + esc(d.sent.join(", ")) +
            (failed.length ? "<br><strong>Not sent:</strong> " + esc(failed.map(function (f) { return f.email + " (" + f.error + ")"; }).join(", ")) : ""));
          toast("Email sent");
          if (!failed.length) { $("#em-subject").val(""); $("#em-body").empty(); }
        })
        .fail(function (x) { result("err", esc(errMsg(x, "The email could not be sent."))); })
        .always(function () { $b.prop("disabled", false).text("Send email"); });
    });

    /* ----- templates ----- */
    function renderTemplates() {
      $("#em-tpls").html(groups().map(function (g) {
        return "<div class='ccm-etpl-g'>" + esc(g.name) + "</div>" + g.events.map(function (e) {
          var n = S.saved.filter(function (r) { return r.active && r.events.indexOf(e.key) >= 0; }).length;
          return (
            "<div class='ccm-etpl'><div style='min-width:0'><div class='ccm-strong'>" + esc(e.label) + "</div><div class='ccm-muted ccm-small'>" + esc(e.help) + "</div></div>" +
            "<span class='ccm-pill " + (n ? "p-approved" : "p-neutral") + "'>" + n + " recipient" + (n === 1 ? "" : "s") + "</span>" +
            "<span class='ccm-actions' style='flex-wrap:nowrap'><button type='button' class='ccm-btn sm' data-tprev='" + e.key + "'>Preview</button>" +
            "<button type='button' class='ccm-btn sm' data-ttest='" + e.key + "'>Send test</button></span></div>"
          );
        }).join("");
      }).join(""));
    }
    function testTo() {
      var to = $.trim($("#em-testto").val());
      if (!EMAIL_RE.test(to)) { toast("Enter a valid address to send tests to"); $("#em-testto").trigger("focus"); return ""; }
      return to;
    }
    $(document).on("click", "[data-tprev]", function () {
      var ev = $(this).data("tprev");
      var label = S.events.filter(function (e) { return e.key === ev; })[0].label;
      get("emails/preview", { event: ev })
        .done(function (d) { showEmail(label, "Subject: " + d.subject, d.html); })
        .fail(function (x) { toast(errMsg(x, "Preview failed")); });
    });
    $(document).on("click", "[data-ttest]", function () {
      var to = testTo();
      if (!to) return;
      var $b = $(this).prop("disabled", true).text("Sending…");
      req("POST", "emails/test", { event: $(this).data("ttest"), to: to })
        .done(function () { toast("Test email sent to " + to); })
        .fail(function (x) { toast(errMsg(x, "Test email failed")); })
        .always(function () { $b.prop("disabled", false).text("Send test"); });
    });
    $("#em-testall").on("submit", function (e) {
      e.preventDefault();
      var to = testTo();
      if (!to) return;
      var $b = $(this).find("button").prop("disabled", true).text("Sending…");
      req("POST", "emails/test", { event: "all", to: to })
        .done(function (d) { toast(d.sent + " test emails sent to " + to); })
        .fail(function (x) { toast(errMsg(x, "Test emails failed")); })
        .always(function () { $b.prop("disabled", false).text("Send all tests"); });
    });

    /* ----- log ----- */
    function kind(ctx) {
      ctx = String(ctx || "");
      var ev = function (k) { var e = S.events.filter(function (x) { return x.key === k; })[0]; return e ? e.label : k; };
      if (ctx.indexOf("wcss_event_") === 0) return ["Notification", ev(ctx.slice(11)), "p-info"];
      if (ctx.indexOf("wcss_test_") === 0) return ["Test", ev(ctx.slice(10)), "p-neutral"];
      if (ctx === "wcss_manual") return ["Manual", "Sent from Supply Manager", "p-approved"];
      if (/store_employee/.test(ctx)) return ["Store", "Store employee update", "p-pending"];
      if (/vendor/.test(ctx)) return ["Vendor", "Vendor email", "p-pending"];
      if (/shop_manager|operations/.test(ctx)) return ["Manager", "Before Email settings", "p-neutral"];
      return ["Other", ctx || "WordPress / WooCommerce", "p-neutral"];
    }
    var logTimer;
    function loadLog() {
      $("#em-logrows").html("<tr><td colspan='5' class='ccm-empty'>Loading…</td></tr>");
      get("emails/log", { page: S.logPage, q: $.trim($("#em-logq").val()) })
        .done(function (d) {
          S.logTotal = d.total || 0;
          var rows = d.items || [];
          $("#em-logrows").html(rows.length ? rows.map(function (r) {
            var k = kind(r.context);
            return (
              "<tr><td class='ccm-nowrap'>" + esc(r.created_at) + "</td>" +
              "<td class='ccm-wrapcell'>" + esc(String(r.mailed_to).split(",").join(", ")) + "</td>" +
              "<td class='ccm-wrapcell'>" + esc(r.subject) + "</td>" +
              "<td><span class='ccm-pill " + k[2] + "' title='" + esc(k[1]) + "'>" + esc(k[0]) + "</span></td>" +
              "<td class='num'><button type='button' class='ccm-btn sm' data-logview='" + r.id + "'>View</button></td></tr>"
            );
          }).join("") : "<tr><td colspan='5' class='ccm-empty'><strong>No emails found</strong>Nothing matches that search.</td></tr>");
          var from = S.logTotal ? (S.logPage - 1) * (d.per_page || 25) + 1 : 0;
          $("#em-logfoot").text(S.logTotal ? "Showing " + from + "–" + (from + rows.length - 1) + " of " + S.logTotal : "");
          $("#em-logprev").prop("disabled", S.logPage <= 1);
          $("#em-lognext").prop("disabled", S.logPage * (d.per_page || 25) >= S.logTotal);
        })
        .fail(function (x) { $("#em-logrows").html("<tr><td colspan='5' class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load the log")) + "</td></tr>"); });
    }
    $("#em-logq").on("input", function () { clearTimeout(logTimer); logTimer = setTimeout(function () { S.logPage = 1; loadLog(); }, 300); });
    $("#em-logrefresh").on("click", loadLog);
    $("#em-logprev").on("click", function () { S.logPage = Math.max(1, S.logPage - 1); loadLog(); });
    $("#em-lognext").on("click", function () { S.logPage++; loadLog(); });
    $(document).on("click", "[data-logview]", function () {
      get("emails/log/" + $(this).data("logview"))
        .done(function (r) {
          var html = /<html|<table|<p|<div|<br/i.test(r.message || "") ? r.message : "<pre style='font:14px/1.5 Inter,sans-serif;white-space:pre-wrap;padding:16px'>" + esc(r.message || "") + "</pre>";
          showEmail(r.subject, "To " + String(r.mailed_to).split(",").join(", ") + " · " + r.created_at, html);
        })
        .fail(function (x) { toast(errMsg(x, "Couldn’t open that email")); });
    });

    load();
    var saved = "";
    try { saved = localStorage.getItem("ccm-email-tab") || ""; } catch (e) {}
    var want = (location.hash || "").replace("#", "") || saved;
    if ($("[data-etab='" + want + "']").length) tab(want);
  };

  /* ---------- Order detail page: approve → auto-place, vendor receipt, SM-facing summary ---------- */
  screens.orderView = function () {
    var id = +$("[data-ccm-screen]").data("id");
    $("#od-delete").on("click", function () {
      deleteOrderDialog(id, function () {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        setTimeout(function () { location.href = base + "orders"; }, 500);
      });
    });
    var VEND = { amazon: "Amazon", gt: "Grand & Toy" };
    var pollTimer = null;
    var AUTO_EVT = {
      auto_approved: function () { return { text: "Approved automatically (within store policy)", kind: "approved" }; },
      auto_vendor_submit: function (p) {
        var v = (p.vendors || []).map(function (k) { return VEND[k] || k; }).join(" + ") || "vendor";
        if (p.status === "submitted") return { text: "Sent to " + v, kind: "approved" };
        if (p.status === "woo_only" || p.status === "skipped") return { text: "Not sent — no vendor assigned", kind: "rejected" };
        if (p.status === "manual") return { text: "Manual order required", kind: "edit", note: plainMsg(p.message) };
        if (p.status === "needs_approval") return { text: "Held for Supply Ops approval", kind: "edit" };
        if (p.status === "queued") return { text: "Vendor submit queued", kind: "edit" };
        return { text: "Couldn’t send to " + v, kind: "rejected", note: WCSSM.is_admin || /^No vendor/.test(p.message || "") ? plainMsg(p.message) : "" };
      },
      policy: function (p) {
        if (p.in_policy) return null;
        return { text: (p.reasons || []).indexOf("ink_review") > -1 ? "Ink order — sent for Supply Ops review" : "Outside the store’s ordering policy", kind: "edit" };
      },
    };
    function plainMsg(m) { return String(m || "").replace(/^(AMAZON|GT|GRAND & TOY):\s*/i, ""); }
    /** Why an order wasn't sent. Raw vendor API errors are for the dev team; our own messages are fine for anyone. */
    function sendFailure(d, auto, vendor) {
      var msg = plainMsg((d.vendor_receipt || {}).message || auto.message);
      var fromVendor = auto.results && Object.keys(auto.results).length > 0;
      if (fromVendor && !WCSSM.is_admin) {
        var who = vendor && vendor !== "—" ? vendor : "the vendor";
        return "We couldn’t place this order with " + who + ". Please try again. If it still doesn’t go through, contact your system administrator.";
      }
      return msg;
    }
    function money2(v) { return money(+v || 0); }
    function row(k, v) { return "<div><dt>" + esc(k) + "</dt><dd>" + v + "</dd></div>"; }
    function bar(used, cap) {
      var pct = cap > 0 ? Math.min(100, Math.round((used / cap) * 100)) : 0;
      return "<div class='ccm-od-bar" + (cap > 0 && used > cap ? " is-over" : "") + "'><i style='width:" + pct + "%'></i></div>";
    }
    function receiptMode(d) {
      var r = d.vendor_receipt || {};
      if (r.mode) return r.mode;
      var st = (d.auto_submit_detail && d.auto_submit_detail.status) || "";
      if (st === "woo_only" || st === "skipped") return "failed";
      if (st === "manual") return "manual";
      if (st === "submitted") return "sent";
      if (st === "processing") return "processing";
      if (st === "failed" || st === "partial") return "failed";
      if (st === "needs_approval") return "needs_approval";
      if ((d.amazon_order && d.amazon_order.found) || (d.gt_order && d.gt_order.found)) return "sent";
      return "pending";
    }
    function vendorLabel(d) {
      return VEND[d.vendor] || d.vendor_label || "the vendor";
    }
    function action(d, n) {
      var $a = $("#od-action");
      var auto = d.auto_submit_detail || {};
      var mode = receiptMode(d);
      var vendor = vendorLabel(d);
      var html = "", tone = "";
      var refs = ((d.vendor_receipt && d.vendor_receipt.vendors) || []).map(function (v) { return v.ref; }).filter(Boolean);

      if (n.status === "pending") {
        tone = "warn";
        var msgs = (d.policy && d.policy.messages) || [];
        // An earlier send already failed (e.g. no vendor): don't promise an automatic placement.
        if (mode === "failed") msgs = msgs.concat(sendFailure(d, auto, vendor) || []);
        html =
          "<div class='ccm-od-action-t'><strong>Waiting for your decision</strong>" +
          (msgs.length ? "<ul>" + msgs.map(function (m) { return "<li>" + esc(m) + "</li>"; }).join("") + "</ul>" : "<p>Approve to place this order automatically with " + esc(vendor) + ", or reject it.</p>") + "</div>" +
          "<div class='ccm-od-action-b'><button type='button' class='ccm-btn ccm-od-reject' data-od='rejected'>Reject</button><button type='button' class='ccm-btn ccm-od-approve' data-od='approved'>Approve order</button></div>";
      } else if (n.status === "approved" && mode === "processing") {
        tone = "warn";
        html = "<div class='ccm-od-action-t'><strong>Sending to " + esc(vendor) + "…</strong><p>" + esc(plainMsg(auto.message) || "Placing the order with the supplier — this usually takes a minute.") + "</p></div>";
      } else if (n.status === "approved" && mode === "sent") {
        tone = "ok";
        html = "<div class='ccm-od-action-t'><strong>Sent to " + esc(vendor) + "</strong><p>" +
          (refs.length ? "Vendor reference: " + esc(refs.join(", ")) + ". " : "") +
          (d.edd ? "Expected delivery: " + esc(d.edd) + "." : "The vendor has the order.") +
          ((d.vendor_receipt || {}).manual_note ? " Still to place by hand — " + esc(d.vendor_receipt.manual_note) : "") +
          "</p></div>";
      } else if (n.status === "approved" && mode === "manual") {
        tone = "warn";
        html = "<div class='ccm-od-action-t'><strong>Approved — manual order required</strong><p>Orders for " + esc(plainText(d.vendor_label || "this vendor")) + " aren’t sent automatically. Please contact the vendor directly to place this order.</p></div>";
      } else if (n.status === "approved" && mode === "failed") {
        tone = "bad";
        var why = sendFailure(d, auto, vendor) || "The automatic place with the supplier failed.";
        if (why && !/[.!?]$/.test(why)) why += ".";
        html =
          "<div class='ccm-od-action-t'><strong>Approved, but not sent yet</strong><p>" + esc(why) + "</p></div>" +
          "<div class='ccm-od-action-b'>" +
          "<button type='button' class='ccm-btn ccm-od-approve' id='od-retry'>Retry auto-send</button>" +
          "</div>";
      } else if (n.status === "approved") {
        tone = "warn";
        html = "<div class='ccm-od-action-t'><strong>Approved — preparing supplier send…</strong><p>Waiting for automatic placement to start.</p></div>";
      } else if (n.status === "rejected") {
        tone = "neutral";
        var note = "";
        (d.timeline || []).forEach(function (ev) { if (ev.type === "status_change" && ev.payload && ev.payload.note && STATUS[ev.payload.new] === "rejected") note = ev.payload.note; });
        html = "<div class='ccm-od-action-t'><strong>Rejected</strong><p>" + esc(note || "This order won’t be sent to a vendor.") + "</p></div>";
      }
      $a.attr("class", "ccm-od-action is-" + tone).html(html).prop("hidden", !html);
    }

    function renderReceipt(d) {
      var r = d.vendor_receipt || {};
      var mode = receiptMode(d);
      var $card = $("#od-receipt-card");
      if (mode === "pending" || mode === "needs_approval" || mode === "manual" || ((mode === "processing" || mode === "failed") && !(r.vendors || []).length)) {
        $card.prop("hidden", true);
        $("#od-receipt").empty();
        return;
      }
      var blocks = (r.vendors || []).map(function (v) {
        var ship = v.ship_to || {};
        var shipLines = [ship.name, ship.company, ship.line1, ship.line2, [ship.city, ship.state, ship.postcode].filter(Boolean).join(", "), ship.phone, ship.email].filter(Boolean);
        var lineHtml = (v.lines || []).map(function (ln) {
          return "<div class='ccm-od-receipt-line'><span>" + esc(ln.name || ln.sku || "Item") +
            (ln.sku ? " <em>" + esc(ln.sku) + "</em>" : "") +
            "</span><span>" + (+ln.qty || 0) + (ln.price != null ? " × " + money2(ln.price) : "") + "</span></div>";
        }).join("") || "<p class='ccm-muted ccm-small'>No line snapshot stored.</p>";
        return (
          "<div class='ccm-od-receipt'>" +
          "<div class='ccm-od-receipt-h'><strong>" + esc(v.label) + "</strong>" +
          "<span class='ccm-pill " + (v.placed ? "p-approved" : "p-neutral") + "'>" + esc(v.status || (v.placed ? "placed" : "—")) + "</span></div>" +
          "<dl class='ccm-od-dl'>" +
          row("Vendor reference", esc(v.ref || "—")) +
          row("PO / external ID", esc(v.po_number || "—")) +
          (v.placed_at ? row("Placed", esc(v.placed_at)) : "") +
          (v.order_total != null ? row("Vendor total", money2(v.order_total)) : "") +
          (v.trial_mode ? row("Mode", "Trial / stage") : "") +
          (v.message && (WCSSM.is_admin || v.placed) ? row("Note", esc(plainMsg(v.message))) : "") +
          "</dl>" +
          "<div class='ccm-od-receipt-sec'><div class='ccm-od-addr-h'>Lines sent</div>" + lineHtml + "</div>" +
          (shipLines.length ? "<div class='ccm-od-receipt-sec'><div class='ccm-od-addr-h'>Ship-to on vendor order</div>" + shipLines.map(esc).join("<br>") + "</div>" : "") +
          "</div>"
        );
      }).join("");
      $("#od-receipt-sub").text(mode === "failed" ? "Automatic place did not finish — review the details below." : "What we sent to the supplier, and what came back.");
      $("#od-receipt").html(blocks || "<p class='ccm-muted'>No vendor receipt yet.</p>");
      $card.prop("hidden", false);
    }

    /* ---------- Edit items while the order waits for approval (SOW 1.16) ---------- */
    var D = null, E = null, oeTimer = null;
    function oeLines() { return E.lines.concat(E.added); }
    function oeSub() { return oeLines().reduce(function (a, l) { return a + (l.removed ? 0 : l.qty * l.unit); }, 0); }
    function oeDirty() {
      return E.added.length > 0 || E.lines.some(function (l) { return l.removed || l.qty !== l.orig; });
    }
    function startEdit() {
      E = {
        lines: (D.items || []).map(function (it) {
          return { key: "i" + it.item_id, item_id: it.item_id, name: plainText(it.name), sku: it.sku || "", image: it.image || "",
            vendor: it.vendor_label || VEND[it.vendor] || "", unit: +it.unit_price || 0, qty: +it.qty || 1, orig: +it.qty || 1, removed: false };
        }),
        added: [], results: [], busy: false,
      };
      renderEdit();
      oeSearch("");
    }
    function stopEdit() { E = null; render(D); }
    function oeFind(key) { return oeLines().filter(function (l) { return l.key === key; })[0]; }
    function oeStepper(l) {
      return "<div class='ccm-no-step'>" +
        "<button type='button' data-oe-step='-1' data-oe='" + l.key + "' aria-label='Decrease quantity of " + esc(l.name) + "'" + (l.qty < 2 ? " disabled" : "") + ">−</button>" +
        "<input type='number' min='1' max='999' value='" + l.qty + "' data-oe-qty='" + l.key + "' aria-label='Quantity of " + esc(l.name) + "'>" +
        "<button type='button' data-oe-step='1' data-oe='" + l.key + "' aria-label='Increase quantity of " + esc(l.name) + "'" + (l.qty >= 999 ? " disabled" : "") + ">+</button></div>";
    }
    function oeRow(l, isNew) {
      var tag = isNew ? "<span class='ccm-pill p-approved'>Added</span>" : l.removed ? "<span class='ccm-pill p-rejected'>Removed</span>" : l.qty !== l.orig ? "<span class='ccm-pill p-pending'>Was " + l.orig + "</span>" : "";
      return "<div class='ccm-od-item ccm-oe-item" + (l.removed ? " is-removed" : "") + "'>" +
        "<img class='ccm-od-img' src='" + esc(l.image) + "' alt='' loading='lazy'>" +
        "<div class='ccm-od-item-n'><div class='ccm-strong'>" + esc(l.name) + "</div>" +
        "<div class='ccm-muted ccm-small'>" + [l.sku ? "SKU " + esc(l.sku) : "", l.vendor ? "<span class='ccm-pill p-neutral'>" + esc(l.vendor) + "</span>" : "", tag].filter(Boolean).join(" ") + "</div>" +
        "<div class='ccm-muted ccm-small'>" + money2(l.unit) + " each</div></div>" +
        "<div class='ccm-oe-ctrl'>" + (l.removed ? "" : oeStepper(l)) +
        "<button type='button' class='ccm-linkbtn' data-oe-rm='" + l.key + "'>" + (l.removed ? "Undo" : "Remove") + "</button></div>" +
        "<div class='ccm-od-item-t'>" + (l.removed ? "<s>" + money2(l.orig * l.unit) + "</s>" : money2(l.qty * l.unit)) + "</div></div>";
    }
    function renderEdit() {
      $("#od-edit").prop("hidden", true);
      $("#od-action button").prop("disabled", true);
      var have = {};
      oeLines().forEach(function (l) { have[l.key] = true; });
      var res = E.results.filter(function (p) { return !have["p" + p.id]; });
      var kept = oeLines().filter(function (l) { return !l.removed; }).length;
      $("#od-items").html(
        "<div class='ccm-oe-note'><strong>Editing items</strong>Change quantities, remove items or add approved products. Budget, order limit and vendor are rechecked when you save.</div>" +
        E.lines.map(function (l) { return oeRow(l, false); }).join("") +
        E.added.map(function (l) { return oeRow(l, true); }).join("") +
        "<div class='ccm-oe-add'><label class='ccm-field grow'>Add a product" +
        "<input class='ccm-input' type='search' id='oe-q' placeholder='Search " + (D.is_ink || normOrder(D).isInk ? "ink" : "approved") + " products…' autocomplete='off'></label>" +
        "<div class='ccm-oe-res' id='oe-res'>" + (res.length ? res.map(function (p) {
          return "<div class='ccm-oe-r'><img class='ccm-od-img' src='" + esc(p.image || "") + "' alt='' loading='lazy'>" +
            "<div><div class='ccm-strong'>" + esc(plainText(p.name)) + "</div><div class='ccm-muted ccm-small'>" +
            [p.sku ? "SKU " + esc(p.sku) : "", money2(p.price), p.vendor_label ? esc(p.vendor_label) : ""].filter(Boolean).join(" · ") + "</div></div>" +
            "<button type='button' class='ccm-btn sm' data-oe-add='" + p.id + "'>Add</button></div>";
        }).join("") : "<p class='ccm-muted ccm-small'>" + (E.searching ? "Searching…" : "No matching products.") + "</p>") + "</div></div>"
      );
      var cur = +((D.totals || {}).subtotal) || 0, next = oeSub();
      $("#od-totals").html(
        row("Current subtotal", money2(cur)) +
        "<div class='is-total'><dt>New subtotal</dt><dd>" + money2(next) + "</dd></div>" +
        "<div class='ccm-oe-foot'>" +
        (!kept ? "<p class='ccm-oe-warn'>An order needs at least one item. To cancel it, reject the order instead.</p>" : "<p class='ccm-muted ccm-small'>Tax is recalculated when you save.</p>") +
        "<div class='ccm-oe-btns'><button type='button' class='ccm-btn' id='oe-cancel'>Cancel</button>" +
        "<button type='button' class='ccm-btn primary' id='oe-save'" + (!kept || !oeDirty() || E.busy ? " disabled" : "") + ">" + (E.busy ? "Saving…" : "Save changes") + "</button></div></div>"
      );
    }
    function oeSearch(q) {
      E.searching = true;
      get("orders/" + id + "/edit-products", { search: q }).done(function (r) {
        if (!E) return;
        E.results = (r && r.items) || [];
        E.searching = false;
        var focus = document.activeElement && document.activeElement.id === "oe-q", val = $("#oe-q").val();
        renderEdit();
        if (focus) { $("#oe-q").val(val).trigger("focus"); }
      }).fail(function (x) { if (E) { E.searching = false; E.results = []; renderEdit(); toast(errMsg(x, "Couldn’t load products")); } });
    }
    function oeSave() {
      if (!E || E.busy) return;
      E.busy = true; renderEdit();
      var lines = E.lines.map(function (l) { return { item_id: l.item_id, qty: l.removed ? 0 : l.qty }; })
        .concat(E.added.map(function (l) { return { product_id: l.product_id, qty: l.qty }; }));
      req("PUT", "orders/" + id + "/items", { lines: lines }).then(function (r) {
        E = null;
        var p = (r && r.policy) || {};
        toast("Order #" + id + " updated" + (r && r.changed ? " · new total " + money2(r.after) : "") + (p.in_policy ? " · now within policy" : ""));
        return load();
      }, function (x) {
        if (E) { E.busy = false; renderEdit(); }
        toast(errMsg(x, "Couldn’t save the changes"));
      });
    }
    $(document).off(".oe")
      .on("click.oe", "#od-edit", function () { if (D) startEdit(); })
      .on("click.oe", "#oe-cancel", function () {
        if (E && oeDirty() && !window.confirm("Discard your changes to this order?")) return;
        stopEdit();
      })
      .on("click.oe", "#oe-save", oeSave)
      .on("click.oe", "[data-oe-step]", function () {
        var l = oeFind($(this).data("oe")); if (!l) return;
        l.qty = Math.min(999, Math.max(1, l.qty + +$(this).data("oe-step"))); renderEdit();
      })
      .on("change.oe", "[data-oe-qty]", function () {
        var l = oeFind($(this).data("oe-qty")); if (!l) return;
        var v = parseInt(this.value, 10);
        l.qty = isNaN(v) ? l.qty : Math.min(999, Math.max(1, v)); renderEdit();
      })
      .on("click.oe", "[data-oe-rm]", function () {
        var key = $(this).data("oe-rm"), l = oeFind(key); if (!l) return;
        if (l.item_id) l.removed = !l.removed;
        else E.added = E.added.filter(function (a) { return a.key !== key; });
        renderEdit();
      })
      .on("click.oe", "[data-oe-add]", function () {
        var pid = +$(this).data("oe-add"), p = E.results.filter(function (x) { return x.id === pid; })[0]; if (!p) return;
        E.added.push({ key: "p" + p.id, product_id: p.id, name: plainText(p.name), sku: p.sku || "", image: p.image || "", vendor: p.vendor_label || "", unit: +p.price || 0, qty: 1, orig: 0 });
        renderEdit();
      })
      .on("input.oe", "#oe-q", function () {
        var q = $.trim(this.value);
        clearTimeout(oeTimer);
        oeTimer = setTimeout(function () { if (E) oeSearch(q); }, 250);
      });
    window.addEventListener("beforeunload", function (e) { if (E && oeDirty()) { e.preventDefault(); e.returnValue = ""; } });

    /* ---------- Delivery details (SOW 1.23) ---------- */
    var SHIP_EDIT = false;
    function renderShip(d) {
      var sh = d.shipment || {}, $c = $("#od-ship-card");
      if (!sh.editable && !sh.has) { $c.prop("hidden", true); return; }
      $c.prop("hidden", false);
      $("#od-ship-edit").prop("hidden", !sh.editable || SHIP_EDIT).text(sh.tracking || (sh.edd && sh.edd_source === "manual") ? "Edit" : "Add");
      if (SHIP_EDIT) {
        var opts = "<option value=''>Choose a carrier</option>" + Object.keys(d.carriers || {}).map(function (k) {
          return "<option value='" + esc(k) + "'" + (sh.carrier === k ? " selected" : "") + ">" + esc(d.carriers[k]) + "</option>";
        }).join("");
        $("#od-ship").html(
          "<div class='ccm-ship-form'>" +
          "<label class='ccm-field'>Carrier<select class='ccm-select' id='sh-carrier'>" + opts + "</select></label>" +
          "<label class='ccm-field'>Tracking number<input class='ccm-input' id='sh-tracking' maxlength='64' autocomplete='off' value='" + esc(sh.tracking || "") + "' placeholder='e.g. 1Z999AA10123456784'></label>" +
          "<label class='ccm-field'>Expected delivery<input class='ccm-input' type='date' id='sh-edd' value='" + esc(sh.edd_source === "manual" ? sh.edd : "") + "'></label>" +
          (sh.edd_source === "amazon" ? "<p class='ccm-muted ccm-small'>Amazon expects " + esc(sh.edd_label) + ". Leave the date empty to keep Amazon’s date.</p>" : "") +
          "<p class='ccm-muted ccm-small'>The store gets an email when you save.</p>" +
          "<div class='ccm-ship-btns'><button type='button' class='ccm-btn sm' id='sh-cancel'>Cancel</button><button type='button' class='ccm-btn sm primary' id='sh-save'>Save</button></div></div>"
        );
        return;
      }
      var rows = "";
      rows += row("Expected delivery", sh.edd_label ? esc(sh.edd_label) + (sh.edd_source === "amazon" ? " <span class='ccm-pill p-neutral'>From Amazon</span>" : "") : "<span class='ccm-muted'>Not set</span>");
      rows += row("Carrier", sh.carrier_label ? esc(sh.carrier_label) : "<span class='ccm-muted'>Not set</span>");
      rows += row("Tracking", sh.tracking ? (sh.url ? "<a class='ccm-link' style='padding:0' target='_blank' rel='noopener' href='" + esc(sh.url) + "'>" + esc(sh.tracking) + " ↗</a>" : esc(sh.tracking)) : "<span class='ccm-muted'>Not set</span>");
      $("#od-ship").html("<dl class='ccm-od-dl'>" + rows + "</dl>" + (!sh.editable ? "<p class='ccm-muted ccm-small'>Delivery details can be added once the order is approved.</p>" : ""));
    }
    $(document).off(".odShip")
      .on("click.odShip", "#od-ship-edit", function () { SHIP_EDIT = true; renderShip(D); $("#sh-carrier").trigger("focus"); })
      .on("click.odShip", "#sh-cancel", function () { SHIP_EDIT = false; renderShip(D); })
      .on("click.odShip", "#sh-save", function () {
        var $b = $(this).prop("disabled", true).text("Saving…");
        req("POST", "orders/" + id + "/shipment", { carrier: $("#sh-carrier").val() || "", tracking: $.trim($("#sh-tracking").val() || ""), edd: $("#sh-edd").val() || "" }).then(function (r) {
          SHIP_EDIT = false;
          toast(r && r.shipment && r.shipment.changed === false ? "No changes to save" : "Delivery details saved · the store was emailed");
          return load();
        }, function (x) { $b.prop("disabled", false).text("Save"); toast(errMsg(x, "Couldn’t save the delivery details")); });
      });

    function renderReturns(d) {
      var list = d.returns || [];
      $("#od-ret-card").prop("hidden", !list.length);
      $("#od-ret").html(list.map(function (r) {
        return "<div class='ccm-ret-row'><div><button type='button' class='ccm-idlink' data-od-rt='" + r.id + "'>" + esc(r.number) + "</button>" +
          "<div class='ccm-muted ccm-small'>" + esc(r.reason_label) + " · " + r.items + " item" + (r.items === 1 ? "" : "s") + " · " + esc(fd(r.created_at)) + "</div></div>" + retPill(r) + "</div>";
      }).join(""));
    }
    $(document).off("click.odRet").on("click.odRet", "[data-od-rt]", function () { openReturn(+$(this).data("odRt"), function () { load(); }); });

    function render(d) {
      D = d;
      renderShip(d);
      renderReturns(d);
      var n = normOrder(d), s = d.store || {}, items = d.items || [], t = d.totals || {}, L = d.ledger || {};
      var req = d.requester || {};
      var units = items.reduce(function (a, it) { return a + (+it.qty || 0); }, 0);
      $("#od-status").attr("class", n.pillCls).text(n.statusLabel);
      $("#od-ink-pill").remove();
      if (n.isInk) {
        $("#od-status").after("<span class='ccm-pill p-ink' id='od-ink-pill' style='margin-left:8px'>Ink order</span>");
      }
      $("#od-sub").text(
        (n.isInk ? "Ink order · " : "") +
        "Placed " + (n.date ? fd(n.date) + " at " + ft(n.date) : "—") + " · " +
        [plainText(s.name || "No store"), s.city].filter(Boolean).join(", ") + " · " +
        units + (units === 1 ? " item" : " items") + " · " + money2(t.grand_total != null ? t.grand_total : d.total)
      );
      action(d, n);
      renderReceipt(d);

      // Poll while auto-submit is in flight after Approve.
      if (n.status === "approved" && (receiptMode(d) === "processing" || (!d.auto_submit_detail && receiptMode(d) === "pending"))) {
        if (!pollTimer) pollTimer = setInterval(function () { load(); }, 4000);
      } else if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }

      $("#od-count").text(items.length + (items.length === 1 ? " product" : " products"));
      $("#od-items").html(items.map(function (it) {
        var v = it.vendor_label || VEND[it.vendor] || "";
        return (
          "<div class='ccm-od-item'>" +
          "<img class='ccm-od-img' src='" + esc(it.image || "") + "' alt='' loading='lazy'>" +
          "<div class='ccm-od-item-n'><div class='ccm-strong'>" + esc(plainText(it.name)) + "</div>" +
          "<div class='ccm-muted ccm-small'>" + [it.sku ? "SKU " + esc(it.sku) : "", v ? "<span class='ccm-pill p-neutral'>" + esc(v) + "</span>" : ""].filter(Boolean).join(" ") + "</div></div>" +
          "<div class='ccm-od-item-q'>" + (+it.qty || 0) + " × " + money2(it.unit_price) + "</div>" +
          "<div class='ccm-od-item-t'>" + money2(it.line_total) + "</div></div>"
        );
      }).join("") || "<div class='ccm-empty'>No items.</div>");

      var taxRows = (t.tax_lines || []).map(function (tx) {
        return row(tx.label || "Tax" + (tx.rate_percent ? " (" + tx.rate_percent + "%)" : ""), money2(tx.tax_total));
      }).join("");
      $("#od-totals").html(
        row("Subtotal", money2(t.subtotal)) +
        (+t.discount_total ? row("Discount", "−" + money2(t.discount_total)) : "") +
        row("Shipping", money2(t.shipping_total || 0)) +
        (taxRows || row("Tax", money2(t.total_tax || 0))) +
        (+t.fee_total ? row("Fees", money2(t.fee_total)) : "") +
        "<div class='is-total'><dt>Total</dt><dd>" + money2(t.grand_total != null ? t.grand_total : d.total) + "</dd></div>"
      );

      $("#od-edit").prop("hidden", n.status !== "pending" || !!E);
      if (E) { if (n.status === "pending") renderEdit(); else { E = null; toast("This order is no longer awaiting approval, so it can’t be edited."); } }

      var who = d.placed_by ? d.placed_by + " (Supply Ops) for " + (req.name || n.customer || "the store") : req.name || n.customer || "the store";
      var hist = [{ text: "Placed by " + who, label: fdt(n.date), kind: "new" }];
      (d.timeline || []).slice().reverse().forEach(function (ev) {
        var f = EVENT_TEXT[ev.type] || AUTO_EVT[ev.type];
        var h = f && f(ev.payload || {});
        if (h) pushHistory(hist, h, fdt(ev.created_at));
      });
      $("#od-history").html(hist.map(function (h) {
        return "<div class='ccm-ev'><span class='ccm-evdot ev-" + h.kind + "' aria-hidden='true'></span><div><div class='ccm-evt'>" + esc(h.text) + "</div><div class='ccm-evm'>" + esc(h.label) + "</div></div></div>";
      }).join(""));

      var b = d.shipping && d.shipping.address_1 ? d.shipping : d.billing || {};
      $("#od-store").html(
        row("Store", esc(plainText(s.name || "—"))) +
        (s.code ? row("Store code", esc(s.code)) : "") +
        row("Location", esc([s.city, s.state].filter(Boolean).join(", ") || "—")) +
        row("Ordered by", esc(d.placed_by ? d.placed_by + " (Supply Ops)" : req.name || n.customer || "—")) +
        (req.email ? row("Email", esc(req.email)) : "") +
        (req.phone ? row("Phone", esc(req.phone)) : "") +
        (req.company ? row("Company", esc(req.company)) : "")
      );
      var addr = [
        [b.first_name, b.last_name].filter(Boolean).join(" "),
        b.company, b.address_1, b.address_2,
        [b.city, b.state, b.postcode].filter(Boolean).join(", "),
        b.phone || req.phone || "",
        b.email || req.email || d.billing_email || "",
      ].filter(Boolean);
      $("#od-addr").html(addr.length ? "<div class='ccm-od-addr-h'>Ship to</div>" + addr.map(esc).join("<br>") : "");

      if (L.month) {
        $("#od-month").text(fmtMonth(L.month));
        $("#od-usage").html(
          "<div class='ccm-od-use'><div><span>Orders</span><b>" + (L.used_orders || 0) + (L.quota ? " of " + L.quota : "") + "</b></div>" + bar(L.used_orders || 0, L.quota || 0) + "</div>" +
          "<div class='ccm-od-use'><div><span>Spend</span><b>" + money2(L.used_amount) + (L.budget ? " of " + money2(L.budget) : "") + "</b></div>" + bar(+L.used_amount || 0, +L.budget || 0) + "</div>" +
          "<div class='ccm-od-left'>" + (L.quota ? (L.remaining_orders || 0) + " order" + (L.remaining_orders === 1 ? "" : "s") + " left" : "No order limit") + " · " + (L.budget ? money2(L.remaining_amount) + " budget left" : "No budget limit") + "</div>"
        );
      } else {
        $("#od-usage").html("<div class='ccm-muted ccm-small'>Not linked to a store.</div>");
      }

      if (WCSSM.is_admin) {
        var auto = d.auto_submit_detail || {};
        var vrow = function (k, found, path) {
          return row(k, found ? "<a class='ccm-link' style='padding:0' href='" + base + "orders/" + path + "/" + id + "'>View order</a>" : "<span class='ccm-muted'>Not placed</span>");
        };
        $("#od-tech").html(
          "<div class='ccm-card-h'><div><h2 class='ccm-card-t'>Technical details</h2><p class='ccm-card-s'>Automatic vendor submit and raw API data.</p></div><span class='ccm-pill p-neutral'>Administrators only</span></div>" +
          "<dl class='ccm-od-dl'>" +
          row("Auto-submit", esc(auto.status || "Not run")) +
          row("Selected vendor", esc(VEND[d.vendor] || d.vendor_label || "—")) +
          vrow("Amazon order", d.amazon_order && d.amazon_order.found, "amazon-view") +
          vrow("Grand & Toy order", d.gt_order && d.gt_order.found, "gt-view") +
          (auto.message ? row("Detail", esc(auto.message)) : "") +
          "</dl>" +
          "<div class='ccm-actions'><a class='ccm-btn sm' target='_blank' rel='noopener' href='" + api + "orders/" + id + "?_wpnonce=" + encodeURIComponent(WCSSM.nonce) + "'>Open full API ↗</a>" +
          "<button type='button' class='ccm-btn sm' id='od-json-t' aria-expanded='false'>Show JSON payload</button></div>" +
          "<pre class='ccm-od-json' id='od-json' hidden></pre>"
        ).prop("hidden", false);
        $("#od-json").text(JSON.stringify(d.trigger_payload || auto, null, 2));
      } else {
        $("#od-tech").prop("hidden", true).empty();
      }
    }
    function fmtMonth(ym) { var p = String(ym).split("-"); return MONTHS[+p[1] - 1] + " " + p[0]; }

    function load() {
      return get("orders/" + id).done(render).fail(function (x) {
        $("#od-sub").text("");
        $("#od-items").html("<div class='ccm-empty'><strong>Couldn’t load this order</strong>" + esc(errMsg(x, "Try again.")) + "</div>");
      });
    }
    function setOdBusy(on, copy) {
      $("#od-busy").prop("hidden", !on);
      $("body").toggleClass("ccm-od-is-busy", !!on);
      if (copy) {
        $("#od-busy-title").text(copy.title || "Working…");
        $("#od-busy-sub").text(copy.sub || "Please wait.");
      }
    }
    $(document).off("click.odStatus").on("click.odStatus", "[data-od]", function () {
      var s = $(this).data("od");
      var $b = $("#od-action button").prop("disabled", true);
      if (s === "approved") {
        setOdBusy(true, {
          title: "Approving & placing order…",
          sub: "Confirm if asked, then we’ll send this to the supplier. Please wait.",
        });
      }
      $.when(setOrderStatus(id, s))
        .done(function () { return load(); })
        .always(function () {
          setOdBusy(false);
          $b.prop("disabled", false);
        });
    });
    $(document).off("click.odRetry").on("click.odRetry", "#od-retry", function () {
      var $b = $("#od-action button").prop("disabled", true);
      setOdBusy(true, {
        title: "Retrying supplier send…",
        sub: "Placing this order with Amazon / Grand & Toy. Please wait.",
      });
      req("POST", "orders/" + id + "/retry-vendor-submit", {}).then(
        function () { toast("Retrying vendor send for order #" + id); return load(); },
        function (x) { toast(errMsg(x, "Couldn’t retry vendor send")); }
      ).always(function () {
        setOdBusy(false);
        $b.prop("disabled", false);
      });
    });
    $(document).off("click.odJson").on("click.odJson", "#od-json-t", function () {
      var open = $("#od-json").prop("hidden");
      $("#od-json").prop("hidden", !open);
      $(this).attr("aria-expanded", open ? "true" : "false").text(open ? "Hide JSON payload" : "Show JSON payload");
    });
    window.ccmReload = function () { load(); };
    load();
  };

  /* ---------- Edit store: details, employee, and this store's own ordering rules ---------- */
  screens.storeEdit = function () {
    var id = +$("[data-ccm-screen]").data("id");
    var P = null; // GET stores/{id}/policy
    var snap = "";
    var MODE_L = { review: "Send for approval", block: "Block at checkout" };

    function v(sel) { return $.trim($(sel).val() || ""); }
    function state() {
      return JSON.stringify({
        name: v("#se-name"), code: v("#se-code"), city: v("#se-city"), state: v("#se-state"), address: v("#se-address"),
        postcode: v("#se-postcode"), country: v("#se-country") || "CA",
        phone: v("#se-phone"), hours: v("#se-hours"), user: v("#se-user"),
        ld: $("#se-limits-default").is(":checked"), budget: v("#se-budget"), quota: v("#se-quota"),
        wd: $("#se-win-default").is(":checked"), from: v("#se-from"), to: v("#se-to"), rep: $("#se-repeat").is(":checked"),
        mode: $("input[name=se-mode]:checked").val() || "",
      });
    }
    function err(m) {
      $("#se-err").text(m).prop("hidden", !m);
      if (m) $("html, body").animate({ scrollTop: $("#se-err").offset().top - 120 }, 200);
    }
    function row(label, value, own) {
      return "<div class='ccm-se-row'><dt>" + esc(label) + "</dt><dd><span>" + esc(value) + "</span><em class='" + (own ? "is-own" : "") + "'>" + (own ? "This store" : "Default") + "</em></dd></div>";
    }
    function paint() {
      if (!P) return;
      var d = P.defaults, ld = $("#se-limits-default").is(":checked"), wd = $("#se-win-default").is(":checked");
      var mode = $("input[name=se-mode]:checked").val() || "";
      $("#se-limits").toggleClass("is-off", ld).find("input").prop("disabled", ld);
      if (ld) { $("#se-budget").val(d.budget); $("#se-quota").val(d.quota); }
      $("#se-win").prop("hidden", wd);
      $(".ccm-se-choices .ccm-pol-opt").each(function () { $(this).toggleClass("is-on", $(this).find("input").is(":checked")); });

      var w = wd
        ? { tone: "ok", text: "Following the policy: stores can order " + d.window_label + "." }
        : windowSummary(v("#se-from"), v("#se-to"), $("#se-repeat").is(":checked"), P.today);
      $("#se-win-sum").attr("class", "ccm-pol-sum is-" + w.tone).text(w.text);

      var budget = ld ? d.budget : +v("#se-budget") || 0;
      var quota = ld ? d.quota : +v("#se-quota") || 0;
      var winText = wd ? d.window_label : (v("#se-from") && v("#se-to")
        ? ($("#se-repeat").is(":checked") ? "the " + ordinal(+v("#se-from").slice(8)) + "–" + ordinal(+v("#se-to").slice(8)) + " of every month" : fmtDate(v("#se-from")) + " – " + fmtDate(v("#se-to")))
        : "—");
      $("#se-sum").html(
        row("Monthly budget", budget ? moneyShort(budget) : "No limit", !ld) +
        row("Orders per month", quota ? String(quota) : "No limit", !ld) +
        row("Ordering window", winText.charAt(0).toUpperCase() + winText.slice(1), !wd) +
        row("If a rule is broken", MODE_L[mode || d.mode], !!mode)
      );
      var spent = P.usage.spent, pct = budget > 0 ? Math.min(100, Math.round((spent / budget) * 100)) : 0;
      $("#se-usage").html(
        "<div class='ccm-se-usage-h'>This month so far</div>" +
        "<div class='ccm-se-usage-v'><b>" + moneyShort(spent) + "</b> of " + (budget ? moneyShort(budget) : "no limit") + " · <b>" + P.usage.orders + "</b> of " + (quota || "∞") + " orders</div>" +
        "<div class='ccm-progress'><div style='width:" + pct + "%'></div></div>"
      );
      var dirty = state() !== snap;
      $("#se-save").prop("disabled", !dirty);
      $("#se-bar").toggleClass("is-dirty", dirty);
      $("#se-bar-t").text(dirty ? "You have unsaved changes" : "No changes yet");
    }

    $("#se-form").on("input change", "input, select, textarea", function () { err(""); paint(); });
    $(window).on("beforeunload", function () { if (snap && state() !== snap) return "You have unsaved changes."; });

    $.when(get("stores/" + id), get("users", { store_id: id }), get("stores/" + id + "/policy"), loadStores())
      .done(function (s, u, pol, all) {
        s = s[0]; P = pol[0];
        var users = Array.isArray(u[0]) ? u[0] : (u[0] && u[0].items) || [];
        $("#se-title").text(plainText(s.name || "Edit store"));
        $("#se-sub").text("Code " + (s.code || "—") + " · " + [s.city, s.state].filter(Boolean).join(", ") + " · ID " + id);
        $("#se-name").val(plainText(s.name || "")); $("#se-code").val(s.code || ""); $("#se-city").val(s.city || ""); $("#se-state").val(s.state || "");
        $("#se-address").val(s.address || ""); $("#se-postcode").val(s.postcode || ""); $("#se-country").val(s.country || "CA");
        $("#se-phone").val(s.phone || ""); $("#se-hours").val(s.open_hours || "");
        var provs = (all || []).map(function (x) { return x.state; }).filter(function (x, i, a) { return x && a.indexOf(x) === i; }).sort();
        $("#se-provs").html(provs.map(function (p) { return "<option value='" + esc(p) + "'>"; }).join(""));

        var $u = $("#se-user").html("<option value=''>Choose a store employee</option>");
        users.forEach(function (x) {
          $u.append($("<option>").val(String(x.id)).text((x.name || "User #" + x.id) + (x.email ? " — " + x.email : "") + (x.current ? " (current)" : "")));
        });
        var cur = +s.user_id || 0;
        if (cur && !$u.find("option[value='" + cur + "']").length) {
          $u.append($("<option>").val(String(cur)).text((s.user_name || "User #" + cur) + (s.user_email ? " — " + s.user_email : "") + " (current)"));
        }
        $u.val(cur ? String(cur) : "");
        dropdown($u[0]);

        var st = P.store, d = P.defaults;
        $("#se-limits-default-h").text("Follow the ordering policy: " + moneyShort(d.budget) + " and " + d.quota + " order" + (d.quota === 1 ? "" : "s") + " per month.");
        $("#se-win-default-h").text("Follow the ordering policy: " + d.window_label + ".");
        $("#se-mode-default-h").text("Use the same rule as other stores — currently “" + MODE_L[d.mode] + "”.");
        $("#se-limits-default").prop("checked", st.budget === "" && st.quota === "");
        $("#se-budget").val(st.budget === "" ? d.budget : st.budget);
        $("#se-quota").val(st.quota === "" ? d.quota : st.quota);
        $("#se-win-default").prop("checked", !st.window_from);
        $("#se-from").val(st.window_from || ""); $("#se-to").val(st.window_to || "");
        $("#se-repeat").prop("checked", st.window_repeat !== false);
        $("input[name=se-mode][value='" + (st.limit_mode || "") + "']").prop("checked", true);
        paint();
        snap = state();
        paint();
      })
      .fail(function (x) { err(errMsg(x, "Couldn’t load this store.")); $("#se-sub").text(""); });

    $("#se-save").on("click", function () {
      var ld = $("#se-limits-default").is(":checked"), wd = $("#se-win-default").is(":checked");
      if (!v("#se-name")) return err("Store name is required.");
      if (!v("#se-code")) return err("Store code is required.");
      if (!v("#se-city")) return err("City is required.");
      if (!v("#se-state")) return err("Province is required.");
      if (!v("#se-address")) return err("Street address is required.");
      if (!v("#se-postcode")) return err("Postal code is required.");
      if (!v("#se-phone")) return err("Phone number is required.");
      if (!v("#se-hours")) return err("Open hours are required.");
      if (!(+v("#se-user") > 0)) return err("Choose the store employee.");
      if (!ld && (v("#se-budget") === "" || +v("#se-budget") < 0)) return err("Monthly budget must be 0 or more.");
      if (!ld && (v("#se-quota") === "" || +v("#se-quota") < 0 || Math.floor(+v("#se-quota")) !== +v("#se-quota"))) return err("Orders per month must be a whole number, 0 or more.");
      if (!wd && (!v("#se-from") || !v("#se-to"))) return err("Choose both an opening date and a closing date, or turn on “Use the default window”.");
      if (!wd && v("#se-to") < v("#se-from")) return err("The closing date must be on or after the opening date.");
      err("");
      var $b = $(this).prop("disabled", true).text("Saving…");
      req("PUT", "stores/" + id, {
        name: v("#se-name"), code: v("#se-code"), city: v("#se-city"), state: v("#se-state"),
        address: v("#se-address"), postcode: v("#se-postcode"), country: v("#se-country") || "CA",
        phone: v("#se-phone"), open_hours: v("#se-hours"), user_id: +v("#se-user"),
      })
        .then(function () {
          return req("PUT", "stores/" + id + "/policy", {
            use_default_limits: ld, budget: v("#se-budget"), quota: v("#se-quota"),
            use_default_window: wd, window_from: v("#se-from"), window_to: v("#se-to"), window_repeat: $("#se-repeat").is(":checked") ? 1 : 0,
            limit_mode: $("input[name=se-mode]:checked").val() || "",
          });
        })
        .done(function (pol) {
          P = pol;
          $("#se-title").text(v("#se-name"));
          snap = state();
          paint();
          toast("Store saved");
        })
        .fail(function (x) { err(errMsg(x, "Couldn’t save this store.")); })
        .always(function () { $b.text("Save changes"); paint(); });
    });
  };

  /* ---------- Dashboard: Amazon vs G&T + ordering coverage (same engine as Reports) ---------- */
  screens.dashboard = function () {
    var month = new Date().toLocaleString("en-CA", { month: "long" });
    get("reports/run", { type: "vendors", range: "this_month" })
      .done(function (d) {
        $("#dash-split").html(vendorSplit(d.summary, {
          title: "This month: Amazon vs Grand & Toy",
          sub: "Approved spend, " + d.period.replace(/^This month · /, ""),
          link: base + "reports?type=vendors&range=this_month",
          linkLabel: "Vendor report",
        }));
      })
      .fail(function () { $("#dash-split").html("<div class='ccm-empty'>Couldn’t load the vendor split.</div>"); });

    get("reports/run", { type: "coverage", range: "this_month" })
      .done(function (d) {
        var rows = d.rows || [];
        var yes = rows.filter(function (r) { return r.ordered === "Ordered"; });
        var no = rows.filter(function (r) { return r.ordered !== "Ordered"; });
        var pct = rows.length ? (yes.length / rows.length) * 100 : 0;
        $("#dash-coverage").html(
          "<div class='ccm-cov-top'><div><span class='ccm-cov-n'>" + yes.length + "</span><span class='ccm-muted'> of " + rows.length + " stores ordered in " + esc(month) + "</span></div>" +
          "<span class='ccm-pill " + (pct >= 80 ? "p-approved" : pct >= 40 ? "p-pending" : "p-neutral") + "'>" + pct.toFixed(0) + "%</span></div>" +
          "<div class='ccm-vs-bar' style='margin:10px 0 16px'><i class='is-ok' style='width:" + pct + "%'></i></div>" +
          (no.length
            ? "<div class='ccm-card-s' style='margin-bottom:6px'>Not ordered yet</div><ul class='ccm-cov-list'>" + no.slice(0, 6).map(function (r) {
                return "<li><span class='ccm-strong'>" + esc(r.store) + "</span><span class='ccm-muted ccm-small'>" + esc([r.code ? "#" + r.code : "", r.city].filter(Boolean).join(" · ")) + "</span></li>";
              }).join("") + "</ul>" + (no.length > 6 ? "<div class='ccm-muted ccm-small' style='margin-top:8px'>+ " + (no.length - 6) + " more stores</div>" : "")
            : "<div class='ccm-empty'><strong>Every store has ordered</strong>Nothing left for this month.</div>")
        );
      })
      .fail(function () { $("#dash-coverage").html("<div class='ccm-empty'>Couldn’t load store coverage.</div>"); });
  };

  /* ---------- Dropdown: styled list that always opens below the field ----------
   * macOS draws native <select> menus over the field; this replaces the menu, not the data.
   * The real <select> stays (hidden) and keeps the value, so .val() and "change" work as before.
   * Call dropdown(el) once after options exist, and dropdown.refresh(el) after changing them / the value. */
  function dropdown(sel) {
    var $s = $(sel);
    if ($s.data("dd")) return dropdown.refresh(sel);
    var uid = "dd" + Math.random().toString(36).slice(2, 8);
    var $btn = $("<button type='button' class='ccm-dd-btn' aria-haspopup='listbox' aria-expanded='false'><span class='ccm-dd-val'></span><span class='ccm-dd-chev' aria-hidden='true'></span></button>");
    var $panel = $("<div class='ccm-dd-panel' hidden><div class='ccm-dd-search' hidden><input type='search' class='ccm-dd-q' placeholder='Search…' aria-label='Search options'></div><div class='ccm-dd-list' role='listbox' id='" + uid + "'></div></div>");
    $btn.attr("aria-controls", uid);
    var $wrap = $("<div class='ccm-dd'></div>").append($btn, $panel);
    $s.addClass("ccm-dd-native").attr({ tabindex: "-1", "aria-hidden": "true" }).before($wrap);
    $s.data("dd", { $wrap: $wrap, $btn: $btn, $panel: $panel });
    // Stay in sync when a screen rebuilds the options.
    if (window.MutationObserver) new MutationObserver(function () { dropdown.refresh(sel); }).observe(sel, { childList: true });

    function items() { return $panel.find(".ccm-dd-opt:not([hidden])"); }
    function close() {
      $panel.prop("hidden", true);
      $wrap.removeClass("is-open is-up");
      $btn.attr("aria-expanded", "false");
      $(document).off("." + uid);
    }
    function open() {
      $(".ccm-dd.is-open").not($wrap).each(function () { $(this).find(".ccm-dd-btn").trigger("ccm-dd-close"); });
      dropdown.refresh(sel);
      $panel.find(".ccm-dd-q").val("");
      items().prop("hidden", false);
      $panel.prop("hidden", false);
      $wrap.addClass("is-open");
      $btn.attr("aria-expanded", "true");
      // Open upwards only when there isn't room below.
      var r = $btn[0].getBoundingClientRect(), below = window.innerHeight - r.bottom;
      $wrap.toggleClass("is-up", below < Math.min(340, $panel.outerHeight() + 16) && r.top > below);
      var $cur = $panel.find(".ccm-dd-opt.is-sel");
      setActive($cur.length ? $cur : items().first());
      if ($s.find("option").length > 10) $panel.find(".ccm-dd-q").trigger("focus");
      $(document).on("mousedown." + uid + " touchstart." + uid, function (e) { if (!$wrap[0].contains(e.target)) close(); });
      $(window).on("resize." + uid, close);
    }
    function setActive($o) {
      $panel.find(".ccm-dd-opt.is-active").removeClass("is-active");
      if (!$o || !$o.length) return;
      $o.addClass("is-active");
      $btn.attr("aria-activedescendant", $o.attr("id"));
      var list = $panel.find(".ccm-dd-list")[0], el = $o[0];
      if (el.offsetTop < list.scrollTop) list.scrollTop = el.offsetTop;
      else if (el.offsetTop + el.offsetHeight > list.scrollTop + list.clientHeight) list.scrollTop = el.offsetTop + el.offsetHeight - list.clientHeight;
    }
    function choose($o) {
      if (!$o || !$o.length) return;
      var v = String($o.data("v"));
      close();
      $btn.trigger("focus");
      if (String($s.val()) !== v) { $s.val(v).trigger("change"); }
      dropdown.refresh(sel);
    }
    function move(step) {
      var $all = items(), i = $all.index($all.filter(".is-active"));
      setActive($all.eq(Math.max(0, Math.min($all.length - 1, i + step))));
    }

    $btn.on("click", function (e) { e.preventDefault(); $wrap.hasClass("is-open") ? close() : open(); });
    $btn.on("ccm-dd-close", close);
    $btn.on("keydown", function (e) {
      var k = e.key;
      if (!$wrap.hasClass("is-open")) {
        if (k === "ArrowDown" || k === "ArrowUp" || k === "Enter" || k === " ") { e.preventDefault(); open(); }
        return;
      }
      if (k === "Escape") { e.preventDefault(); close(); }
      else if (k === "ArrowDown") { e.preventDefault(); move(1); }
      else if (k === "ArrowUp") { e.preventDefault(); move(-1); }
      else if (k === "Enter" || k === " ") { e.preventDefault(); choose(items().filter(".is-active")); }
      else if (k === "Tab") close();
      else if (k.length === 1) {
        var $hit = items().filter(function () { return $(this).text().toLowerCase().indexOf(k.toLowerCase()) === 0; }).first();
        if ($hit.length) setActive($hit);
      }
    });
    $panel.on("mousedown", ".ccm-dd-opt", function (e) { e.preventDefault(); choose($(this)); });
    $panel.on("mousemove", ".ccm-dd-opt", function () { if (!$(this).hasClass("is-active")) setActive($(this)); });
    $panel.on("input", ".ccm-dd-q", function () {
      var q = $.trim(this.value).toLowerCase();
      $panel.find(".ccm-dd-opt").each(function () { $(this).prop("hidden", !!q && $(this).text().toLowerCase().indexOf(q) < 0); });
      $panel.find(".ccm-dd-none").prop("hidden", items().length > 0);
      setActive(items().first());
    });
    $panel.on("keydown", ".ccm-dd-q", function (e) {
      if (e.key === "ArrowDown") { e.preventDefault(); move(1); }
      else if (e.key === "ArrowUp") { e.preventDefault(); move(-1); }
      else if (e.key === "Enter") { e.preventDefault(); choose(items().filter(".is-active")); }
      else if (e.key === "Escape") { e.preventDefault(); close(); $btn.trigger("focus"); }
    });
    dropdown.refresh(sel);
  }
  // …and when a screen sets the value in code, e.g. $("#o-vendor").val("all") on "Clear filters".
  (function () {
    var hook = $.valHooks.select || ($.valHooks.select = {});
    var orig = hook.set;
    hook.set = function (elem, value) {
      var r = orig ? orig.apply(this, arguments) : undefined;
      if (r === undefined) $(elem).find("option").each(function () { this.selected = String(this.value) === String(value); });
      if ($(elem).data("dd")) dropdown.refresh(elem);
      return orig ? r : true;
    };
  })();
  dropdown.refresh = function (sel) {
    var $s = $(sel), d = $s.data("dd");
    if (!d) return;
    var val = String($s.val() == null ? "" : $s.val()), id = d.$btn.attr("aria-controls");
    d.$btn.find(".ccm-dd-val").text($s.find("option:selected").text() || "");
    d.$btn.prop("disabled", $s.prop("disabled"));
    var n = $s.find("option").length;
    d.$panel.find(".ccm-dd-search").prop("hidden", n <= 10);
    d.$panel.find(".ccm-dd-list").html(
      $s.find("option").map(function (i) {
        var v = String(this.value), on = v === val;
        return "<div class='ccm-dd-opt" + (on ? " is-sel" : "") + "' role='option' id='" + id + "-" + i + "' data-v='" + esc(v) + "' aria-selected='" + on + "'>" + esc($(this).text()) + "</div>";
      }).get().join("") + "<div class='ccm-dd-none' hidden>No matches</div>"
    );
  };

  /* ---------- Reports ---------- */
  screens.reports = function () {
    var exportBase = String($("[data-ccm-screen]").data("export") || base + "reports/export/");
    var M = null; // meta: types, ranges, vendors, stores, …
    var R = null; // last result
    var sortKey = null, sortDir = -1;
    var RP = pager("#r-pager", function () { table(); });
    var F = defaults();
    var reqNo = 0;

    function defaults() {
      return { type: "vendors", range: "this_month", from: "", to: "", status: "", vendor: "", store: "", province: "", product: "", user: "", reason: "", coverage: "all" };
    }
    function typeMeta(k) { return M.types.filter(function (t) { return t.key === k; })[0] || M.types[0]; }
    function params() {
      var p = {};
      Object.keys(F).forEach(function (k) {
        if (F[k] === "" || F[k] == null) return;
        if ((k === "from" || k === "to") && F.range !== "custom") return;
        if (k === "coverage" && (F.type !== "coverage" || F.coverage === "all")) return;
        if (k === "reason" && ["exceptions", "orders"].indexOf(F.type) < 0) return;
        p[k] = F[k];
      });
      return p;
    }
    function fromUrl() {
      var q = new URLSearchParams(location.search);
      if (!q.get("type")) return;
      Object.keys(F).forEach(function (k) { if (q.get(k) != null) F[k] = q.get(k); });
    }
    function toUrl() {
      var qs = $.param(params());
      history.replaceState(null, "", location.pathname + (qs ? "?" + qs : ""));
      $("#r-xlsx").attr("href", exportBase + "?format=xlsx&" + qs);
      $("#r-csv").attr("href", exportBase + "?format=csv&" + qs);
      $("#r-print").attr("href", exportBase + "?format=print&autoprint=1&" + qs);
    }

    function options($sel, list, valKey, labelFn, first) {
      $sel.html("<option value=''>" + esc(first) + "</option>" + list.map(function (x) {
        return "<option value='" + esc(x[valKey]) + "'>" + esc(labelFn(x)) + "</option>";
      }).join(""));
    }
    function buildFilters() {
      $("#r-types").html(M.types.map(function (t) {
        return "<button type='button' class='ccm-tab' role='tab' data-rtype='" + t.key + "' title='" + esc(t.help) + "'>" + esc(t.label) + "</button>";
      }).join(""));
      $("#r-range").html(M.ranges.map(function (r) { return "<option value='" + r.key + "'>" + esc(r.label) + "</option>"; }).join(""));
      options($("#r-vendor"), M.vendors, "key", function (v) { return v.label; }, "All vendors");
      options($("#r-store"), M.stores, "id", function (s) { return s.name + (s.code ? " · " + s.code : ""); }, "All stores (" + M.stores.length + ")");
      options($("#r-province"), M.provinces.map(function (p) { return { p: p }; }), "p", function (x) { return x.p; }, "All provinces");
      options($("#r-product"), M.products, "id", function (p) { return p.name; }, "All products");
      options($("#r-user"), M.users, "id", function (u) { return u.name + (u.email && u.email !== u.name ? " · " + u.email : ""); }, "All users");
      options($("#r-reason"), M.reasons, "key", function (r) { return r.label; }, "Any reason");
      $(".ccm-rgrid select").each(function () { dropdown(this); });
    }
    function syncControls() {
      var tm = typeMeta(F.type);
      $("[data-rtype]").each(function () {
        var on = $(this).data("rtype") === F.type;
        $(this).toggleClass("on", on).attr("aria-selected", on ? "true" : "false");
      });
      $("#r-range").val(F.range);
      if (F.range !== "custom") {
        var d = (M.ranges.filter(function (r) { return r.key === F.range; })[0] || {}).dates || ["", ""];
        $("#r-from").val(d[0]); $("#r-to").val(d[1]);
      } else {
        $("#r-from").val(F.from); $("#r-to").val(F.to);
      }
      $("#r-status").val(F.status || tm.status);
      ["vendor", "store", "province", "product", "user", "reason", "coverage"].forEach(function (k) { $("#r-" + k).val(F[k] || (k === "coverage" ? "all" : "")); });
      $("[data-show=custom]").prop("hidden", F.range !== "custom");
      $("[data-show=reason]").prop("hidden", ["exceptions", "orders"].indexOf(F.type) < 0);
      $("[data-show=coverage]").prop("hidden", F.type !== "coverage");
      var active = params();
      delete active.type; delete active.range;
      $("#r-reset").prop("hidden", !Object.keys(active).length && F.range === "this_month");

      // Highlight filters that differ from the report's default, so it's clear what is filtered.
      var dflt = { range: "this_month", status: tm.status, coverage: "all" };
      $(".ccm-rgrid select").each(function () {
        var k = this.id.slice(2);
        $(this).closest(".ccm-field").toggleClass("is-set", String(this.value) !== String(dflt[k] != null ? dflt[k] : ""));
      });
      // A preset stays marked until any filter changes.
      var now = JSON.stringify(params());
      if (activePreset && presetSnap === null) presetSnap = now;
      else if (activePreset && now !== presetSnap) activePreset = null;
      $(".ccm-preset").each(function () { $(this).toggleClass("is-active", $(this).find("[data-preset]").data("preset") === activePreset); });
      $(".ccm-rgrid select").each(function () { dropdown.refresh(this); });
    }
    var activePreset = null, presetSnap = null;

    /* presets */
    function renderPresets() {
      $("#r-presets").html(M.presets.map(function (p) {
        return (
          "<span class='ccm-preset" + (p.builtin ? "" : " is-mine") + "'><button type='button' class='ccm-preset-b' data-preset='" + esc(p.id) + "' title='" + esc(p.builtin ? "Built-in preset" : "Saved by " + (p.by || "a manager")) + "'>" + esc(p.name) + "</button>" +
          (p.builtin ? "" : "<button type='button' class='ccm-preset-x' data-pdel='" + esc(p.id) + "' aria-label='Delete preset " + esc(p.name) + "'>×</button>") + "</span>"
        );
      }).join(""));
    }
    $(document).on("click", "[data-preset]", function () {
      var p = M.presets.filter(function (x) { return x.id === $(this).data("preset"); }.bind(this))[0];
      if (!p) return;
      F = $.extend(defaults(), p.filters);
      ["vendor", "store", "product", "user", "reason"].forEach(function (k) { if ($.isArray(F[k])) F[k] = String(F[k][0] || ""); });
      sortKey = null;
      activePreset = p.id; presetSnap = null;
      syncControls(); run();
      toast("Preset: " + p.name);
    });
    $(document).on("click", "[data-pdel]", function () {
      var id = $(this).data("pdel");
      var p = M.presets.filter(function (x) { return x.id === id; })[0];
      confirmDialog({
        title: "Delete preset “" + p.name + "”?",
        sub: "It disappears for every manager. The data isn’t affected.",
        onOk: function () {
          return req("DELETE", "reports/presets/" + id)
            .done(function () { M.presets = M.presets.filter(function (x) { return x.id !== id; }); renderPresets(); toast("Preset deleted"); })
            .fail(function (x) { toast(errMsg(x, "Couldn’t delete the preset")); });
        },
      });
    });
    $("#r-savepreset").on("click", function () {
      formModal({
        title: "Save as preset",
        sub: "Saves this report and its filters for every manager. Periods like “This month” stay relative.",
        saveLabel: "Save preset",
        values: { name: typeMeta(F.type).label + " · " + $("#r-range option:selected").text() },
        fields: [{ k: "name", l: "Preset name", req: true, full: true, ph: "e.g. Alberta stores · Amazon · this month" }],
        onSave: function (v) {
          return req("POST", "reports/presets", { name: v.name, filters: params() }).then(function (p) {
            M.presets.push(p); renderPresets(); toast("Preset saved");
          }, function (x) { return $.Deferred().reject(errMsg(x, "Couldn’t save the preset")).promise(); });
        },
      });
    });

    /* filters */
    $(document).on("click", "[data-rtype]", function () {
      F.type = $(this).data("rtype");
      F.status = ""; // each report has its own default status
      sortKey = null;
      syncControls(); run();
    });
    $("#r-range").on("change", function () {
      F.range = this.value;
      if (F.range === "custom") { F.from = $("#r-from").val(); F.to = $("#r-to").val(); }
      syncControls(); run();
    });
    $("#r-from,#r-to").on("change", function () {
      F.range = "custom"; F.from = $("#r-from").val(); F.to = $("#r-to").val();
      if (F.from && F.to) { syncControls(); run(); }
    });
    $("#r-status,#r-vendor,#r-store,#r-province,#r-product,#r-user,#r-reason,#r-coverage").on("change", function () {
      F[this.id.slice(2)] = this.value;
      syncControls(); run();
    });
    $("#r-reset").on("click", function () {
      var t = F.type; F = defaults(); F.type = t; sortKey = null;
      syncControls(); run();
    });

    /* output */
    function fmt(v, type) {
      if (type === "money") return money(v);
      if (type === "pct") return (Number(v || 0) * 100).toFixed(1) + "%";
      if (type === "int") return Number(v || 0).toLocaleString("en-CA");
      return esc(v == null || v === "" ? "—" : v);
    }
    function kpis(s) {
      var items = [
        ["Total spend", money(s.spend), s.orders + " approved orders · avg " + money(s.avg_order)],
        ["Amazon", money(s.amazon), (s.spend ? ((s.amazon / s.spend) * 100).toFixed(1) : "0.0") + "% of spend"],
        ["Grand & Toy", money(s.gt), (s.spend ? ((s.gt / s.spend) * 100).toFixed(1) : "0.0") + "% of spend"],
        ["Stores that ordered", s.stores_ordered + " <small>of " + s.stores_total + "</small>", (s.stores_total - s.stores_ordered) + " haven’t ordered"],
      ];
      $("#r-kpis").html(items.map(function (k) {
        return "<div class='ccm-card ccm-kpi'><div class='ccm-muted' style='font-size:13px'>" + esc(k[0]) + "</div><div class='ccm-rkpi-v'>" + k[1] + "</div><div class='ccm-muted ccm-small'>" + esc(k[2]) + "</div></div>";
      }).join(""));
    }
    function chart(c) {
      var $c = $("#r-chart");
      if (!c || !c.items || !c.items.length) return $c.prop("hidden", true).empty();
      var html = "";
      if (c.kind === "months") {
        var max = Math.max.apply(null, c.items.map(function (m) { return m.amazon + m.gt + m.other; }).concat([1]));
        html =
          "<div class='ccm-rlegend'><span><i class='is-amz'></i>Amazon</span><span><i class='is-gt'></i>Grand &amp; Toy</span><span><i class='is-oth'></i>Other / unassigned</span></div>" +
          "<div class='ccm-mchart'>" + c.items.map(function (m) {
            var t = m.amazon + m.gt + m.other;
            var h = function (v) { return (v / max) * 100; };
            return (
              "<div class='ccm-mcol' title='" + esc(m.label + ": Amazon " + money(m.amazon) + " · Grand & Toy " + money(m.gt) + " · Other " + money(m.other)) + "'>" +
              "<div class='ccm-mval'>" + (t ? moneyShort(t) : "") + "</div>" +
              "<div class='ccm-mstack'><i class='is-oth' style='height:" + h(m.other) + "%'></i><i class='is-gt' style='height:" + h(m.gt) + "%'></i><i class='is-amz' style='height:" + h(m.amazon) + "%'></i></div>" +
              "<div class='ccm-mlab'>" + esc(m.label.replace(/ \d{4}$/, "")) + "</div></div>"
            );
          }).join("") + "</div>";
      } else if (c.kind === "split") {
        var tot = c.items[0].value + c.items[1].value || 1;
        html =
          "<div class='ccm-rlegend'><span><i class='is-ok'></i>Ordered " + c.items[0].value + "</span><span><i class='is-oth'></i>Not ordered " + c.items[1].value + "</span></div>" +
          "<div class='ccm-vs-bar'><i class='is-ok' style='width:" + (c.items[0].value / tot) * 100 + "%'></i><i class='is-oth' style='width:" + (c.items[1].value / tot) * 100 + "%'></i></div>";
      } else {
        var mx = Math.max.apply(null, c.items.map(function (i) { return i.value; }).concat([1]));
        html = "<div class='ccm-hbars'>" + c.items.map(function (i, n) {
          return (
            "<div class='ccm-hbar'><div class='ccm-hbar-l' title='" + esc(i.label) + "'>" + esc(i.label) + "</div>" +
            "<div class='ccm-hbar-t'><i style='width:" + (i.value / mx) * 100 + "%'" + (n === 0 ? " class='is-top'" : "") + "></i></div>" +
            "<div class='ccm-hbar-v'>" + (c.money ? money(i.value) : i.value) + "</div></div>"
          );
        }).join("") + "</div>";
      }
      $c.html(html).prop("hidden", false);
    }
    function table() {
      var cols = R.columns, rows = R.rows.slice();
      if (sortKey) {
        var col = cols.filter(function (c) { return c.key === sortKey; })[0];
        rows.sort(function (a, b) {
          var x = a[sortKey], y = b[sortKey];
          if (col && col.type !== "text") return (Number(x) - Number(y)) * sortDir;
          return String(x || "").localeCompare(String(y || "")) * sortDir;
        });
      }
      $("#r-thead").html(cols.map(function (c) {
        var on = c.key === sortKey;
        return "<th class='" + (c.type !== "text" ? "num " : "") + "ccm-sortable' aria-sort='" + (on ? (sortDir > 0 ? "ascending" : "descending") : "none") + "'><button type='button' data-sort='" + c.key + "'>" + esc(c.label) + "<span aria-hidden='true'>" + (on ? (sortDir > 0 ? " ↑" : " ↓") : "") + "</span></button></th>";
      }).join(""));
      if (!rows.length) {
        RP.slice(rows, "empty"); // clears the pager
        $("#r-rows").html("<tr><td colspan='" + cols.length + "' class='ccm-empty'><strong>No data for these filters</strong>Try a longer period or remove a filter.</td></tr>");
        $("#r-tfoot").empty();
      } else {
        var page = RP.slice(rows, [JSON.stringify(params()), sortKey, sortDir]);
        $("#r-rows").html(page.map(function (r) {
          return "<tr>" + cols.map(function (c) {
            var v = fmt(r[c.key], c.type);
            if (c.key === "order" && r.id) v = "<a class='ccm-idlink' href='" + base + "orders/view/" + r.id + "'>" + esc(r.order) + "</a>";
            if (c.key === "ordered") v = "<span class='ccm-pill " + (r.ordered === "Ordered" ? "p-approved" : "p-pending") + "'>" + esc(r.ordered) + "</span>";
            if (c.key === "outcome") v = "<span class='ccm-pill " + (/Rejected/.test(r.outcome) ? "p-rejected" : /exception/.test(r.outcome) ? "p-info" : /Waiting/.test(r.outcome) ? "p-pending" : "p-approved") + "'>" + esc(r.outcome) + "</span>";
            if (c.key === "vendor" && R.type === "vendors") v = "<span class='ccm-vdot is-" + (r.key === "amazon" ? "amz" : r.key === "gt" ? "gt" : "oth") + "'></span>" + v;
            if (c.key === "share") v = "<span class='ccm-share'><i style='width:" + Math.round(r.share * 100) + "%'></i></span>" + v;
            return "<td class='" + (c.type !== "text" ? "num" : "") + "'>" + v + "</td>";
          }).join("") + "</tr>";
        }).join(""));
        var t = R.totals || {};
        $("#r-tfoot").html(Object.keys(t).length ? "<tr>" + cols.map(function (c, i) {
          return "<td class='" + (c.type !== "text" ? "num" : "") + "'>" + (i === 0 ? "Total" : t[c.key] != null ? fmt(t[c.key], c.type) : "") + "</td>";
        }).join("") + "</tr>" : "");
      }
      $("#r-count").text(RP.total ? RP.label("rows") : "0 rows");
    }
    $(document).on("click", "[data-sort]", function () {
      var k = $(this).data("sort");
      if (sortKey === k) sortDir = -sortDir; else { sortKey = k; sortDir = -1; }
      table();
    });

    function run() {
      toUrl();
      var my = ++reqNo;
      $("#r-rows").closest(".ccm-rresult").addClass("is-loading");
      get("reports/run", params())
        .done(function (d) {
          if (my !== reqNo) return;
          R = d;
          $("#r-title").text(d.title);
          $("#r-desc").text(d.description);
          $("#r-period").text(d.period);
          $("#r-chips").html(d.filters_text.map(function (t) { return "<span class='ccm-chip is-static'>" + esc(t) + "</span>"; }).join(""));
          kpis(d.summary);
          $("#r-split").html(vendorSplit(d.summary, { sub: "Approved spend · " + d.period }));
          chart(d.chart);
          table();
        })
        .fail(function (x) { $("#r-rows").html("<tr><td class='ccm-empty'>" + esc(errMsg(x, "Couldn’t run the report")) + "</td></tr>"); })
        .always(function () { if (my === reqNo) $(".ccm-rresult").removeClass("is-loading"); });
    }

    get("reports/meta")
      .done(function (m) {
        M = m;
        fromUrl();
        buildFilters();
        renderPresets();
        syncControls();
        run();
      })
      .fail(function (x) { $("#r-rows").html("<tr><td class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load reports")) + "</td></tr>"); });
  };

  /* ---------- Returns (SOW 1.24): store return requests and Supply Ops decisions ---------- */
  var RET_TONE = { submitted: "p-pending", approved: "p-approved", rejected: "p-rejected", closed: "p-neutral" };
  var RET_ACTION = {
    approved: { btn: "Approve", cls: "primary", label: "Instructions for the store", ph: "e.g. Keep the box. Amazon will email a return label; drop it at Canada Post.", req: false, done: "approved" },
    rejected: { btn: "Reject", cls: "danger", label: "Reason (the store sees this)", ph: "e.g. Opened items can’t be returned.", req: true, done: "rejected" },
    closed: { btn: "Close return", cls: "", label: "How it ended (optional)", ph: "e.g. Amazon refunded CA$12.40 on Nov 12.", req: false, done: "closed" },
  };
  function retPill(r) {
    return "<span class='ccm-pill " + (RET_TONE[r.status] || "p-neutral") + "'>" + esc(r.status_label) + "</span>";
  }
  function openReturn(id, onChange) {
    $("#ccm-info .ccm-modal").removeClass("ccm-modal--pol");
    $("#if-title").text("Return request");
    $("#if-sub").text("");
    $("#if-body").html("<div class='ccm-empty'>Loading…</div>");
    $("#if-foot").empty();
    openLayer("ccm-info");
    get("returns/" + id).done(function (r) { drawReturn(r, onChange); })
      .fail(function (x) { $("#if-body").html("<div class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load the return request")) + "</div>"); });
  }
  function drawReturn(r, onChange) {
    $("#if-title").html(esc(r.number) + " " + retPill(r));
    $("#if-sub").text("Order #" + r.order_number + " · " + (r.store.name || "No store") + (r.store.code ? " (" + r.store.code + ")" : ""));
    var rows = [
      ["Requested by", esc(r.requested_by.name || "—") + (r.requested_by.email ? " <span class='ccm-muted'>· " + esc(r.requested_by.email) + "</span>" : "")],
      ["Requested", esc(fdt(r.created_at))],
      ["Reason", esc(r.reason_label)],
      ["Order", "<a class='ccm-idlink' href='" + base + "orders/view/" + r.order_id + "'>#" + esc(r.order_number) + "</a>"],
      ["Supplier", esc(r.vendors.join(", ") || "—")],
      ["Supplier order", esc(r.vendor_ref || "—")],
      ["Value", esc(money(r.value))],
    ];
    var html =
      "<dl class='ccm-od-dl ccm-ret-dl'>" + rows.map(function (x) { return "<div><dt>" + x[0] + "</dt><dd>" + x[1] + "</dd></div>"; }).join("") + "</dl>" +
      (r.note ? "<div class='ccm-ret-note'><div class='ccm-ret-note-t'>What the store said</div>" + esc(r.note) + "</div>" : "") +
      "<table class='ccm-table ccm-ret-items'><thead><tr><th>Item</th><th>SKU</th><th>Supplier</th><th class='num'>Qty</th><th class='num'>Value</th></tr></thead><tbody>" +
      r.lines.map(function (l) {
        return "<tr><td class='ccm-strong'>" + esc(l.name) + "</td><td>" + esc(l.sku || "—") + "</td><td>" + esc(l.vendor || "—") + "</td><td class='num'>" + (+l.qty) + "</td><td class='num'>" + money(l.unit * l.qty) + "</td></tr>";
      }).join("") + "</tbody></table>" +
      "<div class='ccm-card-t' style='margin:16px 0 10px'>History</div><div class='ccm-tl'>" +
      r.history.slice().reverse().map(function (h) {
        return "<div class='ccm-tl-row'><div class='ccm-strong'>" + esc(h.label) + (h.by ? " <span class='ccm-muted ccm-small'>by " + esc(h.by) + "</span>" : "") + "</div>" +
          "<div class='ccm-muted ccm-small'>" + esc(fdt(h.at)) + "</div>" + (h.note ? "<div class='ccm-ret-hnote'>" + esc(h.note) + "</div>" : "") + "</div>";
      }).join("") + "</div>" +
      "<div class='ccm-ret-decide' id='rt-decide' hidden><label class='ccm-field'><span id='rt-dl'></span><textarea class='ccm-input ccm-textarea' id='rt-note' rows='3' maxlength='1000'></textarea></label><div class='ccm-err' role='alert' id='rt-err' hidden></div></div>";
    $("#if-body").html(html);
    var foot = r.next.map(function (s) {
      var a = RET_ACTION[s];
      return a ? "<button type='button' class='ccm-btn " + a.cls + "' data-rt-go='" + s + "'>" + a.btn + "</button>" : "";
    }).join("");
    $("#if-foot").html(foot ? foot : "<span class='ccm-muted ccm-small' style='margin-right:auto'>This request is " + esc(r.status_label.toLowerCase()) + ". Nothing more to do.</span><button type='button' class='ccm-btn' data-ccm-close>Close</button>");

    var chosen = null;
    $("#if-foot").off("click.rt").on("click.rt", "[data-rt-go]", function () {
      var s = String($(this).data("rtGo")), a = RET_ACTION[s];
      chosen = s;
      $("#rt-decide").prop("hidden", false);
      $("#rt-dl").text(a.label + (a.req ? " *" : ""));
      $("#rt-note").attr("placeholder", a.ph).trigger("focus");
      $("#rt-err").prop("hidden", true);
      $("#if-foot").html("<button type='button' class='ccm-btn' data-rt-back>Back</button><button type='button' class='ccm-btn " + (a.cls || "primary") + "' data-rt-save>" + esc(a.btn) + " " + esc(r.number) + "</button>");
    });
    $("#if-foot").off("click.rtb").on("click.rtb", "[data-rt-back]", function () { drawReturn(r, onChange); });
    $("#if-foot").off("click.rts").on("click.rts", "[data-rt-save]", function () {
      var $b = $(this), a = RET_ACTION[chosen], note = $.trim($("#rt-note").val() || "");
      if (a.req && !note) { $("#rt-err").text("Add a reason for the store.").prop("hidden", false); return; }
      $b.prop("disabled", true).text("Saving…");
      req("POST", "returns/" + r.id + "/decision", { status: chosen, note: note })
        .done(function (nr) {
          toast("Return " + r.number + " " + a.done + ". The store was emailed.");
          drawReturn(nr, onChange);
          if (onChange) onChange();
        })
        .fail(function (x) {
          $b.prop("disabled", false).text(a.btn + " " + r.number);
          $("#rt-err").text(errMsg(x, "Couldn’t save")).prop("hidden", false);
        });
    });
  }

  screens.returns = function () {
    var S = { items: [], counts: {}, status: "open", q: "" };
    var P = pager("#rt-pager", function () { render(); });
    var TABS = [["open", "Open"], ["submitted", "Waiting for review"], ["approved", "Approved"], ["closed", "Closed"], ["rejected", "Rejected"], ["all", "All"]];
    function view() {
      var q = S.q.toLowerCase();
      return S.items.filter(function (r) {
        if (S.status === "open" && ["submitted", "approved"].indexOf(r.status) < 0) return false;
        if (S.status !== "open" && S.status !== "all" && r.status !== S.status) return false;
        if (!q) return true;
        var hay = [r.number, "#" + r.order_number, r.order_number, r.store.name, r.store.code, r.requested_by.name, r.requested_by.email, r.reason_label]
          .concat(r.lines.map(function (l) { return l.name + " " + l.sku; })).join(" ").toLowerCase();
        return hay.indexOf(q) >= 0;
      });
    }
    function render() {
      var rows = view(), page = P.slice(rows, [S.status, S.q]);
      $("#rt-tabs").html(TABS.map(function (t) {
        var n = t[0] === "all" ? S.items.length : t[0] === "open" ? S.items.filter(function (r) { return r.status === "submitted" || r.status === "approved"; }).length : S.items.filter(function (r) { return r.status === t[0]; }).length;
        var on = S.status === t[0];
        return "<button type='button' class='ccm-tab" + (on ? " on" : "") + "' role='tab' aria-selected='" + on + "' data-status='" + t[0] + "'>" + t[1] + " <span class='ccm-count'>" + n + "</span></button>";
      }).join(""));
      $("#rt-rows").html(page.map(function (r) {
        var first = r.lines[0] ? r.lines[0].name : "";
        return "<tr>" +
          "<td><button type='button' class='ccm-idlink' data-rt='" + r.id + "'>" + esc(r.number) + "</button></td>" +
          "<td><a class='ccm-idlink' href='" + base + "orders/view/" + r.order_id + "'>#" + esc(r.order_number) + "</a></td>" +
          "<td><div class='ccm-strong'>" + esc(r.store.name || "—") + "</div><div class='ccm-muted ccm-small'>" + esc(r.requested_by.name || "") + "</div></td>" +
          "<td><div class='ccm-ellipsis' style='max-width:240px' title='" + esc(first) + "'>" + esc(first) + "</div><div class='ccm-muted ccm-small'>" + r.items + " item" + (r.items === 1 ? "" : "s") + (r.lines.length > 1 ? " · " + r.lines.length + " products" : "") + "</div></td>" +
          "<td>" + esc(r.reason_label) + "</td>" +
          "<td>" + esc(fd(r.created_at)) + "</td>" +
          "<td>" + retPill(r) + "</td>" +
          "<td class='num'><button type='button' class='ccm-btn sm" + (r.status === "submitted" ? " primary" : "") + "' data-rt='" + r.id + "'>" + (r.status === "submitted" ? "Review" : "View") + "</button></td>" +
          "</tr>";
      }).join(""));
      $("#rt-empty").prop("hidden", rows.length > 0);
      $("#rt-foot").text(P.label("return requests"));
    }
    function load() {
      return get("returns", { status: "all" }).done(function (d) {
        S.items = (d && d.items) || [];
        S.items.forEach(function (r) { r.store.name = plainText(r.store.name); });
        render();
      }).fail(function (x) {
        $("#rt-rows").html("<tr><td colspan='8' class='ccm-empty'>" + esc(errMsg(x, "Couldn’t load return requests")) + "</td></tr>");
      });
    }
    $("#rt-tabs").on("click", "[data-status]", function () { S.status = String($(this).data("status")); render(); });
    $("#rt-q").on("input", function () { S.q = $.trim(this.value); render(); });
    $("#rt-rows").on("click", "[data-rt]", function () { openReturn(+$(this).data("rt"), load); });
    load().done(function () {
      var m = /[?&]r=(\d+)/.exec(location.search);
      if (m) openReturn(+m[1], load);
    });
  };

  $(function () {
    var name = $("[data-ccm-screen]").data("ccmScreen");
    if (name && screens[name]) screens[name]();
    // Every table filter uses the dropdown that opens below the field (native menus cover it on macOS).
    $(".ccm-toolbar select.ccm-select").each(function () { dropdown(this); });
  });
})(jQuery);
