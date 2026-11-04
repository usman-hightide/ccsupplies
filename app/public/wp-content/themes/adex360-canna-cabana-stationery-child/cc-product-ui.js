/* Single product page: quantity stepper + add-to-cart toast. */
(function () {
  "use strict";

  /* ---- toast ---- */
  function initToast() {
    var card = document.querySelector("[data-cc-atc]");
    if (!card) return;
    var notice = card.closest(".woocommerce-message") || card;
    var timer;

    function hide() {
      clearTimeout(timer);
      notice.classList.add("cc-atc-leaving");
      setTimeout(function () {
        notice.style.display = "none";
      }, 250);
    }
    function arm() {
      clearTimeout(timer);
      timer = setTimeout(hide, 8000);
    }

    requestAnimationFrame(function () {
      notice.classList.add("cc-atc-in");
    });
    arm();
    notice.addEventListener("mouseenter", function () { clearTimeout(timer); });
    notice.addEventListener("mouseleave", arm);
    notice.addEventListener("focusin", function () { clearTimeout(timer); });
    notice.addEventListener("keydown", function (e) {
      if (e.key === "Escape") hide();
    });
    var close = card.querySelector("[data-cc-atc-close]");
    if (close) close.addEventListener("click", hide);
  }

  /* ---- quantity stepper ---- */
  function initStepper() {
    document.querySelectorAll("form.cart .quantity").forEach(function (wrap) {
      var input = wrap.querySelector("input.qty");
      if (!input || input.type === "hidden" || wrap.classList.contains("cc-qty")) return;
      wrap.classList.add("cc-qty");

      function btn(label, delta, cls) {
        var b = document.createElement("button");
        b.type = "button";
        b.className = "cc-qty__btn " + cls;
        b.setAttribute("aria-label", label);
        b.textContent = delta < 0 ? "−" : "+";
        b.addEventListener("click", function () {
          var step = parseFloat(input.step) || 1;
          var min = input.min !== "" ? parseFloat(input.min) : 1;
          var max = input.max !== "" ? parseFloat(input.max) : Infinity;
          var v = (parseFloat(input.value) || min) + delta * step;
          input.value = Math.min(max, Math.max(min, v));
          input.dispatchEvent(new Event("change", { bubbles: true }));
          sync();
        });
        return b;
      }
      var minus = btn("Decrease quantity", -1, "cc-qty__btn--minus");
      var plus = btn("Increase quantity", 1, "cc-qty__btn--plus");
      wrap.insertBefore(minus, input);
      wrap.appendChild(plus);

      function sync() {
        var v = parseFloat(input.value) || 0;
        minus.disabled = input.min !== "" && v <= parseFloat(input.min);
        plus.disabled = input.max !== "" && v >= parseFloat(input.max);
      }
      input.addEventListener("input", sync);
      sync();
    });
  }

  function init() {
    initToast();
    initStepper();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
