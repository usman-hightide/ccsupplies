# Plan: Vendor Cheapest Pricing + Checkout UX

**Status:** Implementation in progress on local (`ccsupplies.local`) — P1–P5 landed in child theme  
**Scope:** WooCommerce shop pricing (Amazon + Grand & Toy), cart vendor UI removal, checkout layout & alerts, header search relevance (backend only), checkout page chrome parity with cart  
**Depends on existing:** `amazon_asin` / `wcss_gt_part_id` product meta, Amazon Business API helpers, G&T procurement helpers, current `cc-cart-vendor` cart select (to be retired on storefront), Elementor header search (`input#search-d17f53a` / widget `d17f53a`), cart page chrome in `cc-cart-ui.php`

---

## 1. Goals

| # | Goal | Outcome |
|---|------|---------|
| A | Persist vendor prices in DB | Every eligible Woo product stores latest Amazon + G&T unit prices (when matchable) |
| B | Always show cheapest on frontend | Shop / product / loops use `min(Amazon, G&T, Woo catalog)` — never expose vendor picker |
| C | Hourly refresh | WP-Cron (or Action Scheduler) every 1 hour re-fetches vendor prices and updates DB |
| D | Remove manual vendor UI | Cart `.cc-cart-vendor` select gone; checkout “Vendor” column no longer driven by manual choice |
| E | Checkout layout & alerts | Billing (`#customer_details .col-1`) and order review (`#order_review`) share equal top alignment; checkout blockers show a prominent alert under the 3-step progress nav |
| F | Header product search (backend) | Elementor header search finds the correct Woo products (SKU, title, ASIN, G&T part, etc.); **no search UI/UX redesign** |
| G | Checkout chrome = cart chrome | Checkout uses the same professional pattern as cart: breadcrumbs, page title, lead/subtitle, back/previous control, and progress steps |

**Non-goals (this plan):** Changing Amazon/G&T PO placement flows; redesign of site header/footer chrome or Elementor search markup/styling; multi-currency conversion beyond current CAD assumptions.

---

## 2. Current state (baseline)

- **Amazon:** Product meta `amazon_asin`; fetch via existing Business API (`wp_amazon_business_get_product_by_asin` / batch ASINs).
- **Grand & Toy:** Part via `wcss_gt_part_id` or store SKU (`cc_gt_part_for_product`).
- **Cart today:** Manual dropdown in `cc-cart-vendor.php` live-fetches one vendor and overrides line price (`cc_vendor` / `cc_vendor_price` session keys). Checkout review shows Vendor / Unit columns from that selection.
- **Shop today:** Standard Woo `get_price()` — no cheapest-vendor layer.
- **Header search today:** Elementor Search widget (`elementor-element-d17f53a`, input `#search-d17f53a`, `name="s"`) — default WP/Elementor query; often misses products by SKU / partial title / vendor IDs. UI stays as-is.
- **Checkout chrome today:** Progress steps exist; lacks cart-parity title block, breadcrumbs, and explicit back/previous control.

---

## 3. Architecture overview

```
┌─────────────────┐     hourly cron      ┌──────────────────────────────┐
│ Amazon API      │─────────────────────▶│ wp_cc_vendor_prices (DB)     │
│ G&T price API   │     batch upsert     │ product_id, vendor, price,   │
└─────────────────┘                      │ currency, vendor_sku,        │
                                         │ fetched_at, status, error    │
                                         └──────────────┬───────────────┘
                                                        │
                    ┌───────────────────────────────────┼───────────────────┐
                    ▼                                   ▼                   ▼
             Admin compare view              Frontend price filter    Cart/checkout
             (optional WP list)              (cheapest or Woo)        auto-apply winner
```

**Principle:** Frontend never calls vendor APIs on page load. It reads **cached DB prices** only. Cron is the sole updater (plus optional admin “Sync now”).

---

## 4. Workstream A — Price storage (database)

### 4.1 Table (recommended custom table)

**Name:** `{prefix}cc_vendor_prices`

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `product_id` | BIGINT UNSIGNED | Woo product ID (indexed) |
| `vendor` | VARCHAR(32) | `amazon` \| `gt` |
| `vendor_sku` | VARCHAR(64) | ASIN or G&T part ID |
| `price` | DECIMAL(18,4) | Unit price in store currency |
| `currency` | CHAR(3) | e.g. `CAD` |
| `status` | VARCHAR(20) | `ok` \| `missing_id` \| `not_found` \| `error` |
| `error_message` | TEXT NULL | Last fetch error |
| `raw_hash` | CHAR(40) NULL | Optional fingerprint to skip no-op writes |
| `fetched_at` | DATETIME | Last successful or attempted fetch |
| `updated_at` | DATETIME | Row update time |

**Unique key:** `(product_id, vendor)` — one row per vendor per product.

**Why a table (not only post meta):** Clean hourly bulk upserts, queryable compare reports, no postmeta bloat, easy “all products cheapest” admin audit.

**Optional denormalized meta** (for fast shop filters without JOINs):

- `_cc_cheapest_price` (decimal)
- `_cc_cheapest_vendor` (`amazon` \| `gt` \| `woo`)
- `_cc_cheapest_synced_at`

Updated whenever a vendor row changes or Woo regular/sale price changes.

### 4.2 Product identity rules

| Vendor | Match key | If missing |
|--------|-----------|------------|
| Amazon | `amazon_asin` | Row `status=missing_id`; no price |
| G&T | `wcss_gt_part_id` or fallback SKU | Same |
| Woo | Native `regular_price` / `sale_price` | Always available as fallback |

Eligibility filters (existing `wcss_product_amazon_procurement_allowed` / G&T equivalents) still apply: ineligible vendor → treat as no vendor price for that product.

### 4.3 Initial backfill

1. Install table on theme/plugin activate (or versioned migrator).
2. One-time admin action / CLI: enqueue all published products into sync queue.
3. Process in batches (e.g. 25–50 products per request) to respect Amazon/G&T rate limits.

---

## 5. Workstream B — Compare & frontend cheapest price

### 5.1 Compare logic (single function)

```
candidates = []
if amazon.status == ok and amazon.price >= 0: candidates += amazon
if gt.status == ok and gt.price >= 0:         candidates += gt
woo_price = catalog price (sale if active else regular)
winner = min(candidates + [woo]) by price
if no vendor candidates: winner = woo
```

Ties: prefer Woo, then Amazon, then G&T (document explicitly; adjustable).

### 5.2 Where to apply on frontend

| Surface | Hook / approach |
|---------|-----------------|
| Shop loop / `/shop` | `woocommerce_product_get_price`, `woocommerce_product_get_regular_price` (careful with sale), or display filter only |
| Single product | Same price filters |
| Cart add | Persist `_cc_cheapest_vendor` + price into cart item meta automatically (no UI) |
| Cart / checkout totals | `woocommerce_before_calculate_totals` apply cached cheapest (replace today’s manual vendor apply) |

**Important:** Prefer applying **display + cart** from `_cc_cheapest_*` meta so shop HTML/cache stays consistent. Avoid rewriting Woo “regular price” in DB permanently (procurement accounting still needs catalog baseline).

### 5.3 Fallback (explicit)

If vendor price not fetched, not matched, error, or product has no ASIN/part:

→ **Show WooCommerce price only** (current catalog/sale price).

No empty price, no “Select vendor…”.

### 5.4 Remove manual vendor selection

| Item | Action |
|------|--------|
| Cart DOM `.cc-cart-vendor` | Stop rendering (remove `woocommerce_after_cart_item_name` output) |
| AJAX `cc_cart_vendor_select` | Deprecate or repurpose for admin-only tools |
| Checkout review “VENDOR” column | Either remove column **or** show auto winner label (Amazon / G&T / Store) read-only — **decision in §9** |
| Cart JS vendor change handlers | Remove or no-op |

Cart banner / other chrome stays as-is unless a later plan says otherwise.

---

## 6. Workstream C — Hourly cron

### 6.1 Scheduler

- Prefer **Action Scheduler** (Woo ships it) over raw `wp_schedule_event` for batch reliability.
- Recurrence: **every 1 hour**.
- Hook example: `cc_vendor_prices_hourly_sync`.

### 6.2 Job design

1. Select products due for refresh (all published simple/variable children with ASIN and/or G&T id; or “stale” `fetched_at` older than 55 minutes).
2. Chunk into batches (Amazon: batch ASINs where API allows; G&T: per-part or batch if available).
3. Upsert `{prefix}cc_vendor_prices`.
4. Recompute `_cc_cheapest_price` / `_cc_cheapest_vendor` for touched products.
5. Log summary: updated / missing / errors (WP option or custom log table).

### 6.3 Safety

- Lock flag / AS exclusive group so two crons never overlap.
- Soft fail per product: one ASIN failure must not abort the whole hour.
- Respect Amazon 5% / CAD caps **only if** those rules still apply at display time (confirm with next plan — currently checkout-order constraints; may stay order-time only).

### 6.4 Manual controls (admin)

- “Sync all vendor prices now”
- “Sync this product”
- Last run timestamp + error count on a simple settings/tools page

---

## 7. Workstream D — Checkout UX improvements

### 7.1 Checkout page chrome (parity with cart)

**Problem:** Cart has a professional pagehead (breadcrumb, title, lead, steps). Checkout currently feels incomplete — steps alone, no matching title/breadcrumb/back control.

**Plan — reuse the same professional pattern as cart (`cc-cart-ui.php` chrome), adapted for checkout:**

| Element | Checkout behavior |
|---------|-------------------|
| Breadcrumb | `Home › Shop › Cart › Checkout` (Checkout current / red) |
| Title | e.g. **Checkout** (or “Complete your order” — copy TBD) |
| Lead / subtitle | Short supporting line (confirm vendor-free cheapest pricing is applied; place requisition) |
| Back / previous | Explicit control → return to cart (`wc_get_cart_url()`), labeled e.g. **Back to cart** / **Previous**, styled consistently with cart chrome (not a raw browser history hack) |
| Progress steps | Keep existing 3-step nav; embed in pagehead row like cart (title left, steps right) on desktop |

**Constraints:**

- Same visual language as cart (typography, spacing, brand red accents) via shared `cc-cart-ui.css` classes where possible (e.g. extend `.cc-cart-pagehead` or introduce `.cc-checkout-pagehead` that mirrors it).
- Suppress duplicate theme/Elementor checkout H1 the same way cart title is suppressed.
- Do **not** invent a separate design system — cart is the source of truth.

### 7.2 Equal top alignment (billing + order review)

**Problem:** `#customer_details .col-1` (billing) and `#order_review` start at different vertical offsets.

**Plan:**

1. Wrap checkout main grid so **pagehead** (breadcrumb + title + back + steps) sits full-width above both columns.
2. CSS grid (desktop ≥992px):

   ```
   [ pagehead — full width ]
   [ alert — full width ]
   [ billing col-1 | order_review ]
   ```

3. Both columns `align-items: start` with **identical `margin-top` / no orphan heading offset**; move “Your order” inside `#order_review` card (already partly done via `cc-checkout-order-title`) so tops of white cards align.
4. Remove extra top padding on one column only; mirror padding on both.

Files likely: `cc-cart-ui.php`, `cc-cart-ui.css` (checkout section), optional thin wrapper in checkout template override if markup requires it.

### 7.3 Prominent checkout alert under steps / pagehead

**Placement:** Immediately **below** pagehead / `nav.cc-cart-steps`, **above** billing + order review.

**Behavior:**

- Collect Woo notices (`woocommerce-error`, `woocommerce-info`, `woocommerce-message`) that normally float elsewhere on checkout.
- Also surface custom blockers (vendor price stale, requisition validation, empty required fields after submit) into the same region.
- **Visual:** Full-width, high-contrast alert (brand red soft bg + strong left border or solid emphasis), icon, clear title + body, sticky optional until dismissed or until errors clear.
- Accessibility: `role="alert"` / `aria-live="assertive"` for errors.

**Implementation sketch:**

- PHP: `woocommerce_before_checkout_form` / after pagehead + steps render — print `.cc-checkout-alert` container.
- JS: on `checkout_error` (Woo checkout fragment) inject error HTML into `.cc-checkout-alert` and scrollIntoView.
- Hide duplicate default notice stack at top **or** relocate it into this box (prefer single source so users see one prominent box).

---

## 8. Workstream E — Header search (backend only)

### 8.1 Target UI (do not restyle)

Elementor header search field:

- Widget / element: `elementor-element-d17f53a` (Search widget)
- Input: `#search-d17f53a` — `type="search"`, `name="s"`, placeholder `"Search"`
- Location: sticky header (`elementor-location-header`)

**Hard rule:** No UI/UX change to this input, dropdown chrome, icons, or layout. **Backend / query relevance only.**

### 8.2 Problem

Default WordPress / Elementor search does not reliably return the correct WooCommerce products (SKU, partial names, vendor identifiers, etc.).

### 8.3 Backend improvements (plan)

Improve product discovery for queries coming from this search (and standard `?s=` / product search endpoints Elementor uses), without touching markup:

| Improvement | Detail |
|-------------|--------|
| Restrict / prioritize `product` post type | Search should surface Woo products first (or only products if that matches store UX) |
| SKU search | Match `<_sku>` and variation SKUs |
| Title / content relevance | Better `LIKE` / token matching for product names |
| Vendor IDs | Optionally match `amazon_asin`, `wcss_gt_part_id` so staff can find by ASIN/part |
| Exclude junk | Drafts, hidden catalog items, placeholder products (reuse existing placeholder helpers if present) |
| Live results (if Elementor AJAX) | Hook the same relevance into whatever query Elementor Search uses for `aria-controls="results-d17f53a"` — still no CSS/markup changes |

**Likely hooks (implementation later):** `pre_get_posts`, `posts_search` / `posts_join` / `posts_where` for product meta, and/or Woo `woocommerce_product_data_store_cpt_get_products_query` — exact hook depends on whether Elementor uses WP search vs REST.

### 8.4 Out of scope for this workstream

- Redesigning search input, results dropdown, or header layout
- Replacing Elementor Search with a custom React/autocomplete widget
- Changing placeholder text or combobox ARIA unless required for a pure backend fix

---

## 9. Suggested delivery phases

| Phase | Deliverable | Exit criteria |
|-------|-------------|---------------|
| **P0 — Plan lock** | This doc + open decisions (§10) | Stakeholder sign-off |
| **P1 — Schema + sync** | Table, migrator, hourly job, admin sync | Prices land in DB for matched products |
| **P2 — Frontend cheapest** | Shop/product/cart use cheapest; Woo fallback | `/shop` shows correct prices without vendor UI |
| **P3 — Remove cart vendor select** | No `.cc-cart-vendor`; checkout column policy applied | Manual select gone end-to-end |
| **P4 — Checkout chrome + layout** | Cart-parity pagehead (breadcrumb, title, back), aligned columns, alert under steps | Visual QA on `/checkout` matches cart professionalism + forced-error alert |
| **P5 — Header search backend** | Product/SKU/(optional ASIN·part) relevance for Elementor `#search-d17f53a` | Known products found by name/SKU without UI changes |

Optional later: manager compare UI, CSV export of price gaps, email when vendor rises above Woo.

---

## 10. Open decisions (need your next plan / answers)

1. **Checkout Vendor column:** Remove entirely vs show read-only auto winner (Amazon / G&T / Store)?
2. **Variable products:** Sync per variation or parent only?
3. **Price display vs cart write:** Show cheapest on shop only, or also rewrite cart line meta for procurement routing (which vendor “wins” for fulfill)?
4. **Order fulfillment:** When cheapest is Amazon, should line auto-tag `_cc_vendor=amazon` for manager Amazon/G&T flows?
5. **Action Scheduler vs `wp_cron`:** Prefer AS (recommended) — confirm hosting cron hits `wp-cron.php` reliably.
6. **Where does code live:** Child theme vs `wcss-internal-supplies` plugin (plugin preferred for cron/table longevity)?
7. **Header search scope:** Products only vs products + pages/posts? Include `amazon_asin` / `wcss_gt_part_id` in match set?
8. **Checkout title copy:** Exact H1 + lead string + back-button label (“Back to cart” vs “Previous”)?

---

## 11. Risks & mitigations

| Risk | Mitigation |
|------|------------|
| API rate limits / timeouts | Batch + stagger; mark `error`, keep last good price |
| Stale cheapest after Woo sale change | Hook `woocommerce_update_product` to recompute cheapest from DB + new Woo price |
| Cart already has old manual vendor session | Clear `cc_vendor*` on migrate; force re-add or cart refresh |
| Cached shop pages | Bust product/HTML cache when `_cc_cheapest_*` updates |
| Cron never runs on low traffic | System crontab → `wp cron event run` or real HTTP cron |
| Search becomes too broad / noisy | Rank SKU/title exact matches first; optional products-only mode |
| Elementor Search uses opaque AJAX | Inspect network for results endpoint; hook that query path, not only classic `?s=` |
| Checkout chrome fights Elementor templates | Suppress theme title; inject chrome via Woo hooks early, same as cart |

---

## 12. Test plan (high level)

- [ ] Product with Amazon + G&T + Woo: shop shows true minimum  
- [ ] Product with only Woo: shop shows Woo price  
- [ ] Product with ASIN but Amazon fetch fail: keep last ok price or fall back to Woo (define policy: **last good preferred, else Woo**)  
- [ ] Hourly job updates price when vendor changes  
- [ ] Cart has no vendor dropdown; totals match cheapest  
- [ ] Checkout pagehead matches cart pattern (breadcrumb, title, back, steps)  
- [ ] Checkout columns top-aligned desktop + mobile stack  
- [ ] Force checkout validation error → prominent alert under steps, not a quiet top notice  
- [ ] Header `#search-d17f53a`: find product by full title  
- [ ] Header search: find product by SKU  
- [ ] Header search: (if enabled) find by ASIN / G&T part  
- [ ] Header search UI unchanged (same input, no new chrome)

---

## 13. Next input from you

Provide the **next plan** (or answers to §10). Implementation starts only after you confirm this document and the open decisions.
