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
        if (started) setVendorLoading($wrap, false);
        else setVendorLoading($wrap, false);
      });
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

  $(enhance);
  $(document.body).on("updated_cart_totals updated_wc_div wc_fragments_refreshed", enhance);
})(jQuery);
