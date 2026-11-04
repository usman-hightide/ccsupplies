# Plan after internal demo (3 Nov 2026)

**Source:** `docs/Internal Demo.pdf` (Usman, Bhaumik, Basit, Moiz, 23 min)  
**Meeting notes:** [`Knowledge/client-shared/meetings/MEETING_2026-11-03_INTERNAL_DEMO.md`](../Knowledge/client-shared/meetings/MEETING_2026-11-03_INTERNAL_DEMO.md)  
**Walkthrough:** [`Knowledge/DEMO_SCRIPT_2026-11-04.md`](../Knowledge/DEMO_SCRIPT_2026-11-04.md) · **QA guide:** [`Knowledge/IMPLEMENTATION_GUIDE_2026-11-02.md`](../Knowledge/IMPLEMENTATION_GUIDE_2026-11-02.md)  
**Deadline:** **Thursday 5 Nov** — product goes to UAT (Bhaumik informs Fiaz), then production.  
**Feedback on the call:** dashboard, reports, Excel and PDF export approved ("Beautiful, this is great").

---

## 1. My tasks (Usman) — do in this order

| # | Task | Where | Done when |
|---|------|-------|-----------|
| 1 ✅ | **Over-budget / over-limit pop-up before checkout.** On **Proceed to checkout**, if the cart is over budget, over the order limit or outside the window, show a pop-up: *"Your order is over budget, so it will go for Supply Ops approval. Once approved, it will be placed with the supplier. Please confirm."* **Confirm** → go to checkout. **Cancel** → stay on the cart. Don't block the order. | Cart page (theme `cc-cart-ui.js`) | Pop-up shows only for out-of-policy carts; in-policy carts go straight to checkout |
| 2 ✅ | **Simple limit banner.** Replace the "Orders used this month 1 of 1 / budget" box with one short line, e.g. *"You have reached your monthly limit — your next order needs approval."* | Cart + checkout (`class-quota-usage-ui.php`) | One clear line, no numbers table |
| 3 ✅ | **Place-order loader wording.** For orders that need approval, the steps must say *"Sending order for approval"* — not "Sending to supplier / confirming with supplier". For in-policy orders keep *"Order approved — sending to supplier"*. | Checkout loader (`cc-cart-ui.js`) + thank-you page | Out-of-policy order never mentions the supplier |
| 4 ✅ | **Ordering policy pop-up layout.** Fields pre-filled from the current settings; **Apply to all stores** moves to the **bottom** of the same pop-up (one place, not two buttons). Keep *"Place them and send to Supply Ops for approval"* as the default. | `/manager/stores` → Ordering policy | One pop-up; apply-to-all at the bottom |
| 5 ✅ | **Remove Haroon (Adex)** — `haroon.shahzad@adex360.com` out of **Emails → Recipients** and remove his `shop_manager` access. Aman is already removed on local; remove him on production too. | `/manager/emails`, WP Users | Neither gets any email |
| 6 ✅ | **Test the notification flow end to end:** store, Supply Manager and vendor emails for new → needs approval → approved / rejected. Use local mail redirect. | `/manager/emails` + place real test orders | Each email arrives once, right person, right template — **done** (Office 365 + `[TEST]` templates + order flow already verified) |
| 7 | **Apply meeting values to live data:** $125 + 1 order on all stores, default orders per month = 1, window 1–31 for now (example given: 7–14 — Ops can change later). | `/manager/stores` | Stores page shows $125 / 1 for every store |
| 8 | **Commit + push the branch**, write a short QA note for Salman (what changed, URLs, test steps — use `Knowledge/IMPLEMENTATION_GUIDE_2026-11-02.md` §6). | Git | Branch pushed, Salman has the note |

---

> **1–6 done on local (4 Nov).** Task 4 also adds: window by **dates** (from / to, optional *Repeat every month*), apply limits to **selected stores** (province filter, search, select all), clearer *outside the policy* wording, deferred notifications so save feels instant, and **per-store** window/mode overrides on Edit store. Task 5 done by Usman (Haroon removed local + live). Task 6 (emails) already verified.
>
> **Dropped — not a real task:** “Fix store province data (Brampton)” was a false alarm from the call. They were testing Reports filters and momentarily didn’t place Brampton; the store data is fine. Do not treat it as open work.
>
> **Also done with 1–5:** editable checkout billing (prefilled); usage cards off checkout; Approval required card (no dual Woo icons); Dashboard-only Refresh; docs/demo script updated 4 Nov.
>
> **1–3 detail:** Thank-you + loader wording updated. Confirm pop-up / Approval card only apply in **review** mode. Prefer review for demo/UAT; block was used for local verification.

## 2. Other people

| Who | Task |
|-----|------|
| **Salman (QA)** | Full test of everything before deploy. Nothing goes live without his sign-off. |
| **Moiz** | Approves after Salman; takes over vendor / Amazon calls from now on. |
| **Basit + Moiz** | Join tomorrow's Amazon call (~3 AM), collect any pointers, then **close the weekly Amazon calls**. Moiz requests a call only if needed. |
| **Bhaumik** | Tell Fiaz the product is ready for UAT; inform Chris before production. |
| **Ops (Chris / Alyshia)** | Map vendors to products before go-live (today ~70% of spend has no vendor). |

---

## 3. Release flow (agreed on the call)

1. Usman finishes §1 → pushes branch.
2. **Salman** tests (QA).
3. **Moiz** approves.
4. Inform **Chris** → deploy to **production**.
5. UAT with the client.

---

## 4. Not in this sprint (after launch)

- Ordering windows per **store group** (global + per-store windows exist; groups do not).
- Exception form / email to Alyshia.
- Vendors getting up to 3 emails per order → decide if they only get the PO after approval.
- Site timezone is UTC → set to Edmonton (needs OK).

---

## 5. Plan — three access levels (technical UI = admin only)

### Who

| Role | Who | Access |
|------|-----|--------|
| **1. Store owner** | Store employee (`store_employee`) | Same as today: shop / cart / checkout only. **No** `/manager` portal. |
| **2. Supply Manager** | Ops (`shop_manager` + portal) | Full Supply Manager dashboard: orders, approve/reject, stores, policy, reports, emails, vendors (ops fields). **No** technical / debug UI. |
| **3. Administrator** | Dev team (`administrator`) | Everything Supply Manager has **plus** technical UI. Can also use WP admin / store-owner paths as needed. |

### Hide from Supply Manager (show only for Administrator)

| # | What | Where |
|---|------|--------|
| A | **Needs your attention** | Dashboard `/manager` |
| B | **View API response** (raw vendor API) | Vendors `/manager/vendorslist` |
| C | **Amazon / vendor API response** panel | Order detail `/manager/orders/view/{id}` (+ Amazon view if same) |

### How

1. Flag on manager pages: `isAdmin` = `current_user_can('manage_options')`.
2. Render A/B/C only when `isAdmin`.
3. Gate raw API REST the same way (Supply Manager cannot hit the payload by URL).
4. No new WordPress role — use the three we already have.

### Done when

- Store owner → unchanged (no portal).
- Supply Manager → portal works; A/B/C hidden.
- Administrator → portal + A/B/C visible.

**Status:** ✅ Implemented (4 Nov) — `WCSSM.is_admin` + UI/REST gates. Verify as Supply Manager vs administrator.
