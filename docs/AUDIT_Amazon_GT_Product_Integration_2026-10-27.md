# Technical Audit: Amazon + Grand & Toy Product Integration

**Date:** 2026-10-27  
**Auditor:** Cursor agent (read-only)  
**Evidence sources:** Live admin `ccsupplies.ca` (Usman session), child theme + `wcss-internal-supplies` plugin, `docs/asin sheet/*`, meeting transcript `docs/Call with Chris and 1 other.docx`  
**Branch:** `rejection-notices` (local uncommitted Amazon ASIN + vendor `&amp;` fixes)  
**Constraint:** No production data changes, no live Place Order, no credential values in this report.

---

## A. Executive summary

| Area | Verdict |
|------|---------|
| Amazon Product Search by ASIN | **Working** on linked products (`getProductsByAsins`, CA) |
| Amazon Cart → Checkout → Trial Mode order | **Implemented** (separate from comparison) |
| Tomas list ↔ Woo auto-link | **Partial** — OEM/SKU+colour sync linked 15; 92 have no ASIN |
| Dual-vendor “Scan Now” compare (Amazon + G&T) | **Not implemented** — no code/UI named Scan Now |
| Grand & Toy live price/stock in order flow | **Not wired** — cXML client exists (stage); stock often blocked (Incapsula 403 per handoff); not hooked to checkout |
| Product can be G&T-only without Amazon ASIN | **De facto yes** (missing ASIN only disables Amazon search); **no explicit “G&T-only” flag** |
| Automated vendor selection | **Not implemented** (correct vs Chris: Ops chooses) |

**Blocking progress:** (1) Ops/Alyshia clarifying which of 92 are Amazon-buyable vs G&T-only; (2) G&T catalogue + stage connectivity; (3) no multi-vendor comparison feature yet.

**Number confusion (meeting vs files):** 112 / 118 / 119 / 26 / 92 are **different universes** — see §B. Not a single broken counter.

---

## B. Product reconciliation (verified counts)

### Source lists found in repo

| Source | Location | Exact count | Columns |
|--------|----------|-------------|---------|
| Tomas / High Tide Product List | `docs/asin sheet/High Tide Product List.xlsx` + `asin_list_parsed.json` + theme `amazon-asin-catalog.json` | **112** unique ASINs | `ASIN`, `Product Description` only |
| Woo published catalog (production) | Live `/amazon-catalog/` export 2026-10-26 → CSVs | **118** products | Woo ID, Store SKU, name, optional `amazon_asin` |
| Approved Item List – Chemical Project | `docs/Approved Item List 1(Store Use Chemical Project).csv` | SDS / G&T replacements | **No ASIN** — not Amazon mapping |
| “119” list Chris mentioned | — | **Not found** in `docs/` | Cannot verify Alyshia’s “7” without that file |

### Woo ↔ Amazon meta (production, verified)

| Metric | Count |
|--------|------:|
| Published Woo products | 118 |
| With `amazon_asin` meta | 26 |
| Without `amazon_asin` | 92 |
| Of the 26: ASIN **in** Tomas 112 list | **15** products (14 unique ASINs; one ASIN shared by two Epson 502 Magenta SKUs) |
| Of the 26: ASIN **not** in Tomas list (legacy) | **11** |
| Tomas ASINs unused by any Woo product | **98** |

### Why meeting numbers disagree

| Claim | Reality |
|-------|---------|
| “Tomas 118” | Usman misspoke / conflated with **Woo 118**. File = **112** ASINs |
| “Chris ~112” | Matches Tomas file |
| “119 + 7 gap” | Plausible list-vs-list; **second list file not in repo** — Alyshia work not verifiable here |
| “26 matched / 92 unmatched” | Correct **vs Woo catalog**: 26 have ASIN meta, 92 do not. **Not** “26 of 112 Tomas rows matched” |
| Expecting 112 Woo ASINs | Wrong target: many Woo SKUs are G&T-preferred / no Amazon buy (Chris call) |

### Matching algorithm (code)

**File:** `amazon-apis-integration.php` — `wcss_amazon_resolve_tomas_asin_for_product()` / Sync button.

1. Exact normalized title vs Tomas description  
2. Else OEM/SKU-style codes (+ ink colour filter)  
3. No weak fuzzy title match  
4. Writes `post_meta` key `amazon_asin`  
5. Runtime search: **requires** `amazon_asin`; calls Amazon `getProductsByAsins` (not keywords on catalog page)

**Product-level CSV:** `docs/asin sheet/AUDIT_Product_Reconciliation.csv` (118 rows).

---

## C. Current implementation

### Amazon (child theme)

| Component | File | Status |
|-----------|------|--------|
| ASIN lookup API helper | `amazon-apis-integration.php` | Implemented |
| Catalog UI + Sync | same + page template | Live |
| Cart API | `amazon-cart.php` | Implemented |
| Checkout / TrialMode / approval / $120 / 5% | `amazon-checkout.php` | Implemented |
| Order storage / view | `amazon-order-storage.php` | Implemented |
| Legacy keyword catalogItems | `amazon-catalogItems.php` | Present; catalog page path is ASIN |
| Manager entry | `Open Amazon Test Order` → `/amazon-catalog/?order-id=` | Implemented |

### Grand & Toy (child theme)

| Component | File | Status |
|-----------|------|--------|
| cXML stock check + Submit PO (stage) | `grand-and-toy-cxml.php` | Client implemented |
| Credentials | `wp-config.php` constants `CC_GT_*` (not in git) | Expected |
| Admin tests | `/?gtoy_stock=1`, `/?gtoy_po=1` | Implemented |
| Production G&T URL | Explicitly **not** configured in code | Stage only |
| Hook into store checkout / manager Scan | **Not found** | Gap |
| Product meta `gt_part` dedicated field | **Not found** | Store `_sku` often G&T-like part |

### Scan Now

**Repo-wide search:** no `Scan Now` / `scan_now` in PHP/JS.  
Chris’s dual-vendor scan is a **business intent**, not an existing feature.

### Vendor comparison / multi-supplier

Not implemented. Amazon path is Amazon-only once Ops opens Amazon Test Order. G&T is separate stage test URLs.

### Tests

No automated test suite found under `tests/` for these integrations.

---

## D. Requirements matrix (Chris call + prior thread)

| Chris / business requirement | Current implementation | Evidence | Status | Gap / risk | Recommended action |
|------------------------------|------------------------|----------|--------|------------|-------------------|
| Store places Woo order | Manager / employee portal | `wcss-internal-supplies` | Implemented | — | Keep |
| Ops compares vendors via Scan Now | No Scan Now | Grep empty | **Not implemented** | Ops cannot one-click compare | Design after G&T live; Basit priority |
| Pull Amazon price/stock/ship | Product Search + Cart | Theme Amazon files | **Partial** | Needs ASIN; offer IDs change | Keep ASIN path; refresh near order |
| Pull G&T price/stock | cXML stock check | `cc_gt_stock_check` | **Partial / blocked** | Stage 403; not in UI | Fix connectivity; then wire |
| Ops chooses vendor manually | Implicit (separate tools) | Call transcript | **Partial** | No unified UI | Build comparison UI later |
| Missing Amazon ≠ invalid product | Missing ASIN only blocks Amazon search | Catalog disabled button | **Implemented** (implicit) | No “G&T-only” label | Optional flag/meta |
| G&T-exclusive / pack-size unfit | No structured rule | Call + no field | **Not implemented** | Confusion with “92 unmatched” | Alyshia classify list |
| Don’t auto-place on cheapest | No auto vendor select | Code | **OK (absent)** | — | Do not add auto-buy |
| Multi-vendor future (Staples etc.) | Not present | — | Not implemented | Out of Phase 1 | Defer |
| Fill ASIN in admin | Custom field `amazon_asin` | Product edit #7632 | **Implemented** | Easy to miss (Custom Fields) | Optional dedicated Product Data field |
| SenderIdentity = store emails | G&T PO sender param | Call + `gtoy_sender` | Decision noted | Not fully wired per-store | Implement when PO wired |
| Backorder BO + note / secondary | Default BO in cXML | `grand-and-toy-cxml.php` | **Partial** | FP vs BO open historically | Confirm with Chris (said note/Ops) |

---

## E. Root causes

### Confirmed

1. **112 ≠ 118:** Tomas Amazon preferred list vs full Woo catalog.  
2. **92 “unmatched”:** No `amazon_asin` on Woo — includes G&T-preferred / no safe OEM match / not on Tomas list.  
3. **Only 15/26 linked ASINs are Tomas:** 11 legacy ASINs from earlier keyword era.  
4. **Tomas sheet has no Woo SKU/ID:** Auto-match limited to title/OEM.  
5. **No Scan Now / dual-vendor compare** in codebase.  
6. **G&T not in procurement UI;** stage stock historically Incapsula-blocked.  
7. **`amazon_asin` lives in Custom Fields**, not a first-class WooCommerce “Amazon” panel (but editable).

### Possible (unverified)

1. Alyshia’s **119 vs 112 → 7** items — need her second spreadsheet.  
2. Exact which of 92 are permanently G&T-only — needs Ops classification.  
3. Whether Store SKU always equals G&T `SupplierPartID` — often looks true (e.g. `98934`) but not proven for all.

---

## F. Action items by owner

### Usman (tech)

1. Keep Amazon ASIN search/cart/trial path stable for the 26 linked.  
2. Do **not** force ASINs onto all 92.  
3. When Ops adds ASINs in admin, re-test Search Amazon.  
4. Optional UX: dedicated “Amazon ASIN” field under Product data (avoid Custom Fields only).  
5. Optional meta: `procurement_channel` = `amazon` | `gt` | `both` | `unset`.  
6. G&T: continue stage stock/PO validation; do not wire production endpoint until G&T configures.  
7. Do **not** build Scan Now until Basit prioritizes and G&T stock API is reliable.  
8. Share `AUDIT_Product_Reconciliation.csv` + missing-SKU sheet with Chris/Alyshia.

### Basit (project)

1. Share official **G&T approved catalogue** (Chris said provided — confirm file to Usman).  
2. Prioritize: finish Amazon ASIN coverage for Amazon-buyable SKUs vs start dual-vendor Scan.  
3. Confirm go-live sequencing with Jai review still required for Amazon store release.

### Chris / Alyshia (Ops)

1. Finish list comparison (112 vs any 119 list); document the ~7 if real.  
2. On missing sheet / admin: add ASIN **only** where Amazon is an approved buy option.  
3. Explicitly mark / communicate G&T-only SKUs (scissors example).  
4. Confirm pack-size / price rules for “similar but not equivalent” Amazon items.

---

## G. Prioritized implementation plan (after approval)

| Priority | Change | Depends on | Acceptance |
|----------|--------|------------|------------|
| P0 | Ops ASIN fill for Amazon-buyable subset | Alyshia/Chris | Those SKUs Search Amazon OK |
| P0 | G&T stage stock returns price/qty (not 403) | Network / G&T allowlist | `/?gtoy_stock=1` parses prices |
| P1 | Dedicated ASIN admin field + optional G&T-only flag | Basit UX OK | Chris team can edit without hunting Custom Fields |
| P1 | Wire G&T stock into manager order tools (read-only compare) | Stage OK + part list | Ops sees G&T price next to Amazon when both linked |
| P2 | “Scan / compare” UI (manual choose, no auto-buy) | P1 | Matches Chris workflow |
| P3 | Multi-vendor (Staples etc.) | Business | Out of current scope |

**Safe to do in local/staging after approval:** admin field for ASIN; G&T-only flag; comparison UI mock with fixtures.  
**Needs Chris/Basit approval:** any auto-select vendor; production G&T endpoint; changing legacy 11 ASINs.

---

## Admin panel inspection (live session)

**URL checked:** `https://ccsupplies.ca/wp-admin/post.php?post=7632&action=edit` (HP 305A – Cyan)

| Observation | Result |
|-------------|--------|
| SKU field | Yes — `CE411A` |
| Regular price | Yes |
| `amazon_asin` | Yes — Custom Fields value `B006ZZGDIW` |
| Dedicated Amazon panel | No |
| Grand & Toy part meta | No dedicated field seen |
| Vendors taxonomy UI | Present in labels; dual Amazon/G&T assignment for comparison **not** observed as Scan workflow |
| Can edit without knowing Woo ID | Yes — product name + SKU + Custom Field ASIN |

**Products list:** `edit.php?post_type=product` accessible in same session.

---

## API notes (no secrets)

| Integration | Auth pattern | Live buy in audit |
|-------------|--------------|-------------------|
| Amazon Business | LWA / ABAPI (constants in wp-config) | Not invoked for purchase |
| G&T cXML | Shared secret + identities (`CC_GT_*`) | Not invoked (read-only code review) |

---

## Artifacts produced

- `docs/asin sheet/AUDIT_Product_Reconciliation.csv`  
- `docs/asin sheet/Amazon_Missing_SKU_ASIN_Mapping.csv` (92)  
- `docs/asin sheet/Amazon_Already_Linked_ASINs.csv` (26)  
- This report

---

## Limitation statement

- Alyshia’s second list (119) **not in workspace** — 7-item gap unverified.  
- G&T stage HTTP behaviour not re-tested in this audit pass (prior handoff: Incapsula 403).  
- Vendor taxonomy term assignment per product not exhaustively scraped for all 118.  
- No automated tests to run.
