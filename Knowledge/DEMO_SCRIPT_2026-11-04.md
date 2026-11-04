# Demo / walkthrough — Supply Manager (updated 4 Nov 2026)

**Branch:** `feature/auto-vendor-submit-manager-ux-2026-11-02`  
**Full reference:** [IMPLEMENTATION_GUIDE_2026-11-02.md](IMPLEMENTATION_GUIDE_2026-11-02.md)  
**Post-demo plan:** [../docs/PLAN_After_Internal_Demo_2026-11-03.md](../docs/PLAN_After_Internal_Demo_2026-11-03.md)  
**Internal demo notes:** [client-shared/meetings/MEETING_2026-11-03_INTERNAL_DEMO.md](client-shared/meetings/MEETING_2026-11-03_INTERNAL_DEMO.md)  
**Local URL:** https://ccsupplies.local/manager  

**Logins**

| Role | Account |
|------|---------|
| Supply Ops | `Supply_Manager` / `localadmin` |
| Store (recommended) | `storeowner` / `StoreOwner1!` → **Test store** |
| Store (also linked) | `storeowner.gt` · `uzahid` (temp dual role — local only) |

> **Before any click-through:** install local mail redirect; preferably `cc_auto_vendor_submit_enabled` → false so Amazon is not hit. Details in the implementation guide §3.4.

---

## What we shipped (say this once, ~60–90 seconds)

| Theme | What’s on this branch |
|-------|------------------------|
| **Policy** | Default **CA$120–125 / 1 order / month**, date window (from–to, optional *Repeat every month*), **Send for approval** (review) or **Block at checkout**. Apply limits to **selected stores** (province / search / select all). Per-store overrides on **Edit store**. |
| **Automation** | In-policy → **auto-approve** + **auto-submit** to cheapest vendor (Amazon / G&T). Out-of-policy → queue until Approve anyway / Reject. |
| **Store cart** | One-line limit banner. **Proceed to checkout** opens a confirm pop-up only when the cart needs approval. |
| **Checkout** | Editable billing (prefilled from store). Rich **Approval required** card (not a raw Woo notice). Loader / thank-you say **“Sending for approval”** when out of policy — never “sending to supplier”. Usage cards removed from checkout. |
| **Supply Manager** | Dashboard Custom period; **Refresh** on Dashboard only; Ordering policy redesign; Edit store policy cards; Emails + Reports as before. |
| **Access cleanup** | Haroon (`haroon.shahzad@adex360.com`) removed local + live. Aman already off local shop_managers. |
| **Not yet** | Windows per *store group* (B3 groups). Alyshia exception form (B5). Live $125/1 apply. Commit/push for Salman QA. |

---

## Suggested walkthrough (~12–15 min)

### 1. Dashboard (2 min) — `/manager`

1. Greeting + period: **7 days / 30 days / This month / 6 months / Custom** → Apply.
2. KPIs + **Amazon vs Grand & Toy** + coverage / recent / needs attention.
3. Click topbar **Refresh** — numbers reload in place (button is **Dashboard only**).

### 2. Ordering policy (2–3 min) — `/manager/stores` → **Ordering policy**

1. Card **Monthly limits** — budget + orders/month (pre-filled).
2. Card **Ordering window** — from / to dates + **Repeat every month** (live summary: inside/outside today).
3. Card **If an order breaks a rule** — **Send for approval (Recommended)** vs **Block at checkout**.
4. Card **Update stores (Optional)** — province filter, search, tick stores, **Save and update N stores**.
5. Optional: open **Test store → Edit** — show this store can override limits / window / mode, or follow defaults.

### 3. In-policy order → auto-approve (3 min) — `storeowner`

**Best Amazon demo SKU:** [Epson T6716 Maintenance Box](https://ccsupplies.local/product/epson-t6716-maintenance-box/) — **CA$14.99**, cheapest vendor **Amazon** (ASIN `B07NY5PT6G`).  
*(Thermal roll is cheapest on **woo**, so it won’t show the Amazon path.)*

1. Confirm Test store is **0 / 1** this month (or reject prior demo orders).
2. Qty **1** → cart: green/ok one-line banner (budget left · orders used).
3. **Proceed to checkout** — **no** confirm pop-up (in policy).
4. Checkout: editable address prefilled; **no** amber approval card.
5. Place order → loader **“Placing your order”** / supplier steps (or awaiting if auto-submit filter off).
6. Manager: green **Within store policy** / Approved (+ Auto vendor submit if enabled).

### 4. Out-of-policy = exception path (3 min)

1. Same store, second order (or over $120).
2. Cart banner: *reached monthly order limit… next order needs approval*.
3. **Proceed to checkout** → confirm pop-up: reasons + *Confirm to continue*.
4. Checkout: **Approval required** card + steps (place → Supply Ops → supplier).
5. Place order → loader **“Sending your order for approval”** (not supplier).
6. Manager: amber **Needs Supply Ops approval** → **Approve anyway** / Reject.

### 5. Block mode (30–60 sec, optional)

Ordering policy → **Block at checkout** → try a second order → checkout error list, order **not** created. Switch back to **Send for approval** for the rest of the demo.

### 6. Emails + Reports (2–3 min)

1. `/manager/emails` — recipients (Haroon gone), templates `[TEST]`, sent log.
2. `/manager/reports` — Amazon vs G&T / Exceptions / Export Excel.

### 7. Close

- Still open: prod apply $125/1, commit/push + Salman QA, timezone, G&T IP, B5 form.
- Thursday UAT target — see post-demo plan.

---

## Quick URLs

| Screen | URL |
|--------|-----|
| Dashboard | `/manager` |
| Reports | `/manager/reports` |
| Orders | `/manager/orders` |
| Stores / policy | `/manager/stores` |
| Edit store | `/manager/stores/edit/{id}` |
| Emails | `/manager/emails` |
| Vendors | `/manager/vendorslist` |
| Amazon demo product | `/product/epson-t6716-maintenance-box/` |

## If something fails

| Symptom | Fix |
|---------|-----|
| No email | Search `[TEST]` / `[LOCAL →`; Barracuda quarantine |
| Didn’t auto-approve | Already ordered this month, over budget, outside window, or auto-submit off |
| Confirm pop-up never shows | Policy must be **review** mode; cart must be out of policy |
| Real Amazon risk | `cc_auto_vendor_submit_enabled` → false |
| Stale CSS/JS | Hard refresh; manager assets **1.4.5**, cart CSS uses `filemtime` |

## Automated proof (optional)

```bash
php tests/policy-test.php          # includes date-window / apply-some coverage
php tests/amazon-reliability-test.php
php tests/email-test.php
php tests/reports-test.php
```
