(function ($) {
  function wrapQty($qty) {
    if (!$qty.length || $qty.closest(".cc-qty").length) return;

    var $wrap = $('<div class="cc-qty" />');
    var $minus = $(
      '<button type="button" class="cc-qty__btn cc-qty__btn--minus" aria-label="Decrease quantity">−</button>'
    );
    var $plus = $(
      '<button type="button" class="cc-qty__btn cc-qty__btn--plus" aria-label="Increase quantity">+</button>'
    );

    $qty.wrap($wrap);
    $qty.before($minus);
    $qty.after($plus);
  }

  function enhance() {
    $(".woocommerce-cart-form .quantity .qty").each(function () {
      wrapQty($(this));
    });
    syncSelectAll();
    updateSelectedCount();
  }

  function bump($input, delta) {
    var step = parseFloat($input.attr("step")) || 1;
    var min = $input.attr("min") !== "" ? parseFloat($input.attr("min")) : 0;
    var maxAttr = $input.attr("max");
    var max = maxAttr !== "" && typeof maxAttr !== "undefined" ? parseFloat(maxAttr) : NaN;
    var val = parseFloat($input.val()) || 0;
    var next = val + delta * step;
    if (!isNaN(min)) next = Math.max(min, next);
    if (!isNaN(max)) next = Math.min(max, next);
    if (next === val) return;
    $input.val(next).trigger("change");
    var $update = $input.closest("form").find('[name="update_cart"]');
    $update.prop("disabled", false).trigger("click");
  }

  function setVendorLoading($wrap, on) {
    $wrap.toggleClass("is-loading", !!on);
    $wrap.find(".cc-cart-vendor__loader").prop("hidden", !on);
    $wrap.find(".cc-cart-vendor__select").prop("disabled", !!on);
  }

  function setVendorMeta($wrap, data, isError) {
    var $meta = $wrap.find(".cc-cart-vendor__meta");
    if (!data && !isError) {
      $meta.attr("hidden", true).empty();
      return;
    }
    $meta.removeAttr("hidden").empty();
    if (isError) {
      $meta.append(
        $('<span class="cc-cart-vendor__error" />').text(
          (data && data.message) ||
            (window.ccCartVendor && ccCartVendor.i18n && ccCartVendor.i18n.error) ||
            "Could not load vendor price."
        )
      );
      return;
    }
    if (data.vendor_label) {
      $meta.append($('<span class="cc-cart-vendor__badge" />').text(data.vendor_label));
    }
    if (data.vendor_id) {
      $meta.append($('<span class="cc-cart-vendor__id" />').text(data.vendor_id));
    }
  }

  function refreshCartUi() {
    var $update = $(".woocommerce-cart-form").find('[name="update_cart"]');
    if ($update.length) {
      $update.prop("disabled", false).trigger("click");
      return;
    }
    $(document.body).trigger("wc_update_cart");
  }

  function selectVendor($select) {
    if (!window.ccCartVendor || !ccCartVendor.ajaxUrl) return;

    var $wrap = $select.closest(".cc-cart-vendor");
    var vendor = $select.val() || "";
    var prev = $select.data("prev") || "";
    var cartKey = $wrap.data("cart-key");
    var productId = $wrap.data("product-id");
    var delay = (ccCartVendor.loaderDelay || 280) | 0;
    var loaderTimer = null;
    var started = false;

    if (vendor === prev && vendor !== "") return;

    loaderTimer = window.setTimeout(function () {
      started = true;
      setVendorLoading($wrap, true);
    }, delay);

    $.ajax({
      url: ccCartVendor.ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "cc_cart_vendor_select",
        nonce: ccCartVendor.nonce,
        cart_key: cartKey,
        product_id: productId,
        vendor: vendor,
      },
    })
      .done(function (res) {
        if (res && res.success) {
          $select.data("prev", vendor);
          setVendorMeta($wrap, res.data || {}, false);
          refreshCartUi();
          return;
        }
        $select.val(prev);
        setVendorMeta($wrap, (res && res.data) || {}, true);
      })
      .fail(function (xhr) {
        $select.val(prev);
        var payload = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;
        setVendorMeta($wrap, payload, true);
      })
      .always(function () {
        if (loaderTimer) window.clearTimeout(loaderTimer);
        setVendorLoading($wrap, false);
      });
  }

  function updateSelectedCount() {
    var count = $(".cc-cart-item-select:checked").length;
    var $label = $("[data-cc-selected-count]");
    if (!$label.length) return;
    var i18n = (window.ccCartUi && ccCartUi.i18n) || {};
    var tpl = count === 1 ? i18n.selectedOne || "%d item selected" : i18n.selectedMany || "%d items selected";
    $label.text(tpl.replace("%d", String(count)));
  }

  function syncSelectAll() {
    var $items = $(".cc-cart-item-select");
    var $all = $(".cc-cart-select-all");
    if (!$all.length || !$items.length) return;
    var total = $items.length;
    var checked = $items.filter(":checked").length;
    $all.prop("checked", checked === total && total > 0);
    $all.prop("indeterminate", checked > 0 && checked < total);
  }

  $(document.body).on("click", ".cc-qty__btn--minus", function (e) {
    e.preventDefault();
    bump($(this).siblings(".qty"), -1);
  });

  $(document.body).on("click", ".cc-qty__btn--plus", function (e) {
    e.preventDefault();
    bump($(this).siblings(".qty"), 1);
  });

  $(document.body).on("change", ".cc-cart-vendor__select", function () {
    selectVendor($(this));
  });

  $(document.body).on("change", ".cc-cart-select-all", function () {
    var on = $(this).is(":checked");
    $(".cc-cart-item-select").prop("checked", on);
    updateSelectedCount();
  });

  $(document.body).on("change", ".cc-cart-item-select", function () {
    syncSelectAll();
    updateSelectedCount();
  });

  $(document.body).on("click", ".cc-cart-clear", function (e) {
    if (!window.confirm("Clear all items from your cart?")) {
      e.preventDefault();
    }
  });

  function showCheckoutAlert(html) {
    var $box = $("#cc-checkout-alert");
    if (!$box.length) return;
    var title =
      (window.ccCartUi && ccCartUi.i18n && ccCartUi.i18n.checkoutErrorTitle) ||
      "Please fix the following to continue";
    var $body = $box.find(".cc-checkout-alert__body");
    $body.empty();
    $body.append($('<p class="cc-checkout-alert__title" />').text(title));
    if (html) {
      $body.append(html);
    }
    $box.addClass("is-visible is-error").removeAttr("hidden").attr({
      role: "alert",
      "aria-live": "assertive",
    });
    try {
      $box.get(0).scrollIntoView({ behavior: "smooth", block: "center" });
    } catch (err) {
      /* ignore */
    }
  }

  function hideCheckoutAlertIfEmpty() {
    var $box = $("#cc-checkout-alert");
    if (!$box.length) return;
    var hasList = $box.find(".cc-checkout-alert__list li, .woocommerce-error li, .woocommerce-error").length > 0;
    if (!hasList && !$box.find(".cc-checkout-alert__body").text().trim()) {
      $box.removeClass("is-visible is-error").attr("hidden", true);
    }
  }

  $(document.body).on("checkout_error", function (e, errorMessage) {
    if (!(window.ccCartUi && ccCartUi.isCheckout)) return;
    var $tmp = $("<div/>").append(errorMessage || "");
    // Prefer list items from Woo error markup.
    var $list = $tmp.find(".woocommerce-error, ul.woocommerce-error");
    if ($list.length) {
      showCheckoutAlert($list.first().clone());
    } else {
      showCheckoutAlert($tmp.contents().length ? $tmp.contents() : $("<p/>").text(String(errorMessage || "")));
    }
  });

  $(document.body).on("updated_checkout", function () {
    if (!(window.ccCartUi && ccCartUi.isCheckout)) return;
    // Move any stray notice group into the alert box.
    var $stray = $(".woocommerce-NoticeGroup-checkout, .woocommerce-notices-wrapper").filter(function () {
      return $(this).closest("#cc-checkout-alert").length === 0;
    });
    if ($stray.length && $stray.text().trim()) {
      var $errors = $stray.find(".woocommerce-error").first();
      if ($errors.length) {
        showCheckoutAlert($errors.clone());
        $stray.empty();
      }
    }
    hideCheckoutAlertIfEmpty();
  });

  $(enhance);
  $(document.body).on("updated_cart_totals updated_wc_div wc_fragments_refreshed", enhance);
})(jQuery);

/* ---- Cart: confirm before checkout when the order needs approval ----
 * WCSS_Order_Policy prints a hidden .cc-policy-flag next to the checkout button
 * (refreshed with the cart totals). Out of policy → explain and ask to confirm.
 * The order is never blocked here; Supply Ops approves or rejects it later. */
(function ($) {
  var $pop = null;
  var target = "";
  var lastFocus = null;

  function flag() {
    var $f = $(".cc-policy-flag").last();
    if (!$f.length || String($f.data("inPolicy")) !== "0") return null;
    var lines = [];
    try { lines = JSON.parse($f.attr("data-lines") || "[]"); } catch (e) { lines = []; }
    return { reasons: String($f.data("reasons") || "").split(","), lines: lines };
  }

  function build() {
    if ($pop) return $pop;
    $pop = $(
      '<div class="cc-approve" role="dialog" aria-modal="true" aria-labelledby="cc-approve-title" aria-describedby="cc-approve-desc" hidden>' +
        '<div class="cc-approve__card">' +
        '<div class="cc-approve__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg></div>' +
        '<h2 class="cc-approve__title" id="cc-approve-title">This order needs approval</h2>' +
        '<ul class="cc-approve__list"></ul>' +
        '<div id="cc-approve-desc">' +
        '<p class="cc-approve__text">That’s why this order will go to Supply Ops for approval. Once it’s approved, it will be placed with the supplier and we’ll email you.</p>' +
        '<p class="cc-approve__text">Please confirm to continue to checkout.</p>' +
        "</div>" +
        '<div class="cc-approve__steps" aria-hidden="true"><b>Place order</b><span>→</span><span>Supply Ops approval</span><span>→</span><span>Sent to supplier</span></div>' +
        '<div class="cc-approve__actions">' +
        '<button type="button" class="cc-approve__btn" data-cc-approve="back">Back to cart</button>' +
        '<button type="button" class="cc-approve__btn cc-approve__btn--primary" data-cc-approve="go">Confirm and continue</button>' +
        "</div></div></div>"
    );
    $("body").append($pop);
    return $pop;
  }

  function open(f, href) {
    build();
    target = href;
    lastFocus = document.activeElement;
    var lines = f.lines.length ? f.lines : ["It is outside your store’s ordering policy."];
    $pop.find(".cc-approve__list").html(lines.map(function (l) { return $("<li/>").text(l).prop("outerHTML"); }).join(""));
    $pop.prop("hidden", false);
    $("html").addClass("cc-approve-open");
    setTimeout(function () { $pop.find("[data-cc-approve=go]").trigger("focus"); }, 0);
  }

  function close() {
    if (!$pop) return;
    $pop.prop("hidden", true);
    $("html").removeClass("cc-approve-open");
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  $(document.body).on("click", ".wc-proceed-to-checkout a.checkout-button", function (e) {
    var f = flag();
    if (!f) return; // in policy (or not a store user): go straight to checkout
    e.preventDefault();
    open(f, this.href);
  });
  $(document.body).on("click", "[data-cc-approve=back]", close);
  $(document.body).on("click", "[data-cc-approve=go]", function () {
    $(this).prop("disabled", true).text("Opening checkout…");
    window.location.href = target;
  });
  $(document.body).on("click", ".cc-approve", function (e) {
    if (e.target === this) close();
  });
  $(document).on("keydown", function (e) {
    if (!$pop || $pop.prop("hidden")) return;
    if (e.key === "Escape") close();
    if (e.key === "Tab") {
      // Keep focus inside the two buttons.
      var $b = $pop.find("button:not(:disabled)");
      var i = $b.index(document.activeElement);
      e.preventDefault();
      $b.eq((i + (e.shiftKey ? -1 : 1) + $b.length) % $b.length).trigger("focus");
    }
  });
  window.addEventListener("pageshow", function (e) {
    if (e.persisted && $pop) {
      $pop.find("[data-cc-approve=go]").prop("disabled", false).text("Confirm and continue");
      close();
    }
  });
})(jQuery);

/* ---- Checkout: "placing your order" overlay ----
 * Replaces Woo's grey blockUI with a progress card while the requisition is
 * created and (when in policy) auto-submitted to the supplier, which can take
 * several seconds. Closes on checkout_error; on success Woo redirects. */
(function ($) {
  // Out-of-policy orders go to Supply Ops, never to the supplier, so the steps must say so.
  var needsApproval = !!(window.ccCartUi && ccCartUi.needsApproval);
  var STEPS = needsApproval
    ? ["Creating your requisition", "Checking your monthly budget", "Sending your order for approval", "Notifying Supply Ops"]
    : ["Creating your requisition", "Checking your monthly budget", "Order approved — sending to the supplier", "Confirming with the supplier"];
  var TITLE = needsApproval ? "Sending your order for approval" : "Placing your order";
  var SLOW = needsApproval
    ? "Still working — please don’t close or refresh this page."
    : "Still working — suppliers can take a little longer to respond. Please don’t close or refresh this page.";
  var timers = [];
  var $ov = null;

  function build() {
    if ($ov) return $ov;
    $ov = $(
      '<div class="cc-placing" role="dialog" aria-modal="true" aria-labelledby="cc-placing-title" hidden>' +
        '<div class="cc-placing__card">' +
        '<div class="cc-placing__spinner" aria-hidden="true"></div>' +
        '<h2 class="cc-placing__title" id="cc-placing-title"></h2>' +
        '<p class="cc-placing__sub" aria-live="polite"></p>' +
        '<div class="cc-placing__bar" aria-hidden="true"><span></span></div>' +
        '<ol class="cc-placing__steps"></ol>' +
        '<p class="cc-placing__slow" hidden></p>' +
        "</div></div>"
    );
    $ov.find(".cc-placing__title").text(TITLE);
    $ov.find(".cc-placing__slow").text(SLOW);
    $ov.find(".cc-placing__steps").html(
      STEPS.map(function (s) {
        return '<li><span class="cc-placing__dot" aria-hidden="true"></span>' + s + "</li>";
      }).join("")
    );
    $("body").append($ov);
    return $ov;
  }

  function setStep(i) {
    var $li = $ov.find(".cc-placing__steps li");
    $li.each(function (n) {
      $(this).toggleClass("is-done", n < i).toggleClass("is-active", n === i);
    });
    $ov.find(".cc-placing__sub").text(STEPS[i] + "…");
    // Bar eases toward 90% and only completes when the page redirects.
    $ov.find(".cc-placing__bar span").css("width", Math.min(90, 18 + i * 24) + "%");
  }

  function show() {
    build();
    clear();
    $ov.find(".cc-placing__slow").prop("hidden", true);
    $ov.prop("hidden", false);
    $("html").addClass("cc-placing-open");
    setStep(0);
    [1800, 4200, 7500].forEach(function (ms, n) {
      timers.push(setTimeout(function () { setStep(n + 1); }, ms));
    });
    timers.push(setTimeout(function () {
      $ov.find(".cc-placing__slow").prop("hidden", false);
    }, 12000));
  }

  function clear() {
    timers.forEach(clearTimeout);
    timers = [];
  }

  function hide() {
    clear();
    if (!$ov) return;
    $ov.prop("hidden", true);
    $("html").removeClass("cc-placing-open");
  }

  $(document.body).on("checkout_error", hide);
  $(function () {
    // Woo triggers checkout_place_order before posting; returning true lets it continue.
    $("form.checkout").on("checkout_place_order", function () {
      show();
      return true;
    });
  });
  // Back/forward cache restores the page with the overlay still open.
  window.addEventListener("pageshow", function (e) {
    if (e.persisted) hide();
  });
})(jQuery);
