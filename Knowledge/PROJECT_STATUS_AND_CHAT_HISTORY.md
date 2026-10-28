# PROJECT STATUS & CHAT HISTORY — CC Supplies

**Last updated:** 2026-10-28 (evening, Asia/Karachi)  
**Owner (dev):** Usman Zahid (`uzahid@basecampconsulting.co`) — Basecamp Consulting / High Tide  
**Live:** https://ccsupplies.ca  
**Local:** https://ccsupplies.local  
**GitHub:** https://github.com/usman-hightide/ccsupplies  
**This handoff branch:** `feature/gt-amazon-knowledge-demo-2026-10-28`

---

## 1. What is the current process (production today)

### A. Store employee (Woo requisition)

1. Login → `/my-account/`
2. Shop `/shop/` → cart `/cart-2/` → checkout `/checkout-2/`
3. Payment method **Internal Requisition** → order status **Awaiting Approval**
4. Track at `/my-account/orders/` (Woo status only)

**G&T (store-facing, by design):** store employees **may** use `/gt-catalog/`, `/gt-cart/`, `/gt-checkout/` (Grand & Toy stage cXML). This is **OK**.

**Amazon:** store employees **must not** place Amazon orders. Amazon Search AJAX requires manager caps → 403 for store role.

### B. Manager — Amazon path (unchanged pattern)

1. `/manager/orders/` → Approve Woo order (does **not** call Amazon)
2. **Amazon Test Order** → `/amazon-catalog/?order-id={id}`
3. Search → Add to Amazon cart → `/amazon-cart/` → `/amazon-checkout/`
4. Gates: approval + CAD **$120** monthly cap + **5%** price tolerance + Trial Mode
5. View: `/manager/orders/amazon-view/{id}`

### C. Manager — Grand & Toy path (shipped live Oct 28)

1. `/manager/orders/` → **G&T Test Order** → `/gt-catalog/?order-id={id}`
2. Search store products → Check stock (stage cXML) → Add to G&T cart
3. Cart syncs snapshot to Woo meta (`cart_saved`) → `/manager/orders/gt-view/{id}` shows lines
4. `/gt-checkout/?order-id={id}` → Submit stage PO → status `submitted`, PO id like `TEST…`
5. Final view: `/manager/orders/gt-view/{id}` (Amazon-parity UI: summary cards, badges, shipping, line table)

**Rule (Basit):** Amazon ≠ G&T — separate nav, catalog, cart, checkout, manager views. Do not mix.

---

## 2. Cursor chat history — what we did (this Mac, Oct 27–28)

Primary agent chat: `861f8ed0-150c-4f26-819e-d0adfcdc71f9` (plus earlier Amazon/G&T chats `e670fe40`, `00d148cc`, `36884f80`, etc.)

| When | Work |
|------|------|
| Oct 27 | Checkpoint branch; product audit ASIN vs G&T; separate-dashboards plan; local DB dump |
| Oct 27–28 | Built G&T theme pages: `gt-catalog.php`, `gt-cart.php`, `gt-checkout.php`, `cc-gt-procurement.php` |
| Oct 28 | Manager: G&T nav, `orders-gt-view.php`, REST DTO for G&T, `manager.js` buttons mirroring Amazon |
| Oct 28 live | Deployed; fixed private G&T pages → publish for `store_employee`; soft-nonce for CDN-stale search |
| Oct 28 QA | Live manager G&T E2E on order **#8226** — catalog → cart_saved → stage PO → gt-view **PASS** |
| Oct 28 | Created store QA user **jordan.nguyen** for Woo place-order demos |
| Oct 28 | Created `Knowledge/` + this status doc; feature branch for other-system sync |

---

## 3. Done (shipped / verified)

- [x] Amazon manager path (trial): catalog, cart, checkout gates, amazon-view
- [x] Separate Amazon vs G&T manager UX (Basit non-negotiable)
- [x] G&T catalog / cart / checkout (theme) + soft AJAX nonce for PressCACHE
- [x] G&T pages published (not private) so store employees can open them
- [x] Manager **G&T Test Order** + **G&T order view** with `cart_saved` / `submitted` snapshots
- [x] Live QA manager flow order #8226 (PO `TEST261028171810`, HTTP 200)
- [x] Live QA prior order #8227 submitted snapshot still readable
- [x] Store employee QA user created and linked to Test store `#8177`
- [x] Client Approved Item List CSVs filed under `Knowledge/client-shared/`

---

## 4. Pending / open

| Item | Owner | Notes |
|------|-------|-------|
| Final demo on **other laptop** | Usman | Pull this branch; sync theme/plugin; use Knowledge prompt |
| Cloudways/PressCACHE purge of bare `/gt-catalog/` HTML | Usman / host | Soft-nonce mitigates; purge still recommended |
| Confirm SenderIdentity mapping (store email vs `588`) with Chris | Chris / Usman | Checkout default remains `588` for stage |
| Full multi-sheet Excel from Basit (if more location sheets) | Basit | Partial CSVs received (All Locations, The Well, Thompson, Thunder Bay, Chemical) |
| Amazon **store-wide** release | Jai / Basit gate | Still manager-only unless business overrides |
| G&T production (non-stage) endpoint | G&T / Chris | Stage only until TP production URL |
| Optional: Vendor Compare demote | Usman | Not primary UX per Basit |
| Optional: Scan Now dual-vendor | Basit priority later | Not v1 |

---

## 5. People — who shared what

| Person | Role | What they shared / decided |
|--------|------|----------------------------|
| **Muhammad Basit Munir** | High Tide / product lead | Separate Amazon & G&T dashboards; Approved Item List direction; finish G&T mirror of Amazon UX |
| **Chris Bilton** | Ops / G&T | Call notes (`Call with Chris and 1 other.docx`); SenderIdentity = store emails; BO vs note/Ops; ASIN entry with Alyshia |
| **Alyshia Digiovannantonio** | Ops | ASIN entry in admin for Amazon-buyable items |
| **Jai Radhakrishnan** | Amazon / architecture stakeholder | Recap emails; architecture review — emails drafted in `Knowledge/emails/` |
| **Ed Petrovacki** | Stakeholder (Cc on architecture) | Amazon release process context |
| **Bhaumik Dhandhukia** | Cc on architecture email | — |
| **Usman Zahid** | Developer (Basecamp) | Implementation, live QA, Knowledge pack, demo |
| **Tomas** (list) | ASIN file source | Historical ASIN list used in audit (~112) |

---

## 6. Emails we prepared / received (in Knowledge)

| File | To / purpose |
|------|----------------|
| `emails/EMAIL_TO_JAI_ARCHITECTURE.md` | Jai (+ Cc Ed, Chris, Bhaumik, Basit, Alyshia) — architecture DOCX |
| `emails/EMAIL_TO_JAI_PRODUCT_SEARCH.md` | Jai — product search clarifications |
| `emails/AMAZON_MEETING_MOM_EMAIL.md` | Amazon/AWS + HT — MOM of trial-mode demo |

Do not treat draft bodies as proof of send; they are copy/paste templates used in the project.

---

## 7. Data / files received from client

### Approved item lists (Basit / Ops) — `client-shared/approved-item-lists/`

- `Approved Item List 1(All Locations).csv`
- `Approved Item List 1(The Well).csv`
- `Approved Item List 1(Thompson (NCN)).csv`
- `Approved Item List 1(Thunder Bay).csv`
- `Approved Item List 1(Store Use Chemical Project).csv`

### ASIN / product sheets — `client-shared/asin-sheet/`

- High Tide Product List.xlsx
- Amazon Already Linked / Missing SKU ASIN CSVs
- SAFE_ASIN_MAP_BY_SKU.csv, audit CSVs, apply SQL, notes for Chris/Basit

### Meetings

- `client-shared/meetings/Call with Chris and 1 other.docx`

### Plans written by us (from Basit/Chris direction)

- `plans-audits/PLAN_Separate_Amazon_GT_Dashboards_2026-10-27.md`
- `plans-audits/FINAL_PRE_IMPLEMENTATION_DECISION_Amazon_GT_2026-10-27.md`
- `plans-audits/AUDIT_Amazon_GT_Product_Integration_2026-10-27.md`

---

## 8. Flows cheat-sheet (demo)

### Demo 1 — Store places Woo order

1. Incognito → https://ccsupplies.ca/my-account/
2. Login **jordan.nguyen** / `CcStoreOrder26!`
3. Add product → checkout Internal Requisition
4. Note order number → logout

### Demo 2 — Manager Amazon Test Order

1. Manager login → `/manager/orders/`
2. Approve order → **Amazon Test Order**
3. Search / cart / checkout (Trial Mode) → amazon-view

### Demo 3 — Manager G&T Test Order

1. Same orders grid → **G&T Test Order**
2. Search e.g. Epson → Check stock → Add to G&T cart
3. Open **G&T order view** → should show `cart_saved`
4. Checkout: Deliver to = person; Province = **ON** (not city); SenderIdentity leave **588** for stage
5. Submit → gt-view `submitted` + Stage PO Yes

### Demo 4 — Store G&T browse (optional)

1. As jordan.nguyen open `/gt-catalog/`
2. Search works (soft nonce) — expected **yes**
3. `/amazon-catalog/` search should **fail/403** — expected

---

## 9. QA accounts (live)

| Account | Password | Role | Store |
|---------|----------|------|-------|
| `jordan.nguyen` | `CcStoreOrder26!` | store_employee | Test store `#8177` |
| `storeowner.gt.qa` | `StoreTest123!` | store_employee (earlier QA) | `#8177` |
| Manager | Usman’s admin / shop_manager session | portal | — |

**Never commit production Amazon/G&T API secrets.** Names only: `AMAZON_*`, `CC_GT_*` in `wp-config.php`.

---

## 10. Key code paths (this branch)

### Theme (`adex360-canna-cabana-stationery-child/`)

- `cc-gt-procurement.php` — G&T AJAX, soft nonce, cart snapshot merge to order
- `gt-catalog.php` / `gt-cart.php` / `gt-checkout.php`
- `amazon-apis-integration.php` — Amazon path (leave intact)
- `functions.php` — includes G&T module

### Plugin (`wcss-internal-supplies/`)

- `frontend/assets/js/manager.js` — Amazon + G&T Test Order / view buttons
- `frontend/pages/orders-gt-view.php` + `orders-amazon-view.php` + shared `partials/orders-vendor-view-styles.php`
- `frontend/pages/partials/manager-nav.php` — Grand & Toy link
- `frontend/routes/class-routes-manager.php` — gt-view route
- `includes/rest/class-rest-orders.php` — G&T DTO fields

---

## 11. Cursor agent transcripts (local Cursor only)

Not in git. On this Mac under Cursor projects `agent-transcripts/`:

- `861f8ed0-…` — G&T separation + live QA + Knowledge (current)
- Older: `e670fe40`, `00d148cc`, `36884f80`, `5ed8adf4`, `d5810324`

Other system should rely on **this Knowledge folder**, not transcript IDs.

---

## 12. Definition of “demo ready”

- Store can place Woo requisition with jordan.nguyen  
- Manager can Amazon Test Order (trial) on approved order  
- Manager can G&T Test Order → cart_saved → stage PO → gt-view  
- Store has G&T catalog access; **no** Amazon place path  
- Knowledge pack + this status file present on the branch  

**Status as of 2026-10-28 evening:** Demo-ready on live for G&T + Amazon manager paths; pull this branch on the other system before the final demo.