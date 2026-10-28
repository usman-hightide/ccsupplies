# Prompt to paste into Cursor on the OTHER system

Copy everything below the line into a new Cursor Agent chat after you pull the branch.

---

You are working on **CC Supplies** (Canna Cabana internal stationery portal).

**Repo:** https://github.com/usman-hightide/ccsupplies  
**Branch to use:** `feature/gt-amazon-knowledge-demo-2026-10-28`  
**Live:** https://ccsupplies.ca  
**Local:** https://ccsupplies.local  

## First actions (do these before answering product questions)

1. `git fetch origin && git checkout feature/gt-amazon-knowledge-demo-2026-10-28 && git pull`
2. Sync theme + plugin into the Local site (paths in `README.md`).
3. **Read and keep as source of truth:**
   - `Knowledge/PROJECT_STATUS_AND_CHAT_HISTORY.md`
   - `Knowledge/README.md`
   - `Knowledge/plans-audits/PLAN_Separate_Amazon_GT_Dashboards_2026-10-27.md`
4. Do **not** invent requirements. Prefer Knowledge + code over old `CURSOR-HANDOFF.md` (which is outdated vs Oct 28).

## Product rules (non-negotiable)

- **Amazon ≠ Grand & Toy.** Separate catalogs, carts, checkouts, manager views.
- **Store employee:** Woo shop + Internal Requisition; **may** use G&T pages; **must not** place Amazon orders.
- **Manager:** Approves Woo orders; runs **Amazon Test Order** or **G&T Test Order** against `?order-id=`.

## What is already done (as of 2026-10-28)

- G&T theme: `cc-gt-procurement.php`, `gt-catalog.php`, `gt-cart.php`, `gt-checkout.php` (soft nonce for CDN).
- Manager: G&T Test Order button, `/manager/orders/gt-view/{id}`, REST G&T DTO, shared Amazon-like UI styles.
- Live QA passed on order **#8226** (cart_saved → stage PO submitted).
- QA store user: `jordan.nguyen` / `CcStoreOrder26!` → Test store `#8177`.
- Client files live under `Knowledge/client-shared/` (Approved Item Lists, ASIN sheets, Chris call).

## Demo flows

1. Store Woo order as jordan.nguyen → Awaiting Approval.  
2. Manager Amazon Test Order → amazon-view (Trial Mode + gates).  
3. Manager G&T Test Order → gt-view (`cart_saved` then `submitted`). Checkout: Province **ON**, Deliver to = person, SenderIdentity **588** for stage.

## Pending

- PressCACHE purge for bare G&T HTML if search flakes.  
- Chris SenderIdentity per store email confirmation.  
- Basit full multi-sheet Excel if more locations needed.  
- Amazon store-wide release still gated (Jai/Basit).  
- G&T production endpoint not live (stage only).

## When I ask questions

Always answer from **Knowledge/PROJECT_STATUS_AND_CHAT_HISTORY.md** + current branch code. If something conflicts, say what the status doc claims vs what the code shows. Never expose or invent API secrets; secrets stay in `wp-config.php` only (`AMAZON_*`, `CC_GT_*`).

Today is the **final demo** — prioritize demo stability and accurate role/path answers over new features unless I ask to implement.