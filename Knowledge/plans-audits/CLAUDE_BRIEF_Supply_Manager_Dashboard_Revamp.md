# Claude brief — Revamp Supply Manager Dashboard (CC Supplies)

**Use this document as the full requirements prompt for Claude (or any design/implementation agent).**  
Paste the whole file, or start with §1–§6 plus the acceptance checklist.

**Product:** CC Supplies (Canna Cabana internal stationery / supplies portal)  
**Live:** https://ccsupplies.ca · **Local:** https://ccsupplies.local  
**Portal URL today:** `/manager/` (must be renamed conceptually to **Supply Manager**)  
**Audience:** Ops only — Alyshia Digiovannantonio, Hillel, Chris Bilton (not store managers, not store employees)  
**Stakeholder pain (29 Oct 2026 demo):** Current dashboard feels outdated; Export PDF/CSV of a summary is **not** reporting. Ops spends ~CAD $50k/month across ~235 stores and cannot answer “Amazon vs Grand & Toy spend this month” without Excel.

**Do not redesign the storefront shop.** This brief is **only** the Supply Manager portal (dashboard home + reports + related chrome). Orders list redesign notes are in §7.

**Related functional plan:** [PLAN_Aman_Requirements_Post_Demo_2026-10-29.md](./PLAN_Aman_Requirements_Post_Demo_2026-10-29.md)

---

## 1. Mission

Rebuild the Supply Manager home experience so that:

1. It looks like a **2026 ops console**, not a 2015 admin table dump.  
2. **Analytics** (glanceable) and **Reports** (filter → Excel) are clearly two different jobs.  
3. The first viewport answers the business question Aman asked live:  
   **How much did we spend with Amazon vs Grand & Toy this month?**  
4. Naming never says “Manager Dashboard” alone — use **Supply Manager**.

---

## 2. Current baseline (replace / upgrade)

### 2.1 Tech stack (preserve)

- WordPress + WooCommerce  
- Plugin: `wcss-internal-supplies`  
- Pages: `frontend/pages/dashboard.php`, nav `frontend/pages/partials/manager-nav.php`  
- JS: `frontend/assets/js/manager.js` → `initDashboard()` loads `GET /wp-json/wcss/v1/reports/overview`  
- CSS: `frontend/assets/css/manager.css`  
- Brand: Canna Cabana (green / dark logo already in nav). Prefer extending existing CSS variables; do not invent a purple SaaS theme.

### 2.2 What exists today (keep the data ideas, not the layout)

| Block | Keep as concept? |
|-------|------------------|
| Pending / Approved / Rejected counts | Yes — retitle for auto-approved vs needs Ops |
| Orders cost this month | Yes — upgrade to spend + vendor split |
| Stores total / active | Yes |
| Top vendors table | Yes — elevate to primary chart/cards |
| Orders trend 6 months | Yes — add **spend** trend, not only order count |
| Store budgets table | Yes — make scannable; highlight exceptions |
| Export CSV / PDF of whole overview | CSV OK as secondary; **PDF-as-report demoted**. Primary path = filtered report export |

### 2.3 Hard product rules

- Portal users = Supply Ops only (`wcss_manage_portal`).  
- Store employees never see this UI.  
- Vendors in scope: **Amazon** and **Grand & Toy** (keep separate; do not invent a mixed “marketplace” metaphor).  
- Happy-path orders will become auto-approved; dashboard must reflect that world (exceptions matter more than “pending approve all”).

---

## 3. Information architecture

```
Supply Manager
├── Home (Analytics dashboard)     ← this revamp focus
├── Reports                        ← new or upgraded section (same visual system)
├── Orders                         ← exception queue + audit (see §7)
├── Products
├── Vendors
├── Stores
├── Users
├── Amazon catalog                 ← keep separate
└── Grand & Toy                    ← keep separate
```

**Home job:** situational awareness in &lt; 10 seconds.  
**Reports job:** answer any filtered spend/ops question → download Excel/CSV.  
Do not merge Home and Reports into one overcrowded page.

---

## 4. Home (Analytics) — required layout

### 4.1 First viewport (one composition)

Must include:

1. **Brand / portal title:** “Supply Manager” + Canna Cabana mark  
2. **Period control:** This month (default) | Last month | Custom range  
3. **Primary insight strip — Vendor spend comparison (hero metric)**  
   - Amazon spend (CAD)  
   - Grand & Toy spend (CAD)  
   - Total spend  
   - Optional: % split bar (Amazon | G&T)  
4. **One short subtitle**, e.g. “October 2026 · CAD”  
5. **Primary CTA group:** `Open reports` · `Export this view (CSV)`  

Do **not** pack schedules, address blocks, or promo chips in the first viewport.  
Do **not** overlay badges on charts.

### 4.2 Second band — Ops health cards (4–6 max)

Suggested cards (rename to match automation):

| Card | Meaning |
|------|---------|
| Needs Ops approval | Exception queue count |
| Auto-approved (month) | In-policy volume |
| Submitted to vendors | Successfully placed Amazon + G&T |
| Rejected / failed place | Failures Ops must chase |
| Stores ordered this cycle | X of ~235 |
| Budget exceptions | Over $125 or second-order requests |

Each card: label, big number, tiny delta or helper text. Click → deep-link to Orders or Reports with filter pre-applied.

### 4.3 Third band — charts (two panels)

1. **Spend by vendor** (month) — bar or donut; Amazon vs G&T only as first-class series  
2. **Trend (6 months)** — dual series preferred: order count + spend (or toggle)

### 4.4 Fourth band — tables

1. **Top stores by spend** (this period) — store, orders, spend, budget, % used  
2. **Exceptions needing attention** — order #, store, reason (over budget / outside window / second order), amount, age  

Empty states: calm, one sentence + link (“No exceptions — in-policy orders are auto-submitting”).

### 4.5 Explicit non-goals for Home

- No PDF as the main deliverable  
- No “1980s admin” dense unlabeled tables without hierarchy  
- No purple gradient / glassmorphism / emoji decoration  
- No cards-inside-cards nesting more than one level  
- No hero collage or floating sticker overlays  

---

## 5. Reports — required UX (same design system)

### 5.1 Purpose

Alyshia must answer without Excel pivots:

- Spend Amazon vs G&T for any date range  
- Spend by store / product / user  
- Exception list with outcomes  
- Who ordered / who has not ordered this cycle  

### 5.2 Controls

- Date range  
- Vendor: All | Amazon | Grand & Toy  
- Store (searchable)  
- Product / SKU (searchable)  
- User  
- Order status / exception reason  
- Apply · Clear · **Export Excel/CSV**  
- **Save as preset** (minimum custom-report bar for launch)

### 5.3 Results

- Table with sortable columns  
- Summary row: order count, total spend, split by vendor  
- Pagination or virtualized long lists  

---

## 6. Visual design direction

### 6.1 Brand & tone

- Professional supply-chain ops console for retail HQ  
- Calm, dense-but-breathable, high signal  
- Anchor: existing Canna Cabana greens / neutrals from current portal — refine, don’t replace with a generic AI template look  
- Typography: purposeful UI sans for data; avoid Inter/Roboto/Arial defaults if a distinct licensed or already-loaded brand font exists in the theme — otherwise pick one strong modern UI font already available on the site  

### 6.2 Atmosphere

- Soft gradient or subtle pattern in the **page background** (not flat white void)  
- Content surfaces: light panels with clear hierarchy; prefer borders/spacing over heavy shadows  
- Radius: modest; avoid pill overload  

### 6.3 Motion (2–3 intentional)

1. Number count-up on KPI cards when period changes  
2. Chart series fade/slide on filter apply  
3. Table row highlight on deep-link from a KPI card  

No continuous ambient animation.

### 6.4 Responsive

- Desktop-first (Ops workstations)  
- Tablet: stack chart panels  
- Mobile: usable for exception triage (cards + exception list); full report building can be desktop-priority  

### 6.5 Accessibility

- Contrast WCAG AA  
- Keyboard for filters and export  
- Charts have text equivalents / data table toggle  
- `prefers-reduced-motion` disables count-up / chart motion  

---

## 7. Orders screen — design + content requirements (same revamp pass)

**URL:** `/manager/orders`

### 7.1 Role of this screen after automation

Not “approve every store order.” It is:

1. **Exception queue** (default filter)  
2. **Audit list** of auto-approved / submitted orders  

### 7.2 Required on list rows / cards

- Order ID, store, date, total  
- Status badge: Needs Ops · Auto-approved · Submitted Amazon · Submitted G&T · Rejected · Place failed  
- **Exception reason** chip when applicable  
- Winning vendor (Ops only)  
- Quick actions: View · Approve · Reject · (keep Amazon / G&T place for overrides)  

### 7.3 Filters

Status, date range, store, vendor, exception reason — plus Export.

### 7.4 Order detail

- Budget used / remaining, window status, month order count  
- Line items with vendor + unit price (Ops)  
- Timeline: placed → auto/manual decision → vendor submission → confirmation IDs  

Visual language must match the new Home (shared nav, buttons, badges, tables).

---

## 8. Copy / naming

| Old | New |
|-----|-----|
| Manager Dashboard | Supply Manager |
| Pending (ambiguous) | Needs Ops approval |
| Manager (in emails/UI) | Supply Manager / Ops |
| Amazon Test Order (internal) | May keep for launch; label clearer later e.g. “Place with Amazon” |

Avoid “store manager” anywhere in this portal.

---

## 9. Implementation constraints for Claude

1. Prefer editing existing plugin frontend files over a new SPA framework.  
2. Reuse REST `reports/overview` where possible; extend API for vendor spend split and filtered report queries rather than inventing a parallel stack.  
3. Keep Amazon catalog and Grand & Toy catalog as **separate** nav items.  
4. Do not remove Approve / Amazon / G&T flows — restyle and re-prioritize.  
5. No secrets, no API keys in UI.  
6. Match existing permission gates (`wcss_manage_portal`).  

---

## 10. Deliverables expected from Claude

1. **Design spec** (layout wire + component list + badge states) OR direct implementation in plugin CSS/PHP/JS  
2. Updated `manager-nav.php` branding/labels  
3. Revamped `dashboard.php` structure + `manager.css`  
4. Reports page (new view) or clearly separated Reports panel with filters + export  
5. Orders list/detail visual alignment + exception-first default  
6. Short QA checklist against §11  

---

## 11. Acceptance checklist

- [ ] First viewport shows Amazon vs G&T spend for selected period without scrolling on a 1440×900 laptop  
- [ ] “Supply Manager” naming everywhere in portal chrome  
- [ ] Analytics (Home) and Reports are distinct  
- [ ] Reports: filter by vendor/store/product/user/date → CSV/Excel download  
- [ ] KPI cards deep-link to filtered Orders or Reports  
- [ ] Orders default view emphasizes exceptions; auto-approved visible but secondary  
- [ ] Exception reason visible without opening every order  
- [ ] Desktop and mobile usable; no horizontal chaos on tablet  
- [ ] PDF export not presented as the primary reporting tool  
- [ ] Visual identity feels like Canna Cabana Ops, not a generic purple dashboard template  

---

## 12. Prompt starter (paste to Claude)

```
You are implementing a UI/UX revamp of the CC Supplies Supply Manager portal
(WordPress plugin wcss-internal-supplies, routes under /manager/).

Read and follow the full requirements in:
Knowledge/plans-audits/CLAUDE_BRIEF_Supply_Manager_Dashboard_Revamp.md
and the functional plan:
Knowledge/plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md

Goals:
1) Redesign Home analytics so Amazon vs Grand & Toy spend is the hero metric.
2) Add a proper Reports experience with filters + Excel/CSV export.
3) Align Orders UI as an exception-first queue with clear badges.
4) Rename Manager Dashboard → Supply Manager.
5) Keep Amazon and G&T as separate nav paths; do not remove approve/place flows.

Implement in the existing plugin frontend (PHP templates + manager.css + manager.js).
Prefer extending reports REST endpoints over a new framework.
```
