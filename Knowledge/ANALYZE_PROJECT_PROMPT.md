# Prompt — Analyze CC Supplies (full flow)

Paste everything below the line into a new Cursor Agent chat after the repo is available and custom theme/plugin code is synced into the Local WordPress site.

---

You are working on **CC Supplies** (Canna Cabana internal stationery / supplies portal).

**Repo:** https://github.com/usman-hightide/ccsupplies  
**Preferred working branch (current feature work):** `feature/store-cheapest-pricing-checkout-ux-2026-10-30`  
**Also important historical demo branch:** `feature/gt-amazon-knowledge-demo-2026-10-28`  
**Live:** https://ccsupplies.ca  
**Local:** https://ccsupplies.local  

## Must-have before analyzing

1. Pull latest from GitHub on the working branch above.  
2. Sync these custom folders into the Local site `wp-content` (see root `README.md`):  
   - `themes/adex360-canna-cabana-stationery-child/`  
   - `themes/adex360-cannacabana-stationery/` (parent)  
   - `plugins/wcss-internal-supplies/`  
3. Restore local DB from Knowledge if the Local DB is empty/old:  
   - `Knowledge/db-backups/ccsupplies-local-db-LATEST.sql`  
   - Steps in `Knowledge/db-backups/README.md`  
4. Local `wp-config.php` must define Amazon + Grand & Toy constants (names only in docs; values never in git): `AMAZON_*`, `CC_GT_*`.  
5. Read **in this order** and treat as source of truth:  
   1. `Knowledge/PROJECT_STATUS_AND_CHAT_HISTORY.md`  
   2. `Knowledge/README.md`  
   3. `Knowledge/client-shared/meetings/MEETING_2026-10-29_INTERNAL_DEMO.md`  
   4. `Knowledge/plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md`  
   5. `docs/PLAN-vendor-cheapest-pricing-and-checkout-ux.md`  
   6. `Knowledge/plans-audits/PLAN_Separate_Amazon_GT_Dashboards_2026-10-27.md`  
   7. `Knowledge/plans-audits/CLAUDE_BRIEF_Supply_Manager_Dashboard_Revamp.md` (UI direction for Supply Manager)

Do **not** invent requirements. Prefer Knowledge + current code. If status doc and code disagree, say so explicitly.

## Product rules (non-negotiable)

- **Amazon ≠ Grand & Toy.** Separate catalogs, carts, checkouts, manager order views. Do not merge into one “vendor compare” primary UX (Basit).  
- **Store employee:** shops Woo catalog; places **Internal Requisition**. Must **not** place Amazon orders. May open G&T catalog pages. Must **not** choose Amazon vs G&T on cart (Ops decision 29 Oct — cheapest price auto).  
- **Supply Manager (Ops — Alyshia / Hillel / Chris):** `/manager/` portal. Today: approve Woo orders, then Amazon Test Order or G&T Test Order. Direction after 29 Oct: auto-approve in-policy orders and auto-submit to cheapest vendor; portal becomes exception + reporting focused. Rename away from plain “Manager Dashboard.”

## Current end-to-end flows (understand all of these)

### Flow A — Store Woo requisition (live pattern)

Login store employee → `/shop/` → cart → checkout Internal Requisition → status **Awaiting Approval** → wait for Ops.

### Flow B — Ops Amazon (live)

`/manager/orders` → Approve → Amazon Test Order → `/amazon-catalog/?order-id=` → cart → checkout. Gates: approved + monthly cap + 5% price + Trial Mode. View: `/manager/orders/amazon-view/{id}`.

### Flow C — Ops Grand & Toy (live)

`/manager/orders` → G&T Test Order → `/gt-catalog/?order-id=` → cart → `/gt-checkout/` stage PO → `/manager/orders/gt-view/{id}` (`cart_saved` / `submitted`).

### Flow D — Storefront cheapest pricing (local feature branch)

Hourly (or Action Scheduler) sync of Amazon + G&T prices into `{prefix}cc_vendor_prices`; shop/cart show cheapest; manual vendor dropdown removed. Checkout vendor column hide for stores still open. See cheapest-price plan.

### Flow E — Target after 29 Oct demo (not fully built)

In-policy: auto-approve + auto-submit to winning vendor. Exceptions only to Alyshia. Ordering windows + CAD **$125** budget (Chris). Supply Manager reports: Amazon vs G&T spend without Excel pivots. Full list in Aman requirements plan.

## What to produce when asked to “analyze”

1. Short map of **roles** (store employee vs Supply Manager).  
2. Diagram or bullet of **Flows A–E** with what is live vs local-only vs planned.  
3. File map: theme Amazon/G&T modules + plugin manager portal.  
4. Gap list vs Aman plan (prioritized).  
5. Do not expose secrets; never invent API credentials.

## QA pointers

See accounts and demo steps in `Knowledge/PROJECT_STATUS_AND_CHAT_HISTORY.md` § QA / flows.
