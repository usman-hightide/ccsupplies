(function () {
  function go(form) {
    if (!form) return;
    var input = form.querySelector('input[name="s"], input.e-search-input, input[type="search"]');
    if (!input) return;
    var term = (input.value || "").trim();
    if (!term) return;
    var base = form.getAttribute("action") || window.location.origin + "/";
    var url = new URL(base, window.location.origin);
    url.searchParams.set("s", term);
    url.searchParams.set("post_type", "product");
    window.location.assign(url.toString());
  }

  function stopAndGo(e, form) {
    e.preventDefault();
    e.stopPropagation();
    if (e.stopImmediatePropagation) e.stopImmediatePropagation();
    go(form);
  }

  document.addEventListener("submit", function (e) {
    var form = e.target && e.target.closest ? e.target.closest("form.e-search-form") : null;
    if (!form) return;
    stopAndGo(e, form);
  }, true);

  document.addEventListener("keydown", function (e) {
    if (e.key !== "Enter") return;
    var input = e.target;
    if (!input || !input.closest) return;
    var form = input.closest("form.e-search-form");
    if (!form || !input.matches("input[name='s'], input.e-search-input, input[type='search']")) return;
    stopAndGo(e, form);
  }, true);

  document.addEventListener("pointerdown", function (e) {
    // Don't hijack Add to cart clicks inside live results.
    if (e.target && e.target.closest && e.target.closest(".cc-search-hit__atc")) return;
    var btn = e.target && e.target.closest ? e.target.closest("form.e-search-form .e-search-submit, form.e-search-form button[type='submit']") : null;
    if (!btn) return;
    var form = btn.closest("form.e-search-form");
    if (!form) return;
    stopAndGo(e, form);
  }, true);
})();
