# CC Supplies: what was built, how to set it up, how to test it

**Last updated:** 3 November 2026 (evening)
**Branch:** `feature/auto-vendor-submit-manager-ux-2026-11-02` (tip `b349205` on GitHub)
**Demo runbook (call script):** [DEMO_SCRIPT_2026-11-04.md](DEMO_SCRIPT_2026-11-04.md)
**Requirements source:** [MEETING_2026-10-29_INTERNAL_DEMO.md](client-shared/meetings/MEETING_2026-10-29_INTERNAL_DEMO.md) and [PLAN_Aman_Requirements_Post_Demo_2026-10-29.md](plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md). Task IDs below (A1, B2, C1…) come from that plan.
**Stakeholder demo:** Wednesday 4 November 2026. Use the [demo script](DEMO_SCRIPT_2026-11-04.md) on the call; this guide is the full setup + QA reference.

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
| B3 | Configurable ordering window | 🟡 Done as **one global window** (`/manager/stores` → Ordering policy). Per-store-group windows not built. |
| C1 / C5 | Auto-approve only in-policy orders; others wait for Supply Ops | ✅ Done |
| C2 | Auto-submit to the winning vendor | ✅ Done (shipped in commit `63a5e52`, now policy-gated) |
| — | Amazon place reliability | ✅ Done |
| — | Bug: Approve crashed on Valiant orders | ✅ Fixed |
| D3 | Stop emails to Aman | ✅ Done (Aman is no longer a shop_manager; local mail goes to the developer only) |
| E1 | Rename the portal to **Supply Manager** (sidebar, tab titles, page labels) | ✅ Done |
| — | **Email Management** tab: per-address notification choices, manual send, branded templates, sent log | ✅ Done (§5.3) |
| D2 | Copy vendor submissions to a supply mailbox | 🟡 Configurable: add the mailbox in **Emails** and tick *Vendor approved order* + *Vendor rejected order* |
| E3–E7 | **Reports**: 7 filterable reports (Amazon vs G&T, store, product, user, exceptions, stores ordered / not ordered, order detail), saved presets, branded Excel / CSV / Print-PDF export | ✅ Done (§5.4) |
| E2 | Dashboard clarity: Amazon vs G&T this month under the KPIs, "Ordering this month" coverage card, Reports + Export Excel replace the old CSV/PDF snapshot | ✅ Done |
| — | Dashboard **Custom** date period (from / to + Apply), equal-height toolbar; card order: KPIs → vendor split → chart + budgets → coverage + recent orders → activity + attention | ✅ Done |
| — | Emails tab hover (black) / active (red tint) styling | ✅ Done |
| B5 | Exception form to Alyshia | ❌ Pending (§7) |

Local QA applied store limits via **Stores → Apply to all stores** (125 / 1). Branch is on GitHub. Production deploy is separate.

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
| Ordering as a store | `storeowner.gt` (storeowner.gt@ccsupplies.local) | store_employee | Linked to **Test store** (ID 8177) |
| WP admin settings | `localadmin` | administrator | |

**Test data:** store **Test store** (ID 8177; after Apply-to-all: quota **1**, budget **CA$125**). Product **Thermal roll** (ID 8176, $57). Amazon test product ID 7654 (ASIN B07FDHJ73F).

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

### 5. Set the ordering policy (B1 / B2)

Either screen works; both save the same settings.

- **Portal:** `/manager/stores` → **Ordering policy** button
- **WP admin:** **WooCommerce → Internal Supplies** (`?page=wcss-settings`)

| Field (portal / admin) | Meeting value | Meaning |
|------------------------|---------------|---------|
| Default monthly budget (CA$) | **125** | Used when a store has no budget of its own |
| Default orders per month | **1** | Used when a store has no quota of its own. **Set to 1 on local.** |
| Ordering window opens / closes | e.g. **1 / 7** | Day of month. Wrapping is allowed (e.g. 25 → 5 means the 25th through the 5th). 1 / 31 means always open. |
| Orders outside the policy | **Place them and send to Supply Ops for approval** | The alternative is "Block them at checkout" (hard stop) |

### 6. Apply $125 / 1 order to every store (B1 / B2)

`/manager/stores` → **Apply to all stores** → Monthly budget **125**, Orders per month **1** → **Apply to all stores**.

**Local status (3 Nov 2026):** already run — all **223** published stores are CA$125 / 1 order. Re-run only if you restore an old DB dump. Per-store edits remain possible via each store's **Edit** button.

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
| Stores | `/manager/stores` | Quota, budget, usage bar, assigned user; **Ordering policy**, **Apply to all stores**, Export |
| Users | `/manager/users` | Store employees; edit name, email and store; remove |
| Emails | `/manager/emails` | **Recipients** (who gets which notification, Save changes), **Send email** (manual), **Templates** (preview + send test), **Sent log**. Tabs: active = red tint, hover = black |
| Amazon catalog | `/manager/amazon` | Amazon items and prices |
| Grand & Toy | `/manager/gt` | G&T items and prices |

Topbar: global search, live clock, **Refresh**. The sidebar shows a red badge with the pending-approval count.

### Storefront (logged in as a store)

- **Product page:** professional qty stepper, stock text ("In stock" or "Only N left"), add-to-cart toast with **View cart**.
- **Checkout:** no Vendor column. Responsive at all widths. If the cart is outside the policy, a notice at the top says the order will need Supply Ops approval and why. The store can still place the order in review mode.
- **Place order:** full-screen loader with progress steps. After about 12 seconds it shows a "still working" message.

### Code map

| Area | File |
|------|------|
| Policy engine (window, quota, budget, REST `/wcss/v1/policy`, `/stores/apply-limits`) | `plugins/wcss-internal-supplies/includes/class-order-policy.php` |
| Approve check (409 with reasons, override) | `…/includes/rest/class-rest-orders.php` |
| Checkout block (block mode only) | `…/includes/class-order-quota.php` |
| Admin settings fields | `…/includes/class-admin-settings.php` |
| Vendor email (fatal fix) | `…/includes/class-notifications.php` |
| Portal screens JS / CSS | `…/frontend/assets/js/ccm-screens.js`, `…/frontend/assets/css/manager.css` |
| Auto-submit, policy gate, Amazon reliability | `themes/adex360-canna-cabana-stationery-child/cc-auto-vendor-submit.php` |
| Product page UI and toast | child theme `cc-product-ui.php/.css/.js` |
| Checkout and loader | child theme `cc-cart-ui.css/.js`, `woocommerce/checkout/review-order.php` |
| Email recipients, events, triggers, REST `/wcss/v1/emails/*` | `…/includes/class-email-center.php` |
| **Email template** (header, colours, layout of every email) | `…/includes/class-email-template.php` |
| Store employee + vendor emails (not configurable per address) | `…/includes/class-notifications.php` |
| Emails page | `…/frontend/pages/emails.php` + `screens.emails` in `ccm-screens.js` |
| Report engine, presets, REST `/wcss/v1/reports/*`, exports (`/manager/reports/export/`) | `…/includes/class-reports.php` |
| Excel writer (styles, sheets) | `…/includes/class-xlsx.php` |
| Reports page / dashboard cards | `…/frontend/pages/reports.php`, `screens.reports` + `screens.dashboard` + `vendorSplit()` in `ccm-screens.js` |
| Local-only mail redirect | `wp-content/mu-plugins/cc-local-mail-redirect.php` (git-ignored) |

---

## 5. How the new rules behave

### 5.1 Ordering policy (B1, B2, C1, C5)

An order is **in policy** when all of these are true:

1. Today is inside the ordering window.
2. It's the store's **first** order this month (more precisely, under the store's orders-per-month limit).
3. This month's spend including this order is **≤ the store's budget**.

```
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
| System | Ordering policy changed | Ordering policy saved with a change, or **Apply to all stores** |

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

Expected output: `RESULT: 22 passed, 0 failed`, `RESULT: 15 passed, 0 failed` `RESULT: 47 passed, 0 failed` and `RESULT: 41 passed, 0 failed`.

The scripts are safe on local:
- They **block every email** and print how many were blocked.
- The Amazon test **mocks every HTTP call**. Nothing reaches Amazon.
- They create throwaway orders on Test store (8177), then delete them (and their email-log rows) and restore the store's quota, budget, site settings and email recipients.
- They refuse to run unless the site URL ends in `.local`.
- `tests/` sits outside `app/public`, so it's never deployed.

What they cover:

| Suite | Checks |
|-------|--------|
| `policy-test.php` (22) | $125 fallback · first order in policy · second order flagged · over budget · outside window · window wrap 25→5 · auto-submit holds out-of-policy orders · in-policy order passes · Approve → 409 with reasons · Approve anyway → approved and resubmitted · Reject → not sent · API exposes the policy · review mode doesn't block checkout · block mode blocks · settings validation |
| `email-test.php` (47) | Seeding from shop managers · save validation (bad / duplicate address, unknown events) · each notification goes only to addresses that ticked it · paused address gets nothing · store email never names the vendor · needs-approval reasons · reopen only from approved/rejected · rejection reason shown, Woo noise removed · vendor accepted / failed / skipped · vendor add, real edit only, budget, remove · policy change and apply-all · template header/date/link · manual send (2 copies, formatting kept, scripts stripped, bad address / subject / message, server failure message) · previews for all 9 · Send all tests · log list + view · store employee blocked from the API |
| `reports-test.php` (41) | Adds known orders and checks the exact change: Amazon +20, G&T +30, rejected 99 excluded · shares = 100% · totals count each order once · vendor / status / product / user / province / reason filters · store budget % · exceptions outcome incl. "Approved as exception" + approver · coverage ordered / not ordered · custom and relative dates · meta + run API · presets save / duplicate / empty / built-in delete refused / delete · CSV (BOM, totals, injection) · Excel (sheets, frozen header, filter, numbers) · print page · store employee blocked |
| `amazon-reliability-test.php` (15) | Only this order's item placed · qty 2 placed, not the cart's stale 9 · 503 retried with the same externalId · attempts recorded · network timeout not retried · busy lock defers with zero Amazon calls · full run submits, approves and releases the lock |

### 6.2 Manual click-through

Do [Setup step 4](#4-make-local-testing-safe-do-this-first) first. Use two browsers (or one private window): **A** = Supply Manager, **B** = `storeowner.gt`.

**Test 1: Ordering policy settings (B1 / B2)**
1. A: `/manager/stores` → **Ordering policy**. Set budget 125, orders per month 1, window 1–31, "Place them and send to Supply Ops for approval". Save.
2. Expected: toast **Ordering policy saved**. Reopen the dialog. The values persist and match **WooCommerce → Internal Supplies** in WP admin.
3. A: Test store → **Edit** → set quota 1, budget 125 (or use **Apply to all stores** if you mean to change every store).

**Test 2: First order is auto-approved (C1)**
1. B: add 1 × Thermal roll ($57) → checkout. Expected: **no** Vendor column and **no** approval notice.
2. Place the order. Expected: the professional loader, then the thank-you page.
3. A: `/manager/orders` → open the order. Expected: green **Within store policy**. Status moves to **Approved**, and the full order page shows the **Auto vendor submit** result. (If you disabled auto-submit in step 4, it stays awaiting.)

**Test 3: Second order needs approval (B2 / C5)**
1. B: place another order the same month. Expected: a notice at the top of checkout: *"This order will need Supply Ops approval. Your store has already ordered this month."* The order can still be placed.
2. A: the Dashboard **Needs attention** list and the sidebar badge both include it. The drawer shows amber **Needs Supply Ops approval** with the reason.
3. A: click **Approve** → the dialog **Approve over the store's limit?** appears → **Approve anyway**. Expected: status Approved, drawer shows blue **Approved as an exception**, and the order goes to auto-submit.
4. Repeat with a third order and click **Reject**. Expected: Rejected, and nothing is sent to a vendor.

**Test 4: Over budget**
Set Test store quota to 5, then order 3 × Thermal roll ($171). Expected reason: **over the monthly budget**.

**Test 5: Outside the window (B3)**
Ordering policy → set the window to days that exclude today (e.g. today + 2 to today + 3). Place an order. Expected reason: **outside the ordering window**. Reset to 1–31 afterwards.

**Test 6: Block mode**
Ordering policy → **Block them at checkout**. A second order is now refused at checkout with the reason. Switch back to review mode afterwards.

**Test 7: Vendors**
`/manager/vendors`: each card shows **Prices fetched** time. **Set budget** saves a monthly amount. **View API response** shows the latest fetch and grouped errors; **Fetch now** refreshes it. **Edit** lets you change all fields, and bad email or URL values are rejected.

**Test 8: Storefront UI**
Product page: the − / + stepper is aligned and the add-to-cart toast has no blue border. Checkout: resize to phone width; no text overlaps.

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

### Done on local (3 Nov 2026)

1. **Apply to all stores** with **$125 / 1** — verified in UI and DB (223 stores).
2. **Default orders per month = 1** (and default budget 125) in Ordering policy / `wcss_settings`.
3. Dashboard Custom date period + card reorder; Emails tab hover/active styles — on GitHub (`b349205`).
4. Local email path verified: Office 365 SMTP accepts; 9 `[TEST]` templates received at `uzahid@basecampconsulting.co`.

### Decisions needed before production deploy

1. **Production:** remove Aman (`asood`) and `amaclean` from `shop_manager` there **before** deploying. On first load, Emails pre-fills its list from shop managers; after deploy, open **Emails** and check the list.
2. Re-run **Apply to all stores** (125 / 1) and set Ordering policy defaults on **production** after deploy — local DB values do not transfer with Git.

### Not built yet

| ID | Item | Where / note |
|----|------|----------------|
| B3 | Ordering windows **per store group** (e.g. stores 1–100 days 1–7) | Only **one global** window exists: Stores → **Ordering policy** |
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
