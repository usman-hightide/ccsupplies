# Store Documentation

**Site:** https://ccsupplies.ca/  
**Brand name on site:** Canna Cabana – Stationery Store / Canna Cabana Internal Supply Store  
**Parent company (footer):** High Tide Inc.  
**Analysis sources:** Live public entry page + full local restore of production Updraft backup (WordPress/WooCommerce + custom plugin), authenticated employee UI walkthrough.

---

## 1. Executive Summary

**ccsupplies.ca** is **not a public retail shop**. It is a **login-only internal supply / stationery requisition portal** for **Canna Cabana retail store locations**. Store employees browse a curated catalog of office, cleaning, paper, battery, and printer-ink supplies, place **internal requisitions** (no card payment), and wait for **manager approval**, subject to each location’s **monthly order quota** and **budget**.

About **120 published products** across **8 main categories**, mapped to **~223 store locations**, with a separate **Manager Dashboard** (`/manager/`) for approvals, catalog, stores, users, and vendors.

---

## 2. Business & Store Purpose

| Topic | Detail |
| ----- | ------ |
| **What the store is** | Internal B2B/employee procurement portal for Canna Cabana (High Tide Inc.) locations |
| **What it sells** | Stationery, office supplies, printer ink/toner, paper, batteries, cleaning/breakroom goods, and a small “other” assortment (first aid, locks, ice melter, etc.) |
| **Target customers** | Assigned **store employees** (one user ↔ one store location). Managers operate the backend portal. Public visitors cannot shop. |
| **Problem solved** | Centralized ordering of routine store supplies with spend/order controls and approval, instead of ad-hoc purchasing |
| **Primary website purpose** | Authenticate users → browse catalog → submit requisition → managers approve/fulfill |

---

## 3. Product Catalog

**Totals (confirmed in DB):** ~120 published simple products · CAD pricing · no variable products in use · 3 marked out of stock at snapshot time.

### Main Categories

| Category | Approx. count | What it contains |
| -------- | ------------- | ---------------- |
| **OFFICE SUPPLIES** | 39 | Binders, pens, staplers, tape, scissors, folders, labels, laminating, whiteboards, calculators, etc. (many Grand & Toy / Office Depot / Swingline brands) |
| **CLEANING/BREAKROOM** | 27 | Cleaners, garbage bags, gloves, tissue, brooms, sanitizer, dish soap, air fresheners, etc. |
| **HP INK** | 19 | HP toner/ink cartridges by model/color (305A, 410A, 414A, 902XL, 962XL) |
| **EPSON INK** | 14 | Epson ink tanks/cartridges and related (502, 522 set, T10W, T902XL/XXL, maintenance box) |
| **OTHER** | 7 | First aid kit, bandages, lock, key rings, stepstool, shovel, ice melter |
| **BATTERIES** | 5 | Energizer Max AA/AAA/D/9V + coin batteries |
| **PAPER PRODUCTS** | 4 | Copy paper, notepad, cardstock |
| **LEXMARK INK** | 4 | Unison toner cartridges (CMYK) |
| **Uncategorized** | 1 | Thermal roll |

There are **no meaningful product subcategories** in the taxonomy (flat category tree).

### Product Types

- **Simple WooCommerce products only** (no active variations; color/size attributes exist but unused).
- **Printer consumables** sold as separate SKUs per color/model (not bundles except one Epson 522 full set).
- **Office/cleaning staples** typically single-SKU commodity items.
- **Price range examples:** ~$0 (placeholder “Product 1”) up to ~$219 (Epson T902XXL Black); many toners ~$50–$210.
- **Vendors taxonomy (`wcss_vendor`):** Grand & Toy (most linked), Amazon, Valiant, plus unused terms (123 Ink, Shoppers+, Staples). Used for manager reporting/emails—not a shopper-facing filter.

---

## 4. Website Structure

| Area | Behavior |
| ---- | -------- |
| **Public entry** | Any unauthenticated visit redirects to **My Account / Login** |
| **Homepage** (after login) | Hero CTA “Shop Now”, category grid, “Most ordered Items” product strip |
| **Header** | Logo, menu (Our Products / Our Categories / Cart), search, account, mini-cart |
| **Shop** | Paginated catalog (~12/page), category dropdown, sort |
| **Category archives** | Standard WooCommerce category listings |
| **Product pages** | Title, price, add to cart (simple products) |
| **Cart** | Line items + **quota/budget usage banner** |
| **Checkout** | Store summary (address locked to assigned store), optional order notes, **Internal Requisition** only—no shipping/payment UI |
| **My Account** | Profile (store info), Orders, Explore products, Account details (read-only), Logout |
| **Manager portal** | Separate UI at `/manager/` for shop managers/admins |
| **Footer** | Quick links (Products, My account, Cart), Contact (`info@cannacabana.com`), copyright High Tide Inc. |

Elementor builds key marketing/account pages; WooCommerce powers catalog/cart/checkout.

---

## 5. Navigation

### Header menu (“Menu For Header”) — primary employee nav

- **Our Products** → Shop  
- **Our Categories** → All Categories page  
- **Cart** → Cart  

### Header utilities

- Search  
- Account / username  
- Cart badge  

### Footer quick links

- Our Products · My account · Cart · Contact email  

### My Account nav (customized)

- Profile · Orders · Explore products · Account Details · Logout  
- Hidden vs default WooCommerce: Downloads, Addresses, Dashboard (Dashboard text may still appear in Elementor widget copy)

### Manager nav (`/manager/`)

- Dashboard · Orders · Products · Vendors · Stores · Users · Logout  

### Legacy / unused menus

- **Primary/Secondary** menus still contain demo links (`#` placeholders for Returns, Shipping, Product and Sizing, etc.). These are **not** the live employee header path.

---

## 6. Customer Journey

### Primary purchase (requisition) flow

```text
Login (My Account)
  → Homepage or Shop / Category
    → Product → Add to cart
      → Cart (see monthly orders used + budget remaining)
        → Checkout (store address auto-applied; Internal Requisition)
          → Place order → status “Awaiting Approval”
            → Manager approves/rejects
              → Employee sees status under My Account → Orders
```

### Other journeys

| Journey | Flow |
| ------- | ---- |
| **Search → product** | Header search → results → PDP → cart |
| **Category → product** | Homepage/All Categories/Shop sidebar → category → PDP |
| **Account → orders** | My Account → Orders → view past requisitions |
| **Profile** | My Account → Profile → read-only store name/code/address/quota/budget |
| **Manager approval** | Login as shop manager → `/manager/orders` → approve/reject; shipment/receiving tracking available in manager tools |
| **Password recovery** | “Lost your password?” on login (self-registration **disabled**) |

**Not available:** guest checkout, public registration, coupon entry, customer-chosen shipping methods, card payment at checkout.

---

## 7. Store Features

| Feature | What It Does | Where It Is Used |
| ------- | ------------ | ---------------- |
| Private portal | Forces login for all front-end pages | Site-wide (`WCSS_Private_Portal`) |
| Store assignment | One employee mapped to one `store_location` | User meta + Profile + checkout |
| Monthly order quota | Caps how many orders count per month (default setting **3**; many stores set to **1**) | Cart/checkout enforcement |
| Monthly budget | Caps spend against store budget (e.g. $140–$1000 samples) | Cart/checkout banner + validation |
| Internal Requisition gateway | Submits order with **no payment capture**; reduces stock | Checkout |
| Approval workflow | Custom statuses: awaiting-approval / approved / rejected | Manager + WP admin |
| Coupons | Disabled for this portal | Checkout |
| Shipping rates | Disabled; fulfillment tracked operationally by managers | Checkout / manager |
| Product search | Site search | Header |
| Category filter | Dropdown + category links | Shop / categories |
| Sorting | Popularity, rating, date, price | Shop |
| AJAX add to cart | Enabled | Catalog / homepage |
| Mini-cart | Shows item count | Header |
| Stock | WooCommerce stock (some items out of stock) | Catalog |
| Reviews / ratings sort | Sort option exists; little/no review usage observed | Shop |
| Wishlist | **Not found** | — |
| Newsletter signup | **Not found** | — |
| Public promotions/sale badges | Menu leftovers only; not a live promo system | — |
| Vendor tagging | Products linked to supply vendors | Manager reports / emails |
| Email notifications | Order/status emails to employee, managers, vendors | System emails (`no-reply@ccsupplies.ca` SMTP path in plugin) |
| Email log | Admin log of outbound mail | WP admin |
| Manager CRUD | Products, stores, users, vendors, orders | `/manager/` |
| User Switching plugin | Admins can switch users (ops/debug) | WP admin |
| Backup | UpdraftPlus | WP admin |

**Settings snapshot (`wcss_settings`):** `fully_private` · default quota `3` · budget enforcement **on** · bypass method `internal_requisition` · quota counts statuses `awaiting-approval` + `approved`.

---

## 8. Cart & Checkout

### Cart

- Standard WooCommerce cart (qty update, remove, totals).  
- **Quota/budget usage UI** (e.g. “Orders used this month: 0 / 1”, “Budget used: $0 / $160”).  
- Proceed to checkout.

### Checkout

- **Billing/shipping address fields removed** from UI; address forced from assigned store.  
- **Store Summary** card shown instead.  
- Optional **Order notes** (“special instructions for admin”).  
- Payment method: **Internal Requisition** — “Submit your requisition for approval. No payment required.”  
- Place order → creates WooCommerce order in **awaiting-approval** (not a paid online order).  
- Privacy policy notice links to privacy page (page is **draft** / incomplete—see §10).

### After order

- Stock reduced on requisition “payment”.  
- Emails notify store user, shop managers, and relevant vendors.  
- Managers approve/reject; ledger tracks monthly usage for budgets/quotas.

### Shipping & payment (customer-facing)

- **No customer shipping method selection.**  
- **No live card/PayPal capture** for employees (WooPayments/PayPal gateways present in DB but **disabled / not used** for the employee flow).  
- Currency: **CAD**. Store country default: **Canada (ON)** in Woo settings; store locations span Canadian provinces (e.g. Alberta samples).

---

## 9. Customer Account

| Capability | Detail |
| ---------- | ------ |
| Login | Email/username + password; “Remember me”; lost password |
| Registration | **Disabled** (accounts provisioned by admins/managers) |
| Roles | `store_employee` (shoppers), `shop_manager` (portal), `administrator` |
| Profile | Shows assigned store details + quota/budget (read-only) |
| Orders | View requisition history/status |
| Explore products | Shortcut to Shop |
| Account details | Visible but **not editable** by employee (admin-managed) |
| Addresses / downloads | Removed from account menu (addresses come from store) |
| Managers | Redirected to `/manager/` instead of normal shopping admin |

---

## 10. Policies & Important Information

| Item | Status |
| ---- | ------ |
| **Shipping policy page** | Page “Shipping and Tracking” exists but **content empty / not useful** |
| **Returns & Exchanges page** | Exists but **content empty / not useful** |
| **Privacy Policy** | Assigned page is **draft**; contains default WordPress suggested text (wrong site URL)—**not a real production policy** |
| **Terms & conditions** | **Not found** as a proper published policy |
| **FAQ / Customer Help / Contact / About** | Pages exist but largely **theme placeholder / lorem-style copy**—not reliable business policy |
| **Payment information** | Communicated at checkout: internal requisition, no payment |
| **Contact** | Footer: `info@cannacabana.com` |
| **Age restrictions** | **Not found** (catalog is stationery/supplies, not cannabis product sales) |
| **Purchasing requirements** | Must be logged in, assigned to a store, within quota/budget; manager approval required |

**Important:** Treat marketing/policy pages as **incomplete placeholders**. Operational rules that actually govern purchasing live in the **WCSS plugin** (quota, budget, approval), not in those pages.

---

## 11. Brand & Value Proposition

| Aspect | Observation |
| ------ | ----------- |
| **Brand identity** | Canna Cabana retail brand applied to an internal “Stationery Store” / “Internal Supply Store” |
| **Value proposition** | One place for store teams to order approved supplies under controlled budgets |
| **Tone/style** | Professional retail-branded UI (Elementor), stationery hero imagery, dark header, white content |
| **Differentiation** | Not open e-commerce—**location-based requisitions + approvals** |
| **Key selling points (to users)** | Easy browse/order, centralized catalog, account-managed ordering |
| **Trust signals** | Corporate parent (High Tide Inc.), branded login, account gating, approval workflow |
| **Note** | Some homepage copy typos/placeholders (“STATINONERY…”, empty category images, “Product 1” at $0) indicate unfinished polish on marketing surfaces |

---

## 12. Technical Information

| Item | Evidence-based detail |
| ---- | --------------------- |
| **CMS / e-commerce** | WordPress + **WooCommerce** (HPOS/custom orders tables enabled) |
| **Custom core plugin** | **WCSS – Internal Supplies for WooCommerce** (`wcss-internal-supplies`) |
| **Theme** | Parent `adex360-cannacabana-stationery` (Hello Elementor–based) + child `adex360-canna-cabana-stationery-child` |
| **Page builder** | **Elementor + Elementor Pro** |
| **Email** | **WP Mail SMTP**; plugin also configures outbound mail from `no-reply@ccsupplies.ca` |
| **Caching / host artifacts** | Breeze, LiteSpeed-related files present in backup; production historically on Cloudways (from backup header) |
| **Backup** | UpdraftPlus |
| **Spam** | Akismet installed |
| **Forms** | WPForms Lite installed (**usage on live flows could not be confirmed** as primary checkout path) |
| **SEO plugin remnants** | Yoast meta keys on some pages |
| **Payments present but unused in employee flow** | WooPayments / PayPal settings exist, **not** the active employee checkout path |
| **Analytics** | WooCommerce analytics feature flags on; **external analytics (GA/Pixel) not confirmed** |
| **Reviews platform** | **Not found** (native WC rating sort only) |
| **Shipping integrations** | **Not found** (shipping disabled in portal logic) |
| **Experimental child-theme code** | Amazon Business / Grand & Toy PO test helpers exist in child theme—**not confirmed as live production order path** |

---

## 13. Important Pages

| Page | Purpose | Important Content/Functionality |
| ---- | ------- | ------------------------------- |
| `/my-account/` | Login + account hub | Gate to entire store; account menu after login |
| `/` (Home) | Logged-in landing | Shop Now, categories, most-ordered strip |
| `/shop/` | Full catalog | 120 products, sort, category filter, pagination |
| `/all-categories/` | Category index | Product categories shortcode grid |
| `/most-ordered-products/` | Featured list page | “Most ordered” marketing section (static page; not a deep analytics report) |
| `/product-category/{slug}/` | Category PLP | Filtered products |
| `/product/{slug}/` | Product detail | Price, ATC |
| `/cart-2/` | Cart | Items + quota/budget banner |
| `/checkout-2/` | Requisition checkout | Store summary, notes, Internal Requisition, Place order |
| `/my-account/profile/` | Store profile | Assigned location + limits |
| `/my-account/orders/` | Order history | Requisition statuses |
| `/manager/*` | Ops portal | Dashboard, orders, products, vendors, stores, users |
| `/about/`, `/contact-us/`, `/customer-help/` | Marketing/help shells | Mostly placeholder content |
| `/privacy-policy/` | Privacy | Draft / incomplete |
| `/returns-and-exchanges/`, `/shipping-and-tracking/` | Policy shells | Empty / incomplete |

---

## 14. Complete Store Map

```text
Canna Cabana Internal Supply Store (ccsupplies.ca)
├── Public
│   └── My Account / Login (only accessible surface when logged out)
│
├── Employee area (login required)
│   ├── Homepage
│   │   ├── Hero → Shop
│   │   ├── Categories (8)
│   │   └── Most ordered items
│   ├── Shop (all products)
│   │   ├── BATTERIES
│   │   ├── CLEANING/BREAKROOM
│   │   ├── EPSON INK
│   │   ├── HP INK
│   │   ├── LEXMARK INK
│   │   ├── OFFICE SUPPLIES
│   │   ├── OTHER
│   │   ├── PAPER PRODUCTS
│   │   └── Uncategorized
│   │       └── Product detail → Add to cart
│   ├── All Categories
│   ├── Most Ordered Products page
│   ├── Search Results
│   ├── Cart
│   ├── Checkout (Internal Requisition)
│   ├── My Account
│   │   ├── Profile (store + quota/budget)
│   │   ├── Orders
│   │   ├── Explore products → Shop
│   │   ├── Account Details (read-only)
│   │   └── Logout
│   └── Footer contact (info@cannacabana.com)
│
├── Manager portal (/manager)
│   ├── Dashboard (KPIs, vendors, budgets)
│   ├── Orders (approve/reject, notes, shipment/receiving)
│   ├── Products (CRUD + export)
│   ├── Vendors
│   ├── Stores (~223 locations)
│   └── Users (store employees + assignment)
│
├── Policies / info pages (incomplete)
│   ├── About, Contact, Customer Help (placeholder copy)
│   ├── Returns, Shipping (empty)
│   └── Privacy (draft)
│
└── WP Admin (admins; managers largely redirected to /manager)
    ├── WooCommerce → Internal Supplies settings
    ├── Store Locations CPT
    ├── Email Logs
    └── Catalog / orders / users
```

---

## 15. Key Takeaways

1. This is an **internal stationery/supply requisition portal**, not a public cannabis or consumer e-commerce storefront.  
2. Access is **login-only**; self-registration is off.  
3. Shoppers are **store employees** tied to **one physical store location**.  
4. Catalog is ~**120 simple products** in **8 supply categories** (office, cleaning, inks, paper, batteries, other).  
5. Currency is **CAD**; prices track budget usage even though no card is charged.  
6. Checkout method is **Internal Requisition** (approval workflow, no payment capture).  
7. Each store has a **monthly order quota** and **monthly budget**.  
8. Orders go to **awaiting-approval** until a manager **approves or rejects**.  
9. Shipping/coupons/address editing are intentionally removed from the employee checkout UX.  
10. **~223 store locations** are configured as a custom post type.  
11. Managers use **`/manager/`** for orders, products, stores, users, vendors, and dashboard metrics.  
12. Vendors (e.g. Grand & Toy) support **sourcing/notifications**, not the primary shopper taxonomy UX.  
13. Platform stack: **WordPress + WooCommerce + Elementor + WCSS plugin**.  
14. Branding is **Canna Cabana / High Tide Inc.** applied to internal ops.  
15. Marketing/policy pages are largely **unfinished placeholders**—do not treat them as authoritative.  
16. Homepage “most ordered” is a **curated page section**, not a confirmed live analytics engine.  
17. Some catalog hygiene issues exist (placeholder product, missing images, typos).  
18. Real business rules live in **WCSS settings + store meta + approval statuses**, not in the empty policy pages.

---

## 16. Unknown / Could Not Determine

- Exact live production SMTP credentials/ops process beyond plugin configuration (do not document secrets).  
- Whether Amazon Business / Grand & Toy child-theme integrations are used in day-to-day fulfillment (code exists; not confirmed live).  
- Full end-to-end physical fulfillment SLA (who ships, carrier, lead times)—**not documented** on usable policy pages.  
- Real return/refund policy content — **Not found**.  
- Production privacy/terms legal text — **Not found** (draft/placeholder only).  
- Whether every store employee account is actively used vs dormant.  
- External marketing pixels / GA4 property IDs — **Could not determine**.  
- Whether “Most Ordered Products” is manually curated only vs driven by order data — page exists; deep ranking logic **not confirmed**.  
- Public-catalog mode (`visibility_mode`) exists in settings but front-end still forces login—effective behavior is private.

---

*Document generated from production backup + live gated site inspection. Prefer this file over unfinished About/Help page copy when explaining how the store actually works.*
