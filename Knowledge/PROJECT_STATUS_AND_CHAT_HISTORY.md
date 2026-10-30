# PROJECT STATUS & CHAT HISTORY — CC Supplies

**Last updated:** 2026-10-31  
**Owner (dev):** Usman Zahid (`uzahid@basecampconsulting.co`) — Basecamp Consulting / High Tide  
**Live:** https://ccsupplies.ca  
**Local:** https://ccsupplies.local  
**GitHub:** https://github.com/usman-hightide/ccsupplies  

**Active feature branch (storefront cheapest + checkout UX):** `feature/store-cheapest-pricing-checkout-ux-2026-10-30`  
**Prior G&T + Knowledge demo branch:** `feature/gt-amazon-knowledge-demo-2026-10-28`  

**Analyze prompt:** [`ANALYZE_PROJECT_PROMPT.md`](./ANALYZE_PROJECT_PROMPT.md)  
**Post–29 Oct task plan:** [`plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md`](./plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md)  
**Local DB:** [`db-backups/ccsupplies-local-db-LATEST.sql`](./db-backups/ccsupplies-local-db-LATEST.sql)

---

## 0. What must be present to understand / continue this project

| Item | Location |
|------|----------|
| This status doc | `Knowledge/PROJECT_STATUS_AND_CHAT_HISTORY.md` |
| Meeting 29 Oct (Aman demo) | `Knowledge/client-shared/meetings/MEETING_2026-10-29_INTERNAL_DEMO.md` (+ PDF + transcript) |
| Aman requirements plan | `Knowledge/plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md` |
| Supply Manager UI brief | `Knowledge/plans-audits/CLAUDE_BRIEF_Supply_Manager_Dashboard_Revamp.md` |
| Separate Amazon ≠ G&T plan | `Knowledge/plans-audits/PLAN_Separate_Amazon_GT_Dashboards_2026-10-27.md` |
| Cheapest price + checkout UX plan | `docs/PLAN-vendor-cheapest-pricing-and-checkout-ux.md` |
| Child theme (Amazon + G&T + cheapest) | `app/public/wp-content/themes/adex360-canna-cabana-stationery-child/` |
| Manager portal plugin | `app/public/wp-content/plugins/wcss-internal-supplies/` |
| Local DB dump | `Knowledge/db-backups/ccsupplies-local-db-LATEST.sql` |
| Secrets (not in git) | Local `wp-config.php` → `AMAZON_*`, `CC_GT_*` |

---

## 1. Current process

### A. Store employee — Woo requisition (production)

1. Login → `/my-account/`  
2. Shop `/shop/` → cart `/cart-2/` → checkout `/checkout-2/`  
3. Payment **Internal Requisition** → status **Awaiting Approval**  
4. Track at `/my-account/orders/`  

**G&T storefront:** store employees **may** use `/gt-catalog/`, `/gt-cart/`, `/gt-checkout/` (stage cXML).  
**Amazon:** store employees **must not** place Amazon orders (AJAX requires manager caps → 403).

**29 Oct Ops direction (Chris / Aman):** stores must **not** see or pick vendor. Show one price = cheapest of Amazon vs Grand & Toy. Budget visibility yes; vendor name no.

### B. Supply Manager — Amazon path (production)

1. `/manager/orders/` → Approve Woo order (does **not** call Amazon)  
2. **Amazon Test Order** → `/amazon-catalog/?order-id={id}`  
3. Search → Amazon cart → Amazon checkout  
4. Gates: approval + monthly cap (historically CAD $120 in Amazon checkout; Chris now says store budget **$125**) + **5%** price + Trial Mode  
5. View: `/manager/orders/amazon-view/{id}`  

### C. Supply Manager — Grand & Toy path (live since Oct 28)

1. `/manager/orders/` → **G&T Test Order** → `/gt-catalog/?order-id={id}`  
2. Search → Check stock → G&T cart (`cart_saved` on order meta)  
3. `/gt-checkout/?order-id={id}` → stage PO → `submitted`  
4. View: `/manager/orders/gt-view/{id}`  

**Rule (Basit):** Amazon ≠ G&T — separate nav, catalog, cart, checkout, manager views.

### D. Storefront cheapest pricing (local / feature branch)

- Table `{prefix}cc_vendor_prices`; meta `_cc_cheapest_*`  
- Hourly sync; shop/cart use cheapest; manual cart vendor picker removed  
- Plan: `docs/PLAN-vendor-cheapest-pricing-and-checkout-ux.md`  
- Still open vs Aman: hide checkout Vendor column for stores; budget alert at top; Back to cart; default budget **$125**

### E. Target automation (decided 29 Oct — not fully shipped)

- In-policy orders: **auto-approve** + **auto-submit** to winning vendor  
- Alyshia only for exceptions (over budget / outside ordering window / second order)  
- Ordering windows configurable; one order per store per month  
- Supply Manager **reports** (Amazon vs G&T spend) — current dashboard export is insufficient  

---

## 2. Recent work timeline

| When | Work |
|------|------|
| Oct 27 | Checkpoint; Amazon vs G&T product audit; separate-dashboards plan; local DB dump |
| Oct 27–28 | G&T theme pages + manager G&T mirror of Amazon UX |
| Oct 28 | Live deploy + QA order **#8226**; Knowledge pack; jordan.nguyen QA user |
| Oct 29 | Internal demo with Aman, Chris, Alyshia, Basit, Bhaumik, Moiz — see meeting notes |
| Oct 29–30 | Store cart/checkout vendor UI branches; then **cheapest auto-price** feature branch |
| Oct 31 | Aman requirements plan + Supply Manager Claude brief; Knowledge refresh; DB `LATEST` dump |

---

## 3. Done (shipped / verified)

- [x] Amazon manager path (trial): catalog, cart, checkout gates, amazon-view  
- [x] Separate Amazon vs G&T manager UX  
- [x] G&T catalog / cart / checkout + soft AJAX nonce for PressCACHE  
- [x] Manager **G&T Test Order** + **G&T order view**  
- [x] Live QA #8226 stage PO  
- [x] Store QA user **jordan.nguyen** → Test store `#8177`  
- [x] Approved Item List CSVs in Knowledge  
- [x] Local cheapest vendor pricing stack (feature branch) — cache table, sync, shop/cart apply  
- [x] Meeting 29 Oct filed (PDF + notes + transcript)  
- [x] Aman post-demo plan + Supply Manager UI brief in Knowledge  

---

## 4. Pending / open

| Item | Owner | Notes |
|------|-------|-------|
| Auto-approve + auto-submit happy path | Usman / Basit | Aman plan §C |
| CAD **$125** budget everywhere (not $160 / not old tiers) | Usman / Chris | Chris on 29 Oct |
| Ordering windows (e.g. days 1–7 vs 7–15 by store group) | Usman | Aman plan §B3 |
| Exception path to Alyshia (form/email OK for launch) | Usman / Alyshia | Aman plan §B4–B5 |
| Hide vendor from store checkout | Usman | Cheapest plan leftover |
| Supply Manager rename + reports (Amazon vs G&T spend) | Usman | Claude brief + Aman §E |
| Orders tab = exception-first queue | Usman | Aman plan §3 |
| Unmonitored supply mailbox on vendor submit | Usman / Chris | Aman §D2 |
| Stop test emails to Aman (`asood@hightideinc.com`) | Usman | Confirmed on call |
| Stakeholder demo **4 Nov 2026** | All | Aman required; Bhaumik+Basit QA first |
| PressCACHE purge bare `/gt-catalog/` if needed | Usman / host | Soft-nonce mitigates |
| SenderIdentity store email vs `588` | Chris / Usman | Stage default `588` |
| Amazon store-wide release | Jai / Basit | Still gated |
| G&T production endpoint | G&T / Chris | Stage only |

---

## 5. People

| Person | Role | Decisions / inputs |
|--------|------|---------------------|
| **Aman Sood** | Stakeholder | Auto-approve; no store vendor UI; cheapest price; reports; Supply Manager naming; 4 Nov demo |
| **Muhammad Basit Munir** | Product lead | Amazon ≠ G&T separate; price sync cron; owns notes on calls |
| **Bhaumik Dhandhukia** | Eng / PM | Cheapest via both APIs; reports filters; QA before demos |
| **Chris Bilton** | Ops | No store vendor; $125 all stores; one order/month; windows; approvers = Alyshia/Hillel/Chris |
| **Alyshia Digiovannantonio** | Ops | Approvals / exceptions; ASIN entry; OK with exception form |
| **Jai Radhakrishnan** | Amazon stakeholder | Architecture / product search emails |
| **Usman Zahid** | Developer | Implementation, Knowledge, demos |

---

## 6. Emails in Knowledge

| File | Purpose |
|------|---------|
| `emails/EMAIL_TO_JAI_ARCHITECTURE.md` | Architecture DOCX |
| `emails/EMAIL_TO_JAI_PRODUCT_SEARCH.md` | Product search |
| `emails/AMAZON_MEETING_MOM_EMAIL.md` | Amazon trial demo MOM |

Drafts are templates — not proof of send.

---

## 7. Client data in Knowledge

### Approved item lists — `client-shared/approved-item-lists/`

- All Locations, The Well, Thompson (NCN), Thunder Bay, Store Use Chemical Project  

### ASIN / products — `client-shared/asin-sheet/`

- High Tide Product List.xlsx, linked/missing ASIN CSVs, SAFE maps, audit CSVs  

### Meetings — `client-shared/meetings/`

- Call with Chris and 1 other.docx  
- Internal Demo of Amazon and Grand Toy 2026-10-29.pdf + transcript + `MEETING_2026-10-29_INTERNAL_DEMO.md`  

---

## 8. Flows cheat-sheet

### Store Woo order

1. https://ccsupplies.ca/my-account/ → **jordan.nguyen** / `CcStoreOrder26!`  
2. Add product → Internal Requisition → note order #  

### Manager Amazon

1. `/manager/orders/` → Approve → Amazon Test Order → Trial Mode path → amazon-view  

### Manager G&T

1. G&T Test Order → catalog → cart_saved → checkout Province **ON**, SenderIdentity **588** stage → gt-view submitted  

### Store G&T vs Amazon

1. jordan.nguyen: `/gt-catalog/` search OK  
2. `/amazon-catalog/` search should **403**  

---

## 9. QA accounts (live)

| Account | Password | Role | Store |
|---------|----------|------|-------|
| `jordan.nguyen` | `CcStoreOrder26!` | store_employee | `#8177` |
| `storeowner.gt.qa` | `StoreTest123!` | store_employee | `#8177` |
| Manager | Usman admin / shop_manager | portal | — |

**Never commit production Amazon/G&T API secrets.**

---

## 10. Key code paths

### Theme (`adex360-canna-cabana-stationery-child/`)

- `amazon-apis-integration.php` — Amazon path  
- `cc-gt-procurement.php`, `gt-catalog.php`, `gt-cart.php`, `gt-checkout.php`  
- `cc-vendor-prices.php`, `cc-vendor-prices-sync.php`, `cc-vendor-cheapest.php` — cheapest stack  
- `cc-cart-vendor.php` — manual picker retired  
- `cc-cart-ui.php` / checkout templates — checkout UX  

### Plugin (`wcss-internal-supplies/`)

- `frontend/pages/dashboard.php`, `orders.php`, `orders-amazon-view.php`, `orders-gt-view.php`  
- `frontend/pages/partials/manager-nav.php`  
- `frontend/assets/js/manager.js`, `css/manager.css`  
- `includes/rest/class-rest-orders.php`, reports overview endpoints  
- Quota: `class-order-quota.php`, store meta `_wcss_monthly_quota`  

---

## 11. Definition of ready (by milestone)

### Live demo-ready (Amazon + G&T manager paths) — met Oct 28

Store Woo + manager Amazon trial + manager G&T stage PO.

### Next stakeholder demo (4 Nov) — target

Aman plan P0–P1 where possible: cheapest storefront behaviour, budget $125 direction, clear story on auto-approve / reports roadmap; Supply Manager naming; no Aman test spam.

---

## 12. How agents should answer

Always prefer this file + meeting notes + Aman plan + current branch code. State live vs local-feature vs planned. Never invent secrets.
