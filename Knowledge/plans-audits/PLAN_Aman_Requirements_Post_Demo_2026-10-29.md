# PLAN — All Aman / Ops requirements after 29 Oct 2026 internal demo

**Source meeting:** [MEETING_2026-10-29_INTERNAL_DEMO.md](../client-shared/meetings/MEETING_2026-10-29_INTERNAL_DEMO.md)  
**Demo PDF:** `Knowledge/client-shared/meetings/Internal Demo of Amazon and Grand Toy 2026-10-29.pdf`  
**Next stakeholder demo:** Wednesday **4 November 2026** (1 hour; Aman required; Bhaumik + Basit QA first)  
**Related storefront plan (cheapest price):** `docs/PLAN-vendor-cheapest-pricing-and-checkout-ux.md`  
**Claude design brief (dashboard UI only):** [CLAUDE_BRIEF_Supply_Manager_Dashboard_Revamp.md](./CLAUDE_BRIEF_Supply_Manager_Dashboard_Revamp.md)  
**Project status (current flows):** [../PROJECT_STATUS_AND_CHAT_HISTORY.md](../PROJECT_STATUS_AND_CHAT_HISTORY.md)  
**Working branch for cheapest storefront work:** `feature/store-cheapest-pricing-checkout-ux-2026-10-30`

---

## 0. Goal in one sentence

Automate the happy path (cheapest vendor → auto-approve → auto-submit to vendor), leave exception work for Supply Ops (Alyshia / Hillel / Chris), and give Ops a real **reports + analytics** Supply Manager portal — not a CSV-and-Excel workflow.

---

## 1. Status legend

| Status | Meaning |
|--------|---------|
| **Done (local)** | Built on local / in theme or plugin; may not be live |
| **Partial** | Exists but does not match Aman ask |
| **Todo** | Not built or wrong behaviour |
| **Post-launch** | Explicitly deferrable ~1 month after go-live |

---

## 2. Workstreams (everything they asked for)

### A — Storefront pricing & checkout (stores never pick vendor)

| ID | Requirement | Status | Notes |
|----|-------------|--------|-------|
| A1 | Auto pick **cheapest** of Amazon vs Grand & Toy; show that price | **Done (local)** | `cc-vendor-cheapest.php` + price cache |
| A2 | Remove store vendor dropdown | **Done (local)** | Manual picker retired in `cc-cart-vendor.php` |
| A3 | Scheduled price sync (not live API every page) | **Done (local)** | Hourly cron; Basit said “daily” on call — hourly is fine |
| A4 | Hide **vendor name** from store cart / checkout | **Partial** | Checkout still shows Vendor column in `review-order.php` — **remove for store role** |
| A5 | Store still sees **budget remaining / threshold** | **Partial** | Cap exists; confirm $125 + UX clarity |
| A6 | Budget-exceeded alert at **top** of checkout | **Partial / Todo** | Move under pagehead / steps (existing cheapest plan §7.3) |
| A7 | Explicit **Back to cart** on checkout | **Partial / Todo** | Same plan §7.1 |
| A8 | Checkout chrome parity with cart | **Partial / Todo** | Same plan P4 |

**Orders tab impact:** none for store UI. Manager still needs to see which vendor won (see §3).

---

### B — Budget, cadence, ordering windows

| ID | Requirement | Status | Notes |
|----|-------------|--------|-------|
| B1 | All stores monthly budget **CAD $125** (not $160 / not tiered) | **Todo** | Chris: thermal rolls out of budget; update defaults + Test store |
| B2 | **One order per store per month** (“one and done”) | **Partial** | `default_monthly_quota` / store meta exists — set to **1** everywhere and enforce |
| B3 | Configurable **ordering windows** (e.g. stores 1–100: days 1–7; next block: 7–15) | **Todo** | New: window start/end day (or group) editable by Chris/Alyshia |
| B4 | Outside window / second order / over budget → **exception path to Alyshia** | **Todo** | Do not only hard-block forever |
| B5 | Launch exception UX: form or email to Alyshia | **Todo (launch)** | Full in-flow exception order **post-launch** (Bhaumik analytics concern; Aman OK to delay ~1 month) |

---

### C — Auto-approve + auto-submit (happy path)

| ID | Requirement | Status | Notes |
|----|-------------|--------|-------|
| C1 | If in window + under $125 + first order + cheapest vendor already applied → **auto-approve** | **Todo** | Roll back “always awaiting approval” for in-policy orders |
| C2 | On auto-approve → **auto-submit to winning vendor** (Amazon or G&T) | **Todo** | No human Approve / vendor switch / manual field fill on happy path |
| C3 | Checkout / PO fields **pre-filled** from store | **Partial** | G&T “use store details” exists; must be automatic |
| C4 | Keep existing Approve / Amazon Test / G&T Test screens | **Keep** | Aman: leave UI; **skip** on happy path; do not rip out before launch |
| C5 | Alyshia only sees / approves **exceptions** | **Todo** | Over budget, outside window, double order / emergency |

**This is the core change to how the Orders screen is used** — see §3.

---

### D — Notifications & mail hygiene

| ID | Requirement | Status | Notes |
|----|-------------|--------|-------|
| D1 | Store gets order-submitted confirmation | **Partial** | Confirm on both Amazon + G&T place |
| D2 | Copy **unmonitored supply mailbox** on every vendor submission (audit trail) | **Todo** | For “G&T never got the PO” disputes |
| D3 | Remove Aman (`asood@hightideinc.com`) from test / auto emails | **Todo** | He got ~10 test mails morning of demo |
| D4 | Ops email config uses Ops mailbox, not personal inboxes for routine traffic | **Todo** | Confirm with Chris/Alyshia |

---

### E — Supply Manager portal: Dashboard + Reports (Aman’s main complaint)

| ID | Requirement | Status | Notes |
|----|-------------|--------|-------|
| E1 | Rename portal: **Supply Manager** (or Approving Manager), not “Manager Dashboard” | **Todo** | Nav brand + page titles |
| E2 | Keep lightweight **analytics** (cards / trends) | **Partial** | Exists; redesign + fix data quality |
| E3 | Real **reports** Ops can filter without Excel pivots | **Todo** | See §4 |
| E4 | Answer in one click: **Amazon spend vs G&T spend this month** | **Todo** | Primary demo question Aman asked |
| E5 | Filters: product, vendor, store, user, order fields + date range | **Todo** | Bhaumik committed on call |
| E6 | Export filtered results to **Excel / CSV** (not PDF-as-report) | **Partial** | Overview CSV/PDF exist; not filterable custom reports |
| E7 | Alyshia can create / save **custom reports** | **Todo** | At least: saved filter presets; full builder if time |
| E8 | Full visual **revamp** of Supply Manager dashboard | **Todo** | Use Claude brief doc |

---

### F — Process / demo hygiene (not code, but required)

| ID | Requirement | Owner |
|----|-------------|--------|
| F1 | Internal QA before 4 Nov demo | Bhaumik + Basit |
| F2 | Calendar: 1h, Aman required (Harleen) | Chris |
| F3 | Keep Aman copied on decision emails until decisions land | Basit / Usman |

---

## 3. Manager **Orders** tab — what is required on this screen

**URL today:** `/manager/orders`  
**Files:** `frontend/pages/orders.php`, order view in `manager.js`, Amazon/G&T views

### 3.1 What the screen does today

- List Woo orders with status + date filters  
- Open order → Approve / Reject  
- After approve: **Amazon Test Order** / **G&T Test Order** (manual path)  
- Vendor views for placed Amazon / G&T snapshots  

### 3.2 What changes on this screen (from the meeting)

| Change | Why | Priority |
|--------|-----|----------|
| **Default queue = Exceptions only** | Happy-path orders auto-approve and leave this queue | P0 |
| Status / badges: `Auto-approved`, `Submitted to Amazon`, `Submitted to G&T`, `Needs Ops approval`, `Rejected`, `Exception pending` | Aman must see automation vs manual | P0 |
| Show **winning vendor** + unit prices (Ops only) | Stores must not see vendor; Ops must | P0 |
| Show **why** an order needs approval (over budget / outside window / second order / emergency) | Alyshia triage | P0 |
| Show store **budget used / remaining**, window status, month order count | Decision context | P0 |
| Keep Approve / Reject for exceptions | Still needed for out-of-policy | P0 |
| Keep Amazon / G&T manual buttons | Aman: leave them; used for exceptions / ops override / launch safety | P1 |
| Filters: status, store, vendor, date, exception reason | Match report needs | P1 |
| Export filtered order list to CSV/Excel | Ops leaders should not pivot by hand | P1 |
| Optional: one-click “Approve & place with winning vendor” for exceptions | Cuts clicks when Ops allows exception | P2 |
| Rename page context to Supply Manager Orders | Naming | P1 |

### 3.3 Happy path vs Orders tab (behaviour)

```
Store places requisition (no vendor UI)
        │
        ▼
Policy check: window? budget ≤ $125? first order this month? cheapest applied?
        │
   YES ─┴─ NO ──────────────────────────────────────┐
        │                                             │
        ▼                                             ▼
 Auto-approve                                   Status: Needs Ops approval
 Auto-submit to Amazon or G&T                   Appears in Orders exception queue
 Notify store + supply mailbox                  Alyshia Approve / Reject
 Skip Orders approve UI                         Then place (or auto-place after approve)
```

**Orders tab is primarily an exception + audit list**, not “approve every store order.”

---

## 4. Supply Manager **Dashboard** — required content (functional)

Not just a redesign — these **widgets / reports** must exist:

### 4.1 Analytics (home)

1. Spend this month (total) + vs last month  
2. **Spend by vendor** (Amazon vs Grand & Toy) — large, not buried  
3. Orders this month: auto-approved vs exception vs rejected  
4. Stores ordered / not ordered this cycle  
5. Budget utilization: stores near cap, over-cap exceptions  
6. Trend: last 6 months spend **and** order count (spend was missing as primary)  

### 4.2 Reports (dedicated section or tab)

Must answer without Excel:

- Spend by vendor (month / custom range)  
- Spend by store  
- Spend by product / SKU  
- Orders by user (store employee)  
- Exception report (reason + outcome)  
- Ordered vs not-ordered stores for current window  

Controls: date range, vendor, store, product, user, status → **Export Excel/CSV**.  
Saved presets for Alyshia (“This month Amazon”, “Exceptions this week”).

### 4.3 What to demote

- PDF export of the whole dashboard as the “report” (Aman rejected)  
- Relying on overview CSV only  

---

## 5. Implementation phases (suggested)

| Phase | Scope | Target before |
|-------|--------|---------------|
| **P0** | B1 $125; A4 hide store vendor; D3 stop Aman test mail; E1 rename; Orders exception badges stub | Internal QA |
| **P1** | C1–C2 auto-approve + auto-submit happy path; B2 quota=1; D1–D2 emails | 4 Nov demo |
| **P2** | B3 ordering windows; B4–B5 exception form; Orders queue = exceptions | ASAP after / during P1 |
| **P3** | E2–E7 reports + vendor spend; dashboard data fix | 4 Nov if possible, else immediate next |
| **P4** | Full UI revamp (Claude brief) | Parallel with P3 |
| **P5** | Post-launch: full in-system exception order + analytics for those | ~+1 month |

Existing cheapest / checkout UX plan (`docs/PLAN-vendor-cheapest-pricing-and-checkout-ux.md`) continues as **P-store** for A5–A8; do not block automation on polish.

---

## 6. Open decisions (confirm with Chris / Alyshia / Aman)

1. Exact Supply Manager naming string: **Supply Manager** vs **Approving Manager**  
2. Supply audit mailbox address for D2  
3. Ordering window model: per-store dates vs store groups vs global calendar week  
4. Auto-submit to vendor: live Amazon Trial Mode vs stage G&T only until go-live flag  
5. Exception form: email-only for launch vs create Woo order in `needs-approval`  
6. Custom reports: saved filters enough for Nov 4, or full builder  

---

## 7. File map (where work will land)

| Area | Likely paths |
|------|----------------|
| Portal nav / dashboard / orders UI | `wcss-internal-supplies/frontend/pages/*`, `partials/manager-nav.php`, `assets/css/manager.css`, `assets/js/manager.js` |
| Reports API | `wcss-internal-supplies/includes/rest/*` (`reports/overview` today) |
| Quota / budget | `class-order-quota.php`, `class-store-cpt.php`, settings |
| Auto-approve / auto-place | New hooks on order create + vendor place helpers in child theme Amazon/G&T modules |
| Storefront vendor hide / alerts | Child theme checkout templates + `cc-cart-ui` |

---

## 8. Acceptance checks (demo-ready)

- [ ] Store never sees vendor picker or vendor column  
- [ ] In-policy order auto-approves and submits; does not sit in Alyshia’s queue  
- [ ] Exception order appears on Orders with clear reason  
- [ ] Dashboard answers Amazon vs G&T spend for current month  
- [ ] Filtered export downloads without Excel formula work  
- [ ] Portal branded Supply Manager  
- [ ] Budget default $125; one order/month enforced  
- [ ] Aman not receiving test emails  
- [ ] Store + supply mailbox get submit confirmation  
