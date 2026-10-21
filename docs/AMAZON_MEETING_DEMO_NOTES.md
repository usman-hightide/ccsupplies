# Meeting demo notes — Amazon ordering on ccsupplies.ca

Notes from the live demo walkthrough on production (Trial Mode on). Answers match code and what was checked live on ccsupplies.ca. Do not press **Place Amazon Order** unless a trial placement is intentional.

Related step-by-step: [AMAZON_ORDERING_PRODUCTION_TEST.md](AMAZON_ORDERING_PRODUCTION_TEST.md).

**Re-checked live 15 October 2026** (manager as Usman Zahid; store owner as `demostore` / Demo Store). Meeting walkthrough covered flow testing, manager vs store owner, validation (cap / approval / 5%), and Amazon team API + security questions.

---

## End-to-end flow (short)

1. **Store owner** logs in → shops WooCommerce catalog → checks out with Internal Requisition → order is **Awaiting Approval**.
2. **Manager** opens `/manager/orders` → **Approve** or **Reject**. Approve only changes WooCommerce status. It does **not** call Amazon.
3. After **Approved**, manager clicks **Amazon Test Order** → Amazon catalog (`?order-id={id}`) → Search Amazon → Add to Amazon Cart → Amazon cart → Amazon checkout.
4. On checkout: address → **Calculate Shipping** → gates run (approval, CAD 120 cap, 5% price). **Place Amazon Order** stays off until all three pass. Trial Mode stays on (hidden checked) so any place is a trial order.

One shared Amazon Business cart for the whole site (`cart-wxXmutF5kiXGRb9J`). Right-side total = Amazon cart. Monthly cap = store order goods total. Those numbers differ on purpose.

---

## Manager vs store owner (live in the meeting)

| | Store owner | Manager |
| --- | --- | --- |
| WordPress role | `store_employee` (Store Employee) | `shop_manager` (needs `wcss_manage_portal`) |
| Where they work | Shop / My Account | `/manager/` dashboard |
| What they do | Browse products, Woo cart, place store requisition | Approve / Reject, products, stores, users, Amazon path |
| Order they create | **Awaiting Approval** | Changes status to **Approved** or **Rejected** |
| Amazon catalog / cart / Place | No (intended path) | Yes, after Approve |
| Cap / 5% / Trial Mode | No | Yes, on Amazon checkout |
| Store assignment | Required before checkout (`_wcss_store_id`) | Manages store ↔ employee mapping |

### Store owner flow — what they can do

1. Log in at ccsupplies.ca (role Store Employee).
2. Must be assigned to a store location; otherwise Woo checkout errors.
3. Browse shop → add to WooCommerce cart → checkout with Internal Requisition (no card payment).
4. Order lands as **Awaiting Approval**.
5. Wait for manager Approve or Reject; status emails follow the portal path.
6. After Approve, Amazon sourcing is manager-side. The store owner does **not** place on Amazon.

**Store owner can:** log in, browse published products, Woo cart, place requisition (with store assigned), see own orders, get status emails.

**Store owner cannot:** open manager portal, Approve/Reject, run Amazon catalog search AJAX, or Place Amazon Order on the manager path.

**Live check 15 Oct 2026:** logged in as Demo Store (`demostore`, user ID 241). Opening `/manager/` redirected back to `/my-account/`. Account shows Profile / Orders / Explore products only — no manager nav, no Amazon catalog link.

### Manager flow — Amazon order (what they can do)

1. `/manager/` → Orders (nav also has Amazon catalog).
2. **Approve** (or **Reject** with note → store + Operations emails when Operations email is set).
3. **Amazon Test Order** → catalog with `order-id`.
4. Filter store products (2+ letters) → **Search Amazon** (keyword Product Search) → **Add to Amazon Cart**.
5. Cart: qty plus/minus (immediate update).
6. Checkout: address → Calculate Shipping → Payment / Monthly cap / Price check → Place only if gates clear (Trial Mode).

**Live check 15 Oct 2026:** `/manager/orders` showed **Approve**, **Reject**, **Amazon Test Order**, and **Amazon order view** on rows. Dashboard nav includes **Amazon catalog**. Approve does not place on Amazon; Amazon starts only from Amazon Test Order / checkout.

---

## Live validation tests (cap, approval, price)

Checked on production with Trial Mode on. Stop before Place unless a trial place is planned.

| Test | Order | What was shown (live) | Place Amazon Order |
| --- | --- | --- | --- |
| Approval gate | **#8211** Awaiting Approval · CAD 129.33 | “must be approved… Current status: Awaiting Approval” (15 Oct) | Off |
| Over CAD 120 | **#8202** Approved · Shawnessy · CAD 133.51 | Monthly cap: over CAD 120.00; this order CAD 133.51 (15 Oct) | Off |
| Under CAD 120 | **#8196** Approved · Westbrook · CAD 99.98 | Cap under CAD 120.00 | Off until 5% also passes |
| 5% price fail | **#8196** with Neenah in shared cart | Amazon above store × 1.05 | Off |
| Under cap + within 5% | **#8196** / cart with Unison Cyan | Cap under + “Amazon prices are within 5%” | Can enable after Calculate Shipping — stop there |
| No store on order | **#8210** Approved, no store | Cap skipped (no Monthly cap card for CAD 120) | Cap not applied |
| Rejection notices | **#8209** Rejected | Store + Operations rejection email; Reject does not call Amazon | N/A |

**Also live 15 Oct:** `#8211` checkout showed Unison Cyan in the shared cart and “Amazon prices are within 5% of the store prices,” while Place stayed off solely for approval. `#8202` Place stayed off for the CAD 120 cap even with prices within 5%.

Rules enforced in PHP before Ordering API:

- Status must be exactly **approved**.
- Store monthly goods total (this order + Approved / Processing / Completed in the calendar month) ≤ **CAD 120**. Tax and shipping are not in the cap. No store on the order → cap skipped.
- Every cart line linked by ASIN ≤ store unit price × **1.05**. Cheaper Amazon is allowed. Unlinked lines fail.
- Search, cart, and shipping still work when a gate fails. Only Place is blocked.
- Nothing is auto-approved under CAD 120.

---

## What the Amazon / AWS team asked (technical)

### Which APIs are used

Not `getProductsByAsins`. Catalog matching uses Product Search with **keywords** (store product title → top match).

| Step | API | Endpoint |
| --- | --- | --- |
| Auth | Login with Amazon (refresh token) | `POST https://api.amazon.com/auth/o2/token` |
| Product search | Product Search 2020-08-26 | `GET https://na.business-api.amazon.com/products/2020-08-26/products` (`keywords`, `productRegion=CA`, `locale`, `facets`, `pageSize`, `category=OFFICE_PRODUCTS`) |
| Cart create / add / update / read | Cart 2025-04-30 | `…/cart/2025-04-30/carts` and `…/carts/{cartId}/items` |
| Shipping / tax estimate | Cart cost estimate | `POST …/carts/{cartId}/totalPurchaseCostEstimations` |
| Place order | Ordering 2022-10-30 | `POST …/ordering/2022-10-30/orders` with `OrderAttributes` including **TrialMode** while trial is on |

Region host: `na.business-api.amazon.com`. Business user header: `x-amz-user-email` (`ecom@hightideinc.com`). Access token header: `x-amz-access-token`. No SigV4 request signing in our code — LWA token auth only.

### How the API chain works

```
Refresh token → access token
    → Product Search (keywords from store product title)
    → Cart add/update item (shared cart ID)
    → totalPurchaseCostEstimations (address → shipping/tax)
    → Ordering POST only if approval + CAD 120 + 5% all pass
```

Approve on the manager page never hits Amazon. Only Place on Amazon checkout calls Ordering, and only after the three server-side gates.

### Security (what is in place)

- **Credentials:** LWA `client_id`, `client_secret`, and `refresh_token` in server config constants. Not sent to the browser. Access tokens are requested server-side and used only on outbound Amazon calls as `x-amz-access-token`.
- **Who can call Amazon search from the site:** catalog search AJAX requires logged-in + `manage_woocommerce` or `manage_options`. Manager portal requires `wcss_manage_portal` or `manage_options`. Store employees are redirected away from `/manager/`.
- **Request safety:** WordPress nonces on cart, shipping, and checkout posts (`amazon_cart_nonce`, `amazon_shipping_nonce`, `amazon_checkout_nonce`). Catalog search uses `check_ajax_referer`.
- **Business gates before Ordering:** approval status, CAD 120 monthly cap, 5% price check — enforced in PHP before Ordering POST, not only by disabling the UI button.
- **Trial Mode:** checkout sends `attributeType: TrialMode` so Amazon treats the place as a trial order until go-live.
- **Store checkout guard:** store employee must have a valid `_wcss_store_id` before placing a Woo requisition.

### Security monitoring of APIs

What we capture today:

- Amazon **`x-amzn-requestid`** on cart, estimate, and order responses — shown on checkout when present and stored with the place attempt.
- WooCommerce order meta **`_wcss_amazon_order_data`**: cart snapshot, estimate totals, place result, status (`checkout_ready` / `placed`), timestamps, Amazon identifiers when returned.
- Manager **Amazon order view** (`/manager/orders/amazon-view/{id}/`) for ops review of that snapshot.
- WooCommerce **Email Logs** for approval / rejection / operations notices.

What this is not: an in-app CloudWatch or SIEM dashboard on ccsupplies. Support monitoring is order meta + Amazon request IDs + email logs. Amazon retains API request IDs on their side for their support path.

---

## Still out of scope (confirmed in build)

- Ink split outside the CAD 120 cap (needs separate High Tide decision).
- Package tracking after first real (non-trial) Amazon order.
- Returns UI (Operations process).
- Concur monthly invoice (Accounts Payable / Chris).
