# Demo script — Supply Manager (4 November 2026)

**Branch:** `feature/auto-vendor-submit-manager-ux-2026-11-02`  
**Full reference:** [IMPLEMENTATION_GUIDE_2026-11-02.md](IMPLEMENTATION_GUIDE_2026-11-02.md) — setup, QA, policy, emails, reports, and **how to link any user to a store**  
**Local URL:** https://ccsupplies.local/manager  
**Login:** `Supply_Manager` / `localadmin` (Supply Ops) · `storeowner.gt` or **`uzahid`** (store — both temporarily on **Test store** 8177 for this local demo)

> Before any live click-through: confirm local mail redirect is installed, and preferably disable auto-submit (`cc_auto_vendor_submit_enabled` → false) so Amazon is not hit during the call. Details in the implementation guide §3.4. User↔store linking steps are in the same guide under **How a user is connected to a store**.

---

## What we shipped (say this once, ~60 seconds)

| Theme | What’s live on this branch |
|-------|----------------------------|
| **Policy** | Every store **CA$125 / 1 order per month** (applied locally to 223 stores). Global ordering window. Out-of-policy orders go to Supply Ops, not blocked at checkout (review mode). |
| **Automation** | In-policy orders **auto-approve** and **auto-submit** to the winning vendor (Amazon or G&T). Out-of-policy stay in **Awaiting approval** until Approve anyway / Reject. |
| **Storefront** | Stores see **one price**, **no vendor name**. Checkout shows an approval notice when over policy. |
| **Supply Manager** | Renamed portal, redesigned screens, order drawer with policy reasons, Amazon reliability safeguards. |
| **Emails** | `/manager/emails` — who gets which notification, manual send, branded templates, `[TEST]` sends, sent log. Local QA: all 9 tests delivered. |
| **Reports** | `/manager/reports` — 7 reports, presets, Excel / CSV / Print-PDF. Dashboard has Amazon vs G&T + coverage. |
| **Not yet** | Per-store-group windows (B3), Alyshia exception form (B5). Dedicated supply mailbox = add in Emails (D2). |

---

## Suggested walkthrough (~12–15 min)

### 1. Dashboard (2 min) — `/manager`

1. Show greeting + period control: **7 days / 30 days / This month / 6 months / Custom** (pick Custom → from/to → **Apply**).
2. KPIs: Orders placed, Awaiting approval, Approved spend, Rejected.
3. **This month: Amazon vs Grand & Toy** split card → **Vendor report →**.
4. Card stack: chart + store budgets → Ordering this month + Recent orders → Live activity + Needs attention.
5. **Reports** / **Export Excel** buttons.

### 2. Ordering policy + stores (2 min) — `/manager/stores`

1. **Ordering policy**: budget 125, orders/month **1**, window (e.g. 1–31), “Place them and send to Supply Ops…”.
2. Mention **Apply to all stores** already run locally (223 × $125 / 1).
3. Open **Test store** → Edit: show per-store budget/quota still editable.

### 3. Store places an order (3 min) — `storeowner.gt` or `uzahid`

1. Log in as **`storeowner.gt`** or **`uzahid`** (both linked to **Test store** for this local demo — see implementation guide).
2. Shop → Thermal roll ($57) → cart → checkout.
3. Point out: **no Vendor column**; first order → no amber notice.
4. Place order → loader → thank-you.
5. Switch to Supply Manager → order shows **Within store policy** / auto-approved (or awaiting if auto-submit disabled for the demo). Share of Test store’s **1 order / month** counts for either login.

### 4. Second order = exception (3 min)

1. Same store places another order same month → checkout notice: *already ordered this month*.
2. Manager: Dashboard badge / Needs attention / Orders drawer → amber **Needs Supply Ops approval** + reasons.
3. **Approve** → “Approve over the store’s limit?” → **Approve anyway** → blue **Approved as an exception**.
4. Optional: Reject path on a third order.

### 5. Emails (2 min) — `/manager/emails`

1. **Recipients**: `uzahid@…` (or Chris) with event ticks; Active/Paused; Save.
2. **Templates** → Preview one → **Send test** / mention **Send all tests** (9 × `[TEST]`).
3. **Sent log** → View one HTML body.
4. Tab styling: active red tint, hover black.

### 6. Reports (2 min) — `/manager/reports`

1. Preset: **Amazon vs G&T this month**.
2. Switch **Exceptions** / **Stores ordered / not ordered**.
3. **Export Excel** → open Summary + report sheet (or say “same filters as on screen”).

### 7. Close with open items (30 sec)

- **B3** Per-group ordering windows — not built (one global window only).
- **B5** Alyshia exception form — still Supply Ops queue.
- **Prod:** re-apply $125/1, clean Emails list (remove Aman before first Emails load), timezone decision (UTC vs Edmonton), G&T IP allowlist.

---

## Quick URLs

| Screen | URL |
|--------|-----|
| Dashboard | `/manager` |
| Reports | `/manager/reports` |
| Orders | `/manager/orders` |
| Stores / policy | `/manager/stores` |
| Emails | `/manager/emails` |
| Vendors | `/manager/vendorslist` |
| WP policy settings | `/wp-admin/admin.php?page=wcss-settings` |

## If something fails on the call

| Symptom | Likely fix |
|---------|------------|
| No email in Outlook | Search `[TEST]` or `[LOCAL →` with **Enter**; check Other / Junk; Basecamp uses Barracuda quarantine |
| Order didn’t auto-approve | Store already ordered this month, over $125, outside window, or auto-submit filter off |
| Amazon error / real purchase risk | Confirm `cc_auto_vendor_submit_enabled` is false for the demo |
| Stale CSS | Hard refresh; manager CSS version is **1.4.4** |

## Automated proof (optional, off-call)

```bash
php tests/policy-test.php          # 22
php tests/amazon-reliability-test.php  # 15
php tests/email-test.php          # 47
php tests/reports-test.php        # 41
```
