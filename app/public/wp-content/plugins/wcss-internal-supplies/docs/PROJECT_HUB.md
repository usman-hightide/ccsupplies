# WCSS Internal Supplies - Project Hub

This document tracks all major parts of the plugin, their purposes, and development status.

---

## 📂 frontend

Contains the **UI (views)**, CSS/JS, and route handling for the Store Manager dashboard.

- **assets/css/manager.css** → Styling for the manager dashboard
- **assets/js/manager.js** → JS logic for navigation, CRUD forms, pagination
- **pages/** → All custom pages for the dashboard
  - `dashboard.php` → Store Manager landing/dashboard
  - `products.php` → Product listing w/ pagination
  - `products-create.php` → Product create form
  - `products-edit.php` → Product edit form
- **routes/class-routes-manager.php** → Custom rewrite rules for `/manager` and routing logic

✅ Status: **Mostly working** — pagination pending refinement, product create redirect/success in progress.  
🔜 Next: Add navigation bar (logo + logout), polish CRUD UI.

---

## 📂 includes/rest

Contains all REST API endpoints.

- **class-rest-orders.php** → CRUD + status updates for orders
- **class-rest-products.php** → CRUD for products, vendors, categories
- **class-rest-stores.php** → CRUD for store entities
- **class-rest-ledger.php** → Budget/ledger API

✅ Status: APIs registered and working for orders, products, stores, ledger.  
🔜 Next: refine product meta (vendors/categories), unify error handling, pagination stable.

---

## 📂 includes (core logic)

- **class-activator.php** → Setup logic on plugin activation (tables, flush rules)
- **class-admin-menu-restrict.php** → Restricts WP Admin menus for shop managers
- **class-private-portal.php** → Forces shop managers into custom dashboard instead of WP-Admin
- **class-approval-workflow.php** → Approval/rejection workflow for orders
- **class-order-statuses.php** → Registers custom order statuses (Approved/Rejected/etc)
- **class-user-store-map.php** → Mapping users to stores

✅ Status: Working but needs cleanup of legacy functions.  
🔜 Next: Verify **capabilities (`wcss_manage_portal`)** are consistent across all files.

---

## 📂 docs

- `PROJECT_HUB.md` → You are here. Central project tracking file.

---

## 🗂️ Pending Tasks

- [ ] Fix pagination JS hook → load next/prev correctly
- [ ] Success message + redirect after product create
- [ ] Navigation bar with **site logo** + logout button
- [ ] Vendor dropdown + “Add new vendor” in product form
- [ ] CRUD for Stores (frontend form + REST already in place)
- [ ] Reporting (budget utilization, vendor comparison, etc)
- [ ] Final branding (colors, logo, typography)

---

## 🧹 Cleanup Notes

- Old unused functions inside `class-rest-products.php` (commented list/read versions) can be deleted once new listing is stable.
- Double enqueue in `class-routes-manager.php` (`wp_localize_script` repeated twice).
- Some junk logging (`wc_get_logger`) should be replaced with centralized error handler.

git add .
git commit -m "✨ Store CRUD: Completed full Create, Read, Update, Delete flow with UI integration, pagination, and flash messaging

- Added store listing, create, edit, and delete pages
- Integrated REST endpoints with validation and error handling
- Connected frontend forms to backend REST API
- Added success/error flash messages for actions
- Improved CSS styling for listing and forms
- Verified navigation and routing for stores under /manager/stores"

- Added full REST CRUD for Orders (list, read, update status, patch, add note)
- Integrated store mapping (store_id + store_name fallback from title/code)
- Added ledger data (quota, budget, used/remaining orders & amounts)
- Fixed undefined variable warnings for store_id and store_name
- Updated orders_read() to include order items with SKU and variation info
- Enhanced frontend order view with SKU display and styled status badges
- Improved order status update logic (approve/reject) with page refresh & flash messages
- Verified pagination, date filters, and status filters on order list
- General JS refactor to use jQuery-safe syntax and consistent naming

- REST: add reports overview endpoint (class-rest-reports.php::dashboard)
  - Monthly counts by status
  - Sales summary (orders + revenue)
  - Active/total stores
  - Trend (last 6 months)
  - Top vendors (orders + revenue)
  - Store ledger for current month (store name, orders, spend, quota/budget/usage)
- Products REST/DTO: vendor object and vendor_ids carried through
- Vendors: popup create-from-edit (basic validation, inline success/error)
- Frontend (manager.js):
  - initDashboard(): renders cards, top vendors table, 6-month trend bars,
    and store budgets table (uses store titles)
  - Shows counts, revenue with currency, and handles refresh
  - Trend bars now overlay order counts
- CSS (manager.css):
  - Minor styles for trend bar value overlay and dashboard blocks
- Routing/Views:
  - dashboard.php wired to initDashboard (no class name changes)
- Fixes:
  - Guarded null/empty arrays in render paths
  - Kept existing classes/markup; only added IDs where needed

store-employee-2
store2

-- updated user-name for git

-- user attached with stores only one user with one store..
-- edit store also updated..
-- meta field name space update for stores
-- add 404 page for manager only
-- restrict user to place order if not attached to any store.

      -- Future updates required --

-- notifications to vendor and store users/customer and /manager/ as well for different triggers
-- crud for users and vendors if required

- Users
  • Users page scaffold + styles (table, modals, actions)
  • List store employees with current store assignment
  • Create Store Employee modal with client-side validation
  • Assign Store modal shows stores, disables ones already assigned (except current)
  • Render 'Not Assigned' when user has no store

- Stores REST
  • Align create/update/DTO with Store CPT meta keys
  • Enforce one-store-per-user rule on create & update
  • Keep existing meta keys stable to avoid breaking checkout/budget logic

- Manager shell / 404
  • Use plugin shell to render /manager/404 with plugin CSS/JS
  • Ensure manager context (WCSSM) is injected

- UI polish
  • Minor table + modal CSS
  • Flash messaging consistency
  "
