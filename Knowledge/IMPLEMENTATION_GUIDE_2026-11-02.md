# CC Supplies: what was built, how to set it up, how to test it

**Last updated:** 4 November 2026, evening (Supply Manager UI clean-up — see §8)
**Branch:** `feature/auto-vendor-submit-manager-ux-2026-11-02` (on GitHub; local has uncommitted UX from 3–4 Nov)
**Demo runbook (call script):** [DEMO_SCRIPT_2026-11-04.md](DEMO_SCRIPT_2026-11-04.md)
**Requirements source:** [MEETING_2026-10-29_INTERNAL_DEMO.md](client-shared/meetings/MEETING_2026-10-29_INTERNAL_DEMO.md), [MEETING_2026-11-03_INTERNAL_DEMO.md](client-shared/meetings/MEETING_2026-11-03_INTERNAL_DEMO.md), and [PLAN_Aman_Requirements_Post_Demo_2026-10-29.md](plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md). Task IDs (A1, B2, C1…) come from the Aman plan; tasks 1–5 in [PLAN_After_Internal_Demo_2026-11-03.md](../docs/PLAN_After_Internal_Demo_2026-11-03.md) are the cart/policy polish from the 3 Nov internal call.
**Stakeholder / UAT path:** Thursday 5 Nov UAT target (see post-demo plan). Use the [demo script](DEMO_SCRIPT_2026-11-04.md) for walkthroughs; this guide is the full setup + QA reference.

> ⚠️ **Read before testing on local.** Your local site sends **real email** through Office 365 (WP Mail SMTP), and it holds **real Amazon Business credentials** in `wp-config.php`. An in-policy order with Amazon items can place a **real Amazon order** and email real people. Do [Setup step 4](#4-make-local-testing-safe-do-this-first) before you place any order.

---

## 1. Summary

| ID | Requirement | Status |
|----|-------------|--------|
| E8 | Supply Manager portal redesign, all 8 screens, live data | ✅ Done |
| A4 | Hide the vendor on store checkout | ✅ Done |
| — | Storefront product page, add-to-cart toast, checkout responsive fixes, place-order loader | ✅ Done |
| — | Vendor cards: last price fetch, editable monthly budget, API response pop-up, all fields editable | ✅ Done |
| B1 | Every store CA$125/month, editable per store | ✅ Done — **Apply to all stores** run on local (223 stores @ CA$125) |
| B2 | One order per store per month | ✅ Done — all stores quota **1**; site default orders/month set to **1** |
| B3 | Configurable ordering window | 🟡 **Global** window by **dates** (from–to + optional *Repeat every month*) in Ordering policy; **per-store** window / mode overrides on Edit store. Windows **per store group** still not built. |
| C1 / C5 | Auto-approve only in-policy orders; others wait for Supply Ops | ✅ Done |
| C2 | Auto-submit to the winning vendor | ✅ Done (shipped in commit `63a5e52`, now policy-gated) |
| — | Amazon place reliability | ✅ Done |
| — | Bug: Approve crashed on Valiant orders | ✅ Fixed |
| D3 | Stop emails to Aman | ✅ Done (Aman is no longer a shop_manager; local mail goes to the developer only) |
| — | Remove Haroon (`haroon.shahzad@adex360.com`) local + live | ✅ Done (3–4 Nov) — out of Emails recipients and WP users |
| E1 | Rename the portal to **Supply Manager** (sidebar, tab titles, page labels) | ✅ Done |
| — | **Email Management** tab: per-address notification choices, manual send, branded templates, sent log | ✅ Done (§5.3) |
| D2 | Copy vendor submissions to a supply mailbox | 🟡 Configurable: add the mailbox in **Emails** and tick *Vendor approved order* + *Vendor rejected order* |
| E3–E7 | **Reports**: 7 filterable reports (Amazon vs G&T, store, product, user, exceptions, stores ordered / not ordered, order detail), saved presets, branded Excel / CSV / Print-PDF export | ✅ Done (§5.4) |
| E2 | Dashboard clarity: Amazon vs G&T this month under the KPIs, "Ordering this month" coverage card, Reports + Export Excel replace the old CSV/PDF snapshot | ✅ Done |
| — | Dashboard **Custom** date period (from / to + Apply), equal-height toolbar; card order: KPIs → vendor split → chart + budgets → coverage + recent orders → activity + attention | ✅ Done |
| — | Topbar **Refresh** only on Dashboard (hidden on other `/manager` screens) | ✅ Done |
| — | Emails tab hover (black) / active (red tint) styling | ✅ Done |
| — | **Post–3 Nov internal demo (tasks 1–5):** cart confirm pop-up; one-line limit banner; approval loader / thank-you wording; Ordering policy redesign (dates, selected stores); checkout editable billing + Approval required card | ✅ Done — see §4 Storefront + §5.1 |
| B5 | Exception form to Alyshia | ❌ Pending (§7) |

Local QA applied store limits via Ordering policy → **Update selected stores** / earlier Apply-to-all (125 / 1). Branch is on GitHub; **cart/policy polish from 3–4 Nov is still uncommitted** until task 9. Production deploy is separate.

**Local email QA (3 Nov evening):** WP Mail SMTP → `smtp.office365.com` accepted mail (`250 OK`). All **9** `[TEST]` templates delivered to `uzahid@basecampconsulting.co` (search Outlook for `[TEST]` or `[LOCAL →`). Basecamp MX goes through Barracuda — if mail is missing, press Enter on search and check Barracuda quarantine, not only Focused inbox.

---

## 2. Prerequisites

| Item | Value |
|------|-------|
| Local app | Local (by Flywheel), site **ccsupplies**, started |
| Site URL | https://ccsupplies.local |
| Supply Manager portal | https://ccsupplies.local/manager |
| WP admin | https://ccsupplies.local/wp-admin |
| Policy settings (admin) | https://ccsupplies.local/wp-admin/admin.php?page=wcss-settings |
| PHP CLI | Needed for the automated tests. Run them from Local's **Open site shell** so PHP can reach the site's MySQL. |
| Database | Local DB, or a dump per [db-backups/README.md](db-backups/README.md) |

### Test accounts

Passwords are **not** stored in this repo. Use the team password vault, or set one locally from Local → **Open site shell**:

```bash
wp user update storeowner.gt --user_pass='choose-a-local-password'
```

| Use for | Login | Role | Notes |
|---------|-------|------|-------|
| Supply Manager portal | `Supply_Manager` (store@manager.com) | shop_manager | Any administrator also works (e.g. `localadmin`) |
| Ordering as a store | `storeowner` / `StoreOwner1!` | store_employee | **Local demo account** linked to **Test store** (ID 8177). Also: `storeowner.gt` |
| Ordering as a store (local demo) | `uzahid` (uzahid@basecampconsulting.co) | administrator + **store_employee** | **Temporary** local: linked to **Test store** (ID 8177) and given `store_employee` so he appears under `/manager/users`. Do not copy this dual role to production. |
| WP admin settings | `localadmin` | administrator | |

**Test data:** store **Test store** (ID 8177; quota **1**, budget **CA$125**). Product **Thermal roll** (ID 8176, $57 — cheapest on **woo**, not Amazon). **Amazon demo product:** [Epson T6716 Maintenance Box](https://ccsupplies.local/product/epson-t6716-maintenance-box/) (ID **7582**, CA$14.99, ASIN `B07NY5PT6G`). Alternate Amazon ASIN product ID 7654.

### How a user is connected to a store

Every order needs a store. At checkout the site reads the logged-in user’s meta `_wcss_store_id` and copies it onto the order as `_wcss_store_id`. If that meta is empty, policy shows **account not linked to a store** and the order stays awaiting approval (`no_store`).

**Where to link (preferred — Supply Manager UI)**

1. Open **https://ccsupplies.local/manager/users** (or `/manager/users` on the live host).
2. Find the person → **Edit**. This list only shows users with the **`store_employee`** role (not administrators / shop managers). If someone is missing, add that role in **WP admin → Users**, or use **+ Add store employee**.
3. Set **Assigned store** to the store (e.g. **Test store**) → **Save changes**.
4. Confirm the Users table shows that store name (not “Not assigned”).

**Alternate — from the store side**

1. Open **`/manager/stores`** → **Edit** on the store.
2. Choose the **Store Employee** / assigned user → save.
3. Note: saving a store’s primary employee can **unassign other users** previously tied to that same store (one primary assignee in the store editor). For a temporary extra demo login (e.g. `uzahid` alongside `storeowner.gt`), prefer **Users → Edit** so you do not knock `storeowner.gt` off Test store.

**What gets stored**

| Place | Key | Example |
|-------|-----|---------|
| User meta | `_wcss_store_id` | `8177` (Test store) |
| Order meta (set at checkout) | `_wcss_store_id` | same ID |

**Local demo assignment (3–4 Nov 2026):** `uzahid` (user ID **240**) → Test store **8177**. Same store already has `storeowner.gt` (243) and `alyshia` (238). Remove Usman’s link after the demo if you no longer need it: Users → Edit → Assigned store → **Choose a store** (empty) → Save, or delete user meta `_wcss_store_id` for user 240.

**CLI (optional, Local site shell):**

```bash
wp user meta update 240 _wcss_store_id 8177
wp user meta get 240 _wcss_store_id
```

---

## 3. Setup and configuration

### 1. Get the code

```bash
git fetch origin
git checkout feature/auto-vendor-submit-manager-ux-2026-11-02
```

If your Local site lives elsewhere, sync the plugin and child theme as described in the root [README.md](../README.md).

### 2. Flush permalinks (once)

The portal adds `/manager/amazon` and `/manager/gt`. Go to **WP admin → Settings → Permalinks** and click **Save Changes**. This rebuilds the rewrite rules.

### 3. Check the plugin is active

**WP admin → Plugins → WCSS Internal Supplies** must be active. The new class `includes/class-order-policy.php` loads automatically.

### 4. Make local testing safe (do this first)

- **Email:** the local-only mu-plugin `wp-content/mu-plugins/cc-local-mail-redirect.php` sends **every** email to the developer (default `uzahid@basecampconsulting.co`; override with `define( 'CC_LOCAL_MAIL_TO', 'you@example.com' );` in `wp-config.php`). The subject shows the original recipients, e.g. `[LOCAL → asood@…] …`. Cc/Bcc are dropped. It also sets PHPMailer’s CA bundle (Local PHP often has empty `openssl.cafile`, which breaks Office 365 TLS) and a proper `Hostname` (`ccsupplies.ca`) so Message-IDs are not `*.local` (those were delayed/filtered). The folder is git-ignored — copy the file onto each developer machine. It does nothing on sites not ending in `.local`. Note: the WCSS email log still records the *intended* recipients before redirect.
- **Real vendor orders:** to stop auto-submit from contacting Amazon or Grand & Toy during click-through testing, add this line to the child theme's `functions.php` on your machine only, and don't commit it:
  ```php
  add_filter( 'cc_auto_vendor_submit_enabled', '__return_false' );
  ```
  The automated tests in §6 don't need this. They mock every vendor call.

### 5. Set the ordering policy (B1 / B2 / B3)

Either screen works; both save the same settings.

- **Portal:** `/manager/stores` → **Ordering policy** button (preferred — redesigned 3–4 Nov)
- **WP admin:** **WooCommerce → Internal Supplies** (`?page=wcss-settings`)

| Field (portal) | Meeting value | Meaning |
|----------------|---------------|---------|
| Monthly budget (CA$) | **125** | Site default when a store has no budget of its own |
| Orders per month | **1** | Site default when a store has no quota of its own |
| Ordering window (from / to) | e.g. **7–14** or open all month | Calendar dates. **Repeat every month** = same days each month (day-of-month from the two dates). Without repeat = one-off range only. Clearing dates = open all month (days 1–31). Legacy day-of-month fields still sync under the hood. |
| If an order breaks a rule | **Send for approval (Recommended)** | Alternative: **Block at checkout** (hard stop — no order created) |

Live summary in the pop-up shows whether **today** is inside or outside the window.

**Per store (Edit store):** optional overrides for budget/quota, window dates + repeat, and review/block mode. Empty = follow the Ordering policy defaults.

### 6. Apply $125 / 1 to stores (B1 / B2)

In **Ordering policy**, card **Update stores (Optional)**: filter by province, search, select stores (or select all visible) → budget **125**, orders **1** → **Save and update N stores**.  
(Older UI had a separate **Apply to all stores** button; that flow is now this card at the bottom of the same pop-up.)

**Local status (3 Nov 2026):** already applied — all **223** published stores are CA$125 / 1 order. Re-run only if you restore an old DB dump. Per-store edits remain possible via each store's **Edit** button.

---

## 4. Where everything is

### Supply Manager portal (`/manager`)

| Screen | URL | What's there |
|--------|-----|--------------|
| Dashboard | `/manager` | Period seg (**7 days / 30 days / This month / 6 months / Custom** with from–to + **Apply**), KPIs, **This month: Amazon vs Grand & Toy**, orders chart, store budgets, **Ordering this month**, recent orders, live activity, needs attention. Buttons: **Reports**, **Export Excel** |
| Reports | `/manager/reports` | Report tabs, filters, presets, KPIs, Amazon vs G&T split, chart, sortable table with totals; **Export Excel**, **CSV**, **Print / PDF**, **Save as preset** |
| Orders | `/manager/orders` | Filter by status (`?status=awaiting-approval`), search, CSV export. Click a row to open the **order drawer**. |
| Order drawer | click any order | Policy box (green **Within store policy**, amber **Needs Supply Ops approval** with reasons, blue **Approved as an exception**), history, Approve / Reject, **Open Full Order Detail Page** |
| Full order page | `/manager/orders/view/{id}` | Line items, store, the **Auto vendor submit** panel (status, vendor ref, attempts) |
| Products | `/manager/products` | Catalog list, create and edit |
| Vendors | `/manager/vendorslist` | Cards showing **Prices fetched** time, black **monthly budget** box with **Set budget**, **View API response** pop-up with **Fetch now**, **Edit** (all fields) |
| Stores | `/manager/stores` | Quota, budget, usage bar, assigned user; **Ordering policy** (limits + window + mode + update selected stores), Export |
| Edit store | `/manager/stores/edit/{id}` | Address, employee, and **this store’s** policy cards (limits / window / mode, or use defaults) |
| Users | `/manager/users` | Store employees only; edit name, email and store; remove |
| Emails | `/manager/emails` | **Recipients** (who gets which notification, Save changes), **Send email** (manual), **Templates** (preview + send test), **Sent log**. Tabs: active = red tint, hover = black |
| Amazon catalog | `/manager/amazon` | Amazon items and prices |
| Grand & Toy | `/manager/gt` | G&T items and prices |

Topbar: global search, live clock, **Refresh** (**Dashboard only** — not shown on other manager screens). The sidebar shows a red badge with the pending-approval count.

### Storefront (logged in as a store)

- **Product page:** professional qty stepper, stock text ("In stock" or "Only N left"), add-to-cart toast with **View cart**.
- **Cart:** one-line limit banner (no usage table / cards). **Proceed to checkout** on an out-of-policy cart opens a **confirm pop-up** (reasons + Confirm / Cancel). In-policy carts go straight to checkout. Hidden `.cc-policy-flag` on the cart drives this.
- **Checkout:** no Vendor column. Billing fields **editable** (prefilled from the store — not a locked “Store Summary”). Out-of-policy in **review** mode: rich **Approval required** card (not a raw WooCommerce error strip). Usage cards are **not** shown on checkout. **Block** mode: checkout errors, order not created.
- **Place order loader / thank-you:** out-of-policy → title and steps say **Sending your order for approval** / notifying Supply Ops — never “sending to supplier”. In-policy → **Placing your order** / supplier steps. After ~12s a “still working” message can appear.

### Code map

| Area | File |
|------|------|
| Policy engine (date window + repeat, quota, budget, cart `.cc-policy-flag`, thank-you alert, REST `/wcss/v1/policy`, `/stores/apply-limits`, `/stores/{id}/policy`) | `plugins/wcss-internal-supplies/includes/class-order-policy.php` |
| Approve check (409 with reasons, override) | `…/includes/rest/class-rest-orders.php` |
| Checkout block (block mode only) | `…/includes/class-order-quota.php` |
| One-line cart/checkout limit banner | `…/includes/class-quota-usage-ui.php` |
| Checkout billing prefill (editable) | `…/includes/class-checkout-store-lock.php` |
| Admin settings fields | `…/includes/class-admin-settings.php` |
| Vendor email (fatal fix) | `…/includes/class-notifications.php` |
| Portal screens JS / CSS (Ordering policy modal, Edit store policy) | `…/frontend/assets/js/ccm-screens.js`, `…/frontend/assets/css/manager.css` |
| Dashboard-only Refresh + manager chrome | `…/frontend/assets/js/manager.js`, `…/frontend/pages/partials/manager-nav.php` (assets **1.4.5**) |
| Edit store page | `…/frontend/pages/stores-edit.php` |
| Auto-submit, policy gate, Amazon reliability | `themes/adex360-canna-cabana-stationery-child/cc-auto-vendor-submit.php` |
| Product page UI and toast | child theme `cc-product-ui.php/.css/.js` |
| Cart confirm pop-up, checkout Approval card, loader / thank-you wording | child theme `cc-cart-ui.php/.css/.js`, `woocommerce/checkout/review-order.php` |
| Email recipients, events, triggers, deferred send on shutdown, REST `/wcss/v1/emails/*` | `…/includes/class-email-center.php` |
| **Email template** (header, colours, layout of every email) | `…/includes/class-email-template.php` |
| Store employee + vendor emails (not configurable per address) | `…/includes/class-notifications.php` |
| Emails page | `…/frontend/pages/emails.php` + `screens.emails` in `ccm-screens.js` |
| Report engine, presets, REST `/wcss/v1/reports/*`, exports (`/manager/reports/export/`) | `…/includes/class-reports.php` |
| Excel writer (styles, sheets) | `…/includes/class-xlsx.php` |
| Reports page / dashboard cards | `…/frontend/pages/reports.php`, `screens.reports` + `screens.dashboard` + `vendorSplit()` in `ccm-screens.js` |
| Local-only mail redirect | `wp-content/mu-plugins/cc-local-mail-redirect.php` (git-ignored) |

---

## 5. How the new rules behave

### 5.1 Ordering policy (B1, B2, B3, C1, C5)

An order is **in policy** when all of these are true:

1. Today is inside the ordering window (site dates, or this store’s override if set).
2. It's under the store's orders-per-month limit (usually **1** = first order this month).
3. This month's spend including this order is **≤ the store's budget**.

**Window rules:** dates + *Repeat every month* → same day-of-month range every month (may wrap); dates without repeat → only that calendar range; no dates → open all month (legacy start/end days 1–31).

**Mode:** **review** (default) — out-of-policy orders are placed and wait for Supply Ops. **block** — checkout refuses the order (per store override possible).

```
Cart (review + out of policy) ──► confirm pop-up ──► checkout Approval card
Store places order ──► status "Awaiting approval"
        │
   Policy check
   ├─ in policy ──► auto-approved ──► auto-submitted to the winning vendor (Amazon / G&T)
   └─ not in policy ──► stays "Awaiting approval", shows in Orders + Dashboard "Needs attention"
                          ├─ Manager clicks Approve ──► "Approve over the store's limit?" ──► Approve anyway ──► approved + sent to vendor
                          └─ Manager clicks Reject ──► rejected, never sent
```

Reasons shown to the manager and the store: **outside the ordering window**, **already ordered this month**, **over the monthly budget**, **account not linked to a store**.

Store limits come from the store's own values first, then from the site defaults. Orders with status *Awaiting approval* or *Approved* count toward the month (setting: *Statuses Counted Toward Monthly Limit*).

### 5.2 Amazon place reliability

**Problem:** all stores share one Amazon Business cart. Placing "whatever is in the cart" could ship another order's leftover items, or the wrong quantities. Two orders placed at the same moment could also collide, and a blind retry could create a duplicate Amazon order.

**What it does now:**

| Safeguard | Behaviour |
|-----------|-----------|
| Intended lines only | Only this WooCommerce order's ASINs and quantities are sent. Leftover cart items are ignored and recorded (`ignored_cart_items`). |
| One at a time | A lock (`cc_amazon_place_lock`) allows one Amazon placement at a time. A second order waits and retries in 1 minute ("Waiting for another Amazon order to finish…"). A stale lock expires after 10 minutes. |
| Safe retry | Retries up to 3 times **only** on 429 / 5xx, reusing the same `externalId` (`CC-{order}-{hash}`) so Amazon can de-duplicate. |
| No retry on timeouts | A network timeout isn't retried, because the order may already exist on Amazon. It's flagged for a person to check. |
| Audit | Each attempt (HTTP code, time) is saved on the order and shown in the **Auto vendor submit** panel. |

### 5.3 Email Management (`/manager/emails`)

**Who gets what.** Supply Ops emails go only to the addresses listed in **Emails → Recipients**, and each address only gets the notifications ticked for it. Store employees (their own order updates) and vendors (purchase orders) are emailed automatically and are not configured here.

| Group | Notification | Sent when |
|-------|--------------|-----------|
| Orders | New order placed | A store places an order |
| Orders | Order needs approval | The order is over budget, outside the window or a second order this month (includes the reasons) |
| Orders | Order approved | Auto-approved or approved by Supply Ops (says "as an exception" when overridden) |
| Orders | Order rejected | Supply Ops rejects (includes the reason typed) |
| Orders | Order moved back to pending | An approved/rejected order is reopened |
| Vendors | Vendor approved order | Auto-submit to Amazon / Grand & Toy succeeds |
| Vendors | Vendor rejected order | Auto-submit fails or is only partly accepted (includes the vendor response) |
| Vendors | Vendor details changed | A vendor is added, edited (only real changes), removed, or its budget changes |
| System | Ordering policy changed | Ordering policy saved with a change, or limits applied to selected / all stores |

**First run.** The first time the page (or any notification) loads, the list is pre-filled with every `shop_manager` user and the order + vendor-failure emails, so nobody silently stops getting mail. After that the list is the only source; changing someone's role no longer changes their email.

**Add / edit / remove.** Type one or more addresses (comma-separated) → **+ Add** → the new card opens → tick notifications (each group has **All / None**) → **Save changes**. Saved cards stay collapsed and show a summary (e.g. *Orders 4/5 · Vendors 1/3 · System 0/1*); **Choose notifications** opens one, **Expand all** opens every card, and the search box filters by address or name. The save bar turns amber while there are unsaved changes; leaving the page warns you. **Active / Paused** stops all mail to an address without deleting it. **Edit** changes the address or name; **Remove** deletes it on save. Max 50 addresses.

**Manual email.** **Send email** tab: To (comma-separated, or the quick-add chips), Subject, Message (bold, italic, lists, links; pasted text is cleaned) → **Preview** or **Send email**. Each address gets its own copy in the branded layout. A green box confirms who it went to; a red box shows mail-server errors.

**Templates.** All emails share `includes/class-email-template.php` (black header with the Canna Cabana wordmark and red accent, title, status pill, detail rows, items table, action button, sent date/time, and a footer saying why the person got it). **Templates** tab: **Preview** any notification (built from your latest real order; nothing changes), **Send test** one, or **Send all tests** to one address. Tests are marked `[TEST]`.

**Sent log.** Every email the site tries to send, newest first, searchable by recipient or subject, with **View** to see the exact email. Note: the log records an email when it is handed to the mailer, before the mail server accepts it.

### 5.4 Reports (`/manager/reports`)

**What counts as spend.** Approved orders, item totals before tax and shipping — the same rule as store budgets. Each order line's vendor is its cheapest-vendor tag (Amazon / Grand & Toy), else the product's vendor, else **Unassigned**. Today about 70% of historic spend is *Unassigned* (older products had no vendor); the Amazon vs G&T card warns when that share is above 25%.

| Report | Answers | Default status |
|--------|---------|----------------|
| Spend by vendor | Amazon vs Grand & Toy (and others): orders, stores, units, spend, share, average order; monthly stacked chart | Approved |
| Spend by store | Spend vs budget per store (budget × months in the period), exceptions, last order | Approved |
| Spend by product | Units, orders, spend, average unit price, vendor | Approved |
| Orders by user | Orders and spend per store employee | Approved |
| Exceptions | Orders outside the policy or rejected: reason, outcome (Waiting / Approved as exception / Rejected), who approved | All |
| Stores ordered / not ordered | Every store, whether it ordered in the period, with "only not ordered" filter | All (rejected orders don't count as ordered) |
| Order detail | Every order with store, user, status, vendor(s), units, total, exception | All |

**Filters:** period (this month, last month, this / last quarter, year to date, last 12 months, custom dates), status, vendor, store, province, product, user, exception reason (Exceptions / Order detail), and "Show" (Stores ordered report). The URL updates as you filter, so a report link can be shared. Click any column heading to sort.

**Presets:** six built-in (Amazon vs G&T this month, vendor spend last 12 months, exceptions this month, stores not ordered this month, spend by store last month, top products this quarter). **Save as preset** stores the current report + filters for every manager; relative periods stay relative ("this month" is always the current month). Saved presets show with a × to delete; built-ins can't be deleted.

**Exports** (always the report and filters on screen):
- **Export Excel** — `.xlsx` with a branded **Summary** sheet (period, filters, who/when, key figures) and the report sheet (black header row, frozen header, filter buttons, CA$ and % formats, bold totals row, landscape fit-to-width print, page footer). Vendor report adds a **By month** sheet.
- **CSV** — plain table + totals row, UTF-8 (opens correctly in Excel), formula-injection safe.
- **Print / PDF** — branded print page; the print dialog opens automatically, choose *Save as PDF*.

---

## 6. Testing

### 6.1 Automated tests (recommended, about 30 seconds)

Run from the repo root in Local → **Open site shell**:

```bash
php tests/policy-test.php
php tests/amazon-reliability-test.php
php tests/email-test.php
php tests/reports-test.php
```

Expected output: `RESULT: 42 passed, 0 failed` (policy), `RESULT: 15 passed, 0 failed` (Amazon), `RESULT: 47 passed, 0 failed` (email), `RESULT: 41 passed, 0 failed` (reports).

The scripts are safe on local:
- They **block every email** and print how many were blocked.
- The Amazon test **mocks every HTTP call**. Nothing reaches Amazon.
- They create throwaway orders on Test store (8177), then delete them (and their email-log rows) and restore the store's quota, budget, site settings and email recipients.
- They refuse to run unless the site URL ends in `.local`.
- `tests/` sits outside `app/public`, so it's never deployed.

What they cover:

| Suite | Checks |
|-------|--------|
| `policy-test.php` (42) | $125 fallback · first / second order · review vs block at checkout · **date window** (one-off, repeat monthly, labels) · PUT `/policy` date validation · **apply-limits to selected store_ids** · **per-store** GET/PUT `/stores/{id}/policy` (window, mode, clear overrides) · GET/PUT policy + apply-limits validation |
| `email-test.php` (47) | Seeding from shop managers · save validation (bad / duplicate address, unknown events) · each notification goes only to addresses that ticked it · paused address gets nothing · store email never names the vendor · needs-approval reasons · reopen only from approved/rejected · rejection reason shown, Woo noise removed · vendor accepted / failed / skipped · vendor add, real edit only, budget, remove · policy change and apply-all · template header/date/link · manual send (2 copies, formatting kept, scripts stripped, bad address / subject / message, server failure message) · previews for all 9 · Send all tests · log list + view · store employee blocked from the API |
| `reports-test.php` (41) | Adds known orders and checks the exact change: Amazon +20, G&T +30, rejected 99 excluded · shares = 100% · totals count each order once · vendor / status / product / user / province / reason filters · store budget % · exceptions outcome incl. "Approved as exception" + approver · coverage ordered / not ordered · custom and relative dates · meta + run API · presets save / duplicate / empty / built-in delete refused / delete · CSV (BOM, totals, injection) · Excel (sheets, frozen header, filter, numbers) · print page · store employee blocked |
| `amazon-reliability-test.php` (15) | Only this order's item placed · qty 2 placed, not the cart's stale 9 · 503 retried with the same externalId · attempts recorded · network timeout not retried · busy lock defers with zero Amazon calls · full run submits, approves and releases the lock |

### 6.2 Manual click-through

Do [Setup step 4](#4-make-local-testing-safe-do-this-first) first. Use two browsers (or one private window): **A** = Supply Manager, **B** = `storeowner` (or `storeowner.gt`). Prefer **review** mode for Tests 2–5. For Amazon auto-submit path use **Epson T6716** (not Thermal roll).

**Test 1: Ordering policy settings (B1 / B2 / B3)**
1. A: `/manager/stores` → **Ordering policy**. Set budget 125, orders per month 1, open all month (or from–to + Repeat), **Send for approval**. Optionally select stores → **Save and update N stores**.
2. Expected: toast / success; reopen — values persist. Live window summary matches today.
3. A: Test store → **Edit** → confirm limits; try a store-only window then **Use the default**.

**Test 2: First order is auto-approved (C1)**
1. B: add 1 × Epson T6716 ($14.99) or Thermal roll ($57) → cart. Expected: one-line banner (budget left), **no** confirm pop-up on Proceed.
2. Checkout: editable billing; **no** Approval required card; **no** Vendor column.
3. Place order. Expected: loader **Placing your order** (supplier steps if in policy), then thank-you.
4. A: order drawer green **Within store policy** → Approved + Auto vendor submit (unless filter off).

**Test 3: Second order needs approval (B2 / C5)**
1. B: place another order the same month. Cart banner: monthly limit / needs approval. **Proceed** → **confirm pop-up** → Confirm.
2. Checkout: **Approval required** card with reasons. Place order → loader **Sending your order for approval**.
3. A: Dashboard **Needs attention** + badge; drawer amber **Needs Supply Ops approval**.
4. A: **Approve** → **Approve anyway** → blue **Approved as an exception** + auto-submit. Or **Reject** → never sent.

**Test 4: Over budget**
Set Test store quota to 5, then order 3 × Thermal roll ($171). Expected reason: **over the monthly budget** (confirm pop-up + Approval card).

**Test 5: Outside the window (B3)**
Ordering policy → from/to dates that exclude today (no repeat), or a store-only future window. Place an order. Expected reason: **outside the ordering window**. Reset afterwards.

**Test 6: Block mode**
Ordering policy → **Block at checkout**. A second order is refused at checkout (errors; no order). Switch back to **Send for approval** afterwards.

**Test 7: Vendors**
`/manager/vendors`: each card shows **Prices fetched** time. **Set budget** saves a monthly amount. **View API response** shows the latest fetch and grouped errors; **Fetch now** refreshes it. **Edit** lets you change all fields, and bad email or URL values are rejected.

**Test 8: Storefront UI**
Product page: the − / + stepper is aligned and the add-to-cart toast has no blue border. Cart: one-line banner only. Checkout: billing editable; resize to phone width; no text overlaps; dual Woo error icons must not appear on the Approval card.

**Test 9: Email Management**
1. `/manager/emails` → **Recipients**: add `you@yourdomain.com` with name "QA". Expected: card appears with order + vendor-failure emails ticked and the bar says *You have unsaved changes*.
2. Untick *New order placed*, tick *Ordering policy changed* → **Save changes**. Reload: the choices are still there.
3. Click **Send test** on your card. Expected: one `[TEST]` email per ticked notification arrives.
4. Place a store order (Test 2). Expected: you do **not** get "New order placed" (unticked). Approve it: you get "Order approved".
5. Stores → **Ordering policy** → change the window end day → Save. Expected: "Ordering policy changed" email showing old → new.
6. Toggle your card to **Paused** → Save → repeat step 4. Expected: no emails to you.
7. **Send email** tab: send yourself a formatted message. Expected: green confirmation and the email in the branded layout. Try `bad-email` in To: red error, nothing sent.
8. **Templates** → **Preview** each notification; **Send all tests** to yourself (9 emails).
9. **Sent log**: search your address, click **View** on one.
10. Phone width: everything stacks, no sideways scrolling.

**Test 10: Reports and dashboard**
1. Dashboard: under the KPIs, **This month: Amazon vs Grand & Toy** shows both amounts, shares and a split bar; **Vendor report →** opens Reports with the same numbers. **Ordering this month** shows X of 223 stores and lists stores that haven't ordered.
2. `/manager/reports` → click each preset; the tab, period and filters change and the table reloads.
3. **Spend by vendor**, Period = Last 12 months: totals row = Total spend card; chart shows Amazon (black), G&T (red), other (grey) per month.
4. Vendor = Amazon → only the Amazon row. Store = Test store → numbers shrink to that store. **Reset filters** clears them.
5. **Exceptions**: rejected and held orders with reason + outcome; click an order number to open it.
6. **Stores ordered / not ordered** → Show = Only stores that didn't order.
7. **Save as preset** "QA test" → appears with ×; reload — still there; × → gone.
8. **Export Excel** → open the file: Summary sheet + report sheet with header, filters and totals. **CSV** opens in Excel with accents intact. **Print / PDF** → print dialog → Save as PDF.
9. Copy the page URL into a new tab: the same report and filters load.
10. Phone width: filters stack, report tabs scroll sideways inside the card, table scrolls inside its box.

**Clean-up:** delete your test orders (WooCommerce → Orders). Test store is already at quota 1 / budget 125 after Apply-to-all — only change it if a specific test needs a higher quota.

---

## 7. Pending tasks and known issues

### Done on local (3–4 Nov 2026)

1. **$125 / 1** on all **223** stores + defaults in Ordering policy / `wcss_settings`.
2. Dashboard Custom date period + card reorder; Emails tab hover/active styles — on GitHub (`b349205`).
3. Local email path verified: Office 365 SMTP accepts; 9 `[TEST]` templates received at `uzahid@basecampconsulting.co`.
4. **Temporary:** linked `uzahid` (240) → Test store **8177** + `store_employee` so he appears under Users.
5. **Post–internal demo (tasks 1–6):** cart confirm pop-up; one-line banner; approval loader/thank-you wording; Ordering policy dates + selected stores; Haroon removed local + live; editable checkout billing; Dashboard-only Refresh; deferred email send; per-store policy overrides; notification / email flow verified. *(Code polish uncommitted until task 8.)*

### Still open before UAT / production (see post-demo plan)

| # | Task |
|---|------|
| 7 | Apply $125 / 1 + window on **live** data |
| 8 | Commit + push + short QA note for Salman |

Email notification flow (former task 6) is **done**. Province / Brampton was a false alarm during Reports testing on the call — **not** a task.

### Decisions needed before production deploy

1. **Production:** remove Aman (`asood`) and `amaclean` from `shop_manager` there **before** deploying. On first load, Emails pre-fills its list from shop managers; after deploy, open **Emails** and check the list. Haroon already removed.
2. Re-run limits apply (125 / 1) and set Ordering policy defaults on **production** after deploy — local DB values do not transfer with Git.
3. Prefer **review** mode on production for launch (block was used for local verification only).

### Not built yet

| ID | Item | Where / note |
|----|------|----------------|
| B3 | Ordering windows **per store group** (e.g. stores 1–100 days 1–7) | Global + **per-store** windows exist; group windows do not |
| B5 | Exception form or email to Alyshia (launch version) | Out-of-policy orders still go to Supply Ops queue; no dedicated Alyshia form |
| D2 | Dedicated unmonitored supply mailbox CC on every vendor submit | Add mailbox under **Emails** and tick vendor events |

### Known issues and external items

- **Grand & Toy:** live submit needs G&T to allowlist the server IP (external, waiting on G&T).
- **Shared Amazon Business cart:** still holds leftover items from earlier tests. Placement now ignores them, but someone should clear the cart manually in Amazon Business.
- **Site timezone is UTC** (Settings → General is set to `UTC+0`). Dates in emails, reports and the **ordering window** use it, so evening orders in Alberta count as the next day. Recommend setting it to *Edmonton* (or the stores' main zone) — not changed, needs a decision.
- **Data quality limits reports:** ~70% of past spend has no vendor (products without a vendor; see Dashboard → Needs attention → "Products need fixing") and 12 older orders have no store, so they show as *Unassigned* / *No store*.
- **Vendors get up to three emails per order** (new order, status change, purchase order) — existing behaviour, unchanged. Worth confirming with Ops whether vendors (e.g. Valiant) should only get the purchase order.
- WooCommerce statuses *completed* and *cancelled* aren't used on this site (the status policy turns them into *pending*), so there are no notifications for them.
- The **Approve** crash on orders with Valiant products (missing vendor email function) existed before this work. It's fixed on this branch.

---

## 8. Update — 4 Nov 2026 (afternoon, after 3 PM)

Supply Manager UI clean-up from internal QA. No database changes; no theme changes.

### What changed and where

| Area | Where | What's new |
|------|-------|------------|
| Sidebar | every `/manager` page | Real Canna Cabana logo (from **Appearance → Customize → Site identity**); falls back to text if no logo is set |
| Top bar | every `/manager` page | "Live · updated" indicator removed |
| Pagination | Orders, Products, Stores, Users, Amazon catalog, Grand & Toy, Reports | 20 rows per page, pager **‹ Prev · 1 2 … · Next ›**, footer "Showing 21–40 of 223". Search / filters / tabs go back to page 1; tab counts, search and exports still cover all records |
| Dropdowns | Orders, Products, Stores, Users filters; Reports filters; Ordering policy (Province); Edit store (employee); Edit user | Styled dropdown that always opens **below** the field (native macOS menus covered the field). Search box in long lists, keyboard (↑ ↓ Enter Esc), changed Reports filters shown in red |
| Reports | `/manager/reports` | Presets turn black with ✓ when chosen; red hover; "Reset filters" in red |
| Vendors | `/manager/vendorslist` | Minimal cards: status, Products / Orders / Spend, Email, Last order, Prices (Amazon / G&T only, "Details" link for admins). Budget box and fetch statistics removed (budgets kept in the database, not shown) |
| Amazon catalog | `/manager/amazon` | Same layout as Grand & Toy: cards (products, prices synced, last sync), table with Amazon price + status + Edit, **Sync prices now**. "Amazon products" = assigned to Amazon **or** linked to an Amazon item |
| Amazon / G&T | `/manager/amazon`, `/manager/gt` | "Open … catalog" buttons removed |
| Order drawer | Orders / Dashboard → click an order | "Move back to pending" button removed (approved / rejected orders show **Done** only) |
| Users | `/manager/users` → **Edit** | Pop-up now has **Role** (Store employee / Supply Manager — administrators only) and **Status** (Active / Inactive). Inactive blocks sign-in immediately and signs the user out; history kept. Supply Managers are listed with their role |
| Order detail | `/manager/orders/view/{id}` | Rebuilt: breadcrumbs (Dashboard › Orders › Order #), header with status + summary, one action box (Approve / Reject, or "Place with Amazon / Grand & Toy" when the auto-submit failed), items **with product images**, totals, plain-English history, store + ship-to, monthly usage bars. **Technical details / API response / JSON: administrators only** |
| Orders API | `GET /wcss/v1/orders` | `total` / `total_pages` now correct (were 0) |

### How to test (5 minutes)

1. **Pagination:** `/manager/stores` → footer "Showing 1–20 of 223" → click **2** → "Showing 21–40". Type in Search → back to page 1.
2. **Dropdowns:** `/manager/orders` → **Date** opens below the field → pick *Last 7 days* → table filters → **Clear filters** resets it to *All time*.
3. **Vendors:** `/manager/vendorslist` → no budget box; Amazon / G&T show "Prices"; all cards have Edit · View products · Delete on one line.
4. **Amazon catalog:** `/manager/amazon` → 3 cards + table → **Sync prices now** → toast + "Last price sync" updates → **Edit** opens the product.
5. **Users:** `/manager/users` → **Edit** a test user → Status *Inactive* → Save → row shows **Inactive** and that user can't sign in → set back to *Active*. (Role field shows only for administrators.)
6. **Order detail:** open a pending order → amber box with **Reject / Approve**; open an approved order whose submit failed → red box with the reason + **Place with Amazon (recommended)**. Log in as a Supply Manager (not admin) → no "Technical details" / API section.

### Deploy to production (files changed since 3 PM, 4 Nov)

Upload these 14 files (paths from the site root `wp-content/`), then hard-refresh (Cmd+Shift+R). The CSS/JS version is bumped to `1.4.30`, so browsers load the new files.

```
wp-content/plugins/wcss-internal-supplies/frontend/assets/css/manager.css
wp-content/plugins/wcss-internal-supplies/frontend/assets/js/ccm-screens.js
wp-content/plugins/wcss-internal-supplies/frontend/routes/class-routes-manager.php
wp-content/plugins/wcss-internal-supplies/frontend/pages/partials/manager-nav.php
wp-content/plugins/wcss-internal-supplies/frontend/pages/orders.php
wp-content/plugins/wcss-internal-supplies/frontend/pages/orders-view.php
wp-content/plugins/wcss-internal-supplies/frontend/pages/products.php
wp-content/plugins/wcss-internal-supplies/frontend/pages/stores.php
wp-content/plugins/wcss-internal-supplies/frontend/pages/users.php
wp-content/plugins/wcss-internal-supplies/frontend/pages/amazon.php
wp-content/plugins/wcss-internal-supplies/frontend/pages/gt.php
wp-content/plugins/wcss-internal-supplies/frontend/pages/reports.php
wp-content/plugins/wcss-internal-supplies/includes/rest/class-rest-orders.php
wp-content/plugins/wcss-internal-supplies/includes/rest/class-rest-stores.php
```

Do **not** upload `wp-content/mu-plugins/` (local-only mail redirect) or `tests/`.

### Known follow-ups

- Orders still download the full list once per visit (fine today: 66 orders). The Orders screen stops at 1,000 orders, so move it to server-side paging (20 per request) before then.
- "Sync prices now" works in batches of 15 products per click; the hourly cron covers the rest. Grand & Toy prices stay at 0 synced until G&T allow-lists the server IP.

