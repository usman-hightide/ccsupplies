# PLAN — Separate Amazon vs Grand & Toy Manager + Storefront

**Date:** 2026-10-27  
**Source:** Basit direction (call with Usman) — Amazon and G&T must stay **separate vendors / separate dashboards**. Do not mix.  
**Git checkpoint (rollback):** branch `feature/amazon-gt-checkpoint-2026-10-27` @ `c1cdc30` pushed to origin.

---

## 0. Non‑negotiables (Basit)

| Rule | Meaning |
|------|---------|
| Amazon ≠ G&T | Separate nav, separate catalog, separate cart, separate place-order, separate manager order views |
| Mirror Amazon pattern | G&T gets the same *shape* of UX: Catalog → Cart → Checkout/Place → Manager order view (approve / edit / delete as Amazon allows) |
| Easy UI/UX | Store owner and manager must understand without training deck |
| Approved list drives G&T | Part numbers + location sheets from Ops Excel |
| Do not break Amazon | Leave existing Amazon path intact; only add G&T parallel + untangle mixed compare as primary UX |

**Do not:** make Vendor Compare the main procurement UI. Keep Amazon and G&T as two independent purchase paths.

---

## 1. What Amazon already has (mirror target)

### Manager
| Surface | URL / entry | Role |
|---------|-------------|------|
| Orders list | `/manager/orders` | List Woo orders; **Amazon Test Order** button |
| Amazon order detail | `/manager/orders/amazon-view/{id}` | View Amazon line items / totals saved after cart-checkout |
| Woo order view | `/manager/orders/view/{id}` | Approve / reject / edit context (existing WCSS flow) |
| Products | `/manager/products` (+ Amazon-only filter) | Catalog CRUD |
| Amazon Catalog link | `/amazon-catalog/` | ASIN search → add to Amazon cart |

### Amazon purchase path (theme)
| Step | URL | API / behaviour |
|------|-----|-----------------|
| Catalog | `/amazon-catalog/` | `POST …/getProductsByAsins` + `OFFERS` facet |
| Cart | `/amazon-cart/` | Amazon Business Cart API |
| Checkout | `/amazon-checkout/` | Ordering API + Trial Mode / approval / $120 / 5% gates |

### Store employee (storefront today)
| What they see | How they order |
|---------------|----------------|
| Shop / categories / cart | Normal WooCommerce |
| Checkout payment | **Internal Requisition** → status Awaiting Approval |
| Amazon | **Not** a storefront self-serve Amazon checkout for employees (manager Amazon Test Order path) |

**Audit task:** re-walk a `store_employee` account and document exact screens (screenshot checklist) before designing G&T storefront exposure.

---

## 2. What G&T has today (not a dashboard yet)

| Piece | Status |
|-------|--------|
| Stage cXML client | Live — stock + PO helpers; `/?gtoy_stock=1` works from production |
| Product ASIN + eligibility admin | Live (shared product fields; eligibility can mark `gt`) |
| Manager Products G&T filter | Live (taxonomy filter only) |
| Vendor Compare (mixed Amazon+G&T) | Live private page — **demote**; not the Basit primary UX |
| G&T Catalog / Cart / Checkout UI | **Missing** |
| Manager G&T order view | **Missing** |
| Store-owner G&T purchase UX | **Missing** |
| Official multi-sheet Approved catalogue in repo | **Missing** (see §5) |

---

## 3. Target architecture (separate)

```
MANAGER NAV
├── Orders (shared Woo list)
│     ├── [Amazon] Test Order / Amazon view   ← existing
│     └── [Grand & Toy] G&T Order / G&T view  ← new
├── Products (shared) — filters Amazon | G&T
├── Amazon catalog  → /amazon-catalog/        ← existing (unchanged)
└── Grand & Toy     → /gt-catalog/            ← new hub

AMAZON PATH (do not change behaviour)
  Catalog → Amazon Cart → Amazon Checkout → Amazon order meta

G&T PATH (new parallel)
  G&T Catalog (approved parts) → G&T Cart (local) → G&T Confirm/PO (stage) → G&T order meta
```

### Suggested new URLs (theme page templates)
| Page | Purpose |
|------|---------|
| `/gt-catalog/` | Search approved G&T parts for a store/facility; stock check; add to G&T cart |
| `/gt-cart/` | Review G&T lines (part, qty, UOM, price from last stock check) |
| `/gt-checkout/` | Ship-to from store; SenderIdentity; BO/FP; submit stage PO (later production) |
| `/manager/orders/gt-view/{id}` | Manager read of G&T PO acknowledgement / lines |

Manager nav: add **Grand & Toy** next to Amazon Catalog — not under a mixed “Vendor compare” primary link.

---

## 4. Work sequence (senior delivery order)

### Phase A — Freeze & understand (same day start)
1. ~~Git checkpoint branch pushed~~ `feature/amazon-gt-checkpoint-2026-10-27`
2. Storefront audit as `store_employee`: list every order step + screenshot notes
3. Amazon manager path dry-run: Test Order → Catalog → Cart → Checkout (Trial) → amazon-view
4. Confirm Basit Excel (multi-sheet) received — **blocker for catalogue UI**

### Phase B — Data model for G&T (before fancy UI)
1. Import Approved Item List → product meta / custom table / option:
   - `wcss_gt_part_id` (required)
   - location / facility mapping (postal → DC)
   - UOM, description
2. Map Woo SKUs ↔ G&T part where possible; leave unmapped visible as “needs Ops”
3. Do **not** invent parts from Chemical SDS sheet alone

### Phase C — G&T Catalog UI (manager-first MVP)
1. Page template styled like Amazon Catalog (black/red brand language already used)
2. Search by name / G&T part / store SKU
3. Live stage stock check button (reuse `cc_gt_stock_check`)
4. Add to **G&T cart** (user meta or WC session key `wcss_gt_cart` — not Amazon cart)

### Phase D — G&T Cart + Confirm
1. Qty edit / remove
2. Show last price / availability / facility
3. Confirm screen: ship-to store address, SenderIdentity rule (Chris: store emails — confirm mapping)
4. Submit **stage** PO via `cc_gt_submit_order`; save acknowledgement on Woo order or custom post
5. No production G&T host until configured

### Phase E — Manager G&T order dashboard
1. Orders list: button **G&T Order** (parallel to Amazon Test Order)
2. `gt-view` page: lines, status Valid/errors, OrderID, link back to Woo order
3. Approve / edit / delete: **reuse existing Woo order capabilities**; G&T view is procurement detail (same pattern as amazon-view)

### Phase F — Store owner exposure (after manager MVP stable)
1. Decide with Basit/Chris: does store employee buy G&T directly, or only Internal Requisition + Ops procures?
2. If store sees G&T: limited catalog of approved items for their location only
3. If Ops-only: storefront unchanged; manager G&T path is enough for v1

### Phase G — Untangle mixed compare
1. Keep `/vendor-compare/` as **optional Ops tool**, not main nav
2. Primary nav = Amazon Catalog | Grand & Toy Catalog (separate)

---

## 5. Approved Item List — current gap

**In workspace now:**
- `docs/Approved Item List 1(Store Use Chemical Project).csv` — **one** chemical/SDS sheet (~16 rows). Columns: Description, Part Number, SDS eye treatment, Suggested replacements (G&T links). **Not** a multi-location catalogue.

**Basit said:** Excel with **multiple sheets** (parts + locations).  

**Action for Usman:** Ask Basit to **re-send** `Approved Item List 1.xlsx` (full workbook). Without location sheets we cannot correctly bind facilityID / store inventory views.

---

## 6. Realistic “today” vs “complete product”

Basit asked to finish today. Senior plan:

| Today (MVP slice) | Not same-day if quality matters |
|-------------------|----------------------------------|
| Checkpoint on GitHub | Full storefront G&T for all 230 stores |
| Storefront + Amazon audit notes | Production G&T endpoint |
| G&T catalog page shell + stock check for mapped parts | Perfect SenderIdentity for every store email |
| Local G&T cart session | Full approve/edit/delete redesign beyond Amazon parity |
| Manager nav link Grand & Toy | Import all multi-sheet Excel until file arrives |

**Honest gate:** Full Amazon-parity G&T (catalog+cart+PO+manager view+store UX) is a **multi-day** build. Same-day = scaffold + stock-driven catalog + cart stub + clear separation in nav.

---

## 7. QA checklist (every slice)

- [ ] Amazon path still: catalog ASIN → cart → trial checkout  
- [ ] G&T path never writes to Amazon cart  
- [ ] Stage-only G&T host guard still on  
- [ ] Store employee cannot hit admin-only test URLs  
- [ ] Missing G&T part → clear empty state (no fake price)  
- [ ] Manager can open G&T view without PHP errors  
- [ ] Mobile + desktop readable  
- [ ] No secrets in git  

---

## 8. Immediate next implementation step (after plan approval)

1. Usman: request full multi-sheet Approved Item List xlsx from Basit  
2. Agent: storefront/`store_employee` audit on production or local  
3. Agent: create `/gt-catalog/` page template + manager nav item **Grand & Toy** (Amazon untouched)  
4. Wire stock check for one known part (`33111`) end-to-end in that UI  

---

## 9. Rollback

```bash
git fetch origin
git checkout feature/amazon-gt-checkpoint-2026-10-27
# or reset a bad branch to c1cdc30
```
