# MOM email — Amazon ordering demo (ccsupplies.ca)

Copy/paste ready. Adjust To / Cc and meeting date if needed.

---

**Subject:** MOM — Amazon Business ordering demo on ccsupplies.ca (Trial Mode)

**To:** [Amazon / AWS team + High Tide attendees]  
**Cc:** [Internal]  
**From:** Usman Zahid  

---

Hi all,

Please find below the minutes from yesterday’s live demo of Amazon Business ordering on **ccsupplies.ca**. Everything shown was on production with **Trial Mode** on. We did **not** place a live (non-trial) Amazon order.

### 1. End-to-end flow (demoed live)

1. **Store owner** shops the WooCommerce catalog and checks out with Internal Requisition → order status **Awaiting Approval**.
2. **Manager** reviews the order on `/manager/orders` and clicks **Approve** or **Reject**.  
   - Approve only updates the store order status. It does **not** call Amazon.
3. After **Approved**, the manager runs **Amazon Test Order** → Amazon catalog → Search Amazon → Add to Amazon Cart → Amazon checkout.
4. On checkout: enter address → **Calculate Shipping** → system checks approval, **CAD 120** monthly cap, and **5%** price tolerance. **Place Amazon Order** stays disabled until all three pass.

We use one shared Amazon Business cart for the site. The cart total on checkout is the Amazon cart; the monthly cap uses the store order goods total. Those amounts are different on purpose.

### 2. Store owner vs manager (roles live-checked)

| | Store owner | Manager |
| --- | --- | --- |
| Role | Store Employee | Shop manager (manager portal) |
| Does | Shop, Woo cart, place store requisition | Approve / Reject, Amazon catalog / cart / checkout |
| Amazon place | No | Yes, only after Approve and gates pass (Trial Mode) |

- Store owner cannot open the manager portal or place on Amazon.
- Manager can Approve/Reject and complete the Amazon path (search → cart → shipping → Place when allowed).

### 3. Validation / testing covered

Live checks included:

- **Approval gate** — Place stays off while status is Awaiting Approval (e.g. order #8211).
- **CAD 120 monthly cap** — over-cap order keeps Place off (e.g. #8202 Shawnessy, CAD 133.51); under-cap orders can proceed only if price check also passes.
- **5% price check** — if Amazon unit price is more than 5% above the linked store price, Place stays off; cheaper Amazon is allowed.
- **Rejection** — Reject updates the store order and notifies the store (and Operations when the Operations email is configured). Reject does not call Amazon.
- Search, cart, and shipping still work when a gate fails; only Place is blocked.
- Nothing is auto-approved under CAD 120.

### 4. Amazon APIs (technical Q&A)

How the integration works today:

| Step | API |
| --- | --- |
| Auth | Login with Amazon — refresh token → access token |
| Product match | Product Search `2020-08-26` with **keywords** (not `getProductsByAsins`) |
| Cart | Cart API `2025-04-30` (add / update / read items) |
| Shipping / tax | `totalPurchaseCostEstimations` |
| Place | Ordering API `2022-10-30`, with **TrialMode** attribute while Trial Mode is on |

Host: `na.business-api.amazon.com`. Region CA. Catalog starts from our published store products; Search Amazon returns the top keyword match.

**API chain:** refresh token → Product Search → Cart → cost estimate → Ordering POST only if approval + CAD 120 + 5% all pass.

### 5. Security and API monitoring

- Amazon credentials (client id / secret / refresh token) stay on the server; not exposed to the browser.
- Access token sent only on outbound Amazon calls (`x-amz-access-token`).
- Manager portal and Amazon catalog search are capability-gated; store owners are kept on My Account / shop.
- WordPress nonces on cart, shipping, and checkout posts.
- Business rules (approval, CAD 120, 5%) enforced server-side before Ordering API.
- Monitoring today: Amazon `x-amzn-requestid` on responses, order meta snapshot (`_wcss_amazon_order_data`), manager Amazon order view, WooCommerce email logs. This is not a separate CloudWatch/SIEM UI inside ccsupplies; Amazon retains request IDs on their side for support.

### 6. Still out of scope (confirmed)

- Ink / spend split outside the CAD 120 cap (separate High Tide decision).
- Package tracking after the first real (non-trial) Amazon order.
- Returns UI (Operations process).
- Concur monthly invoice (Accounts Payable).

### 7. Next steps — please advise

From our side, the demoed flow (store owner → manager Approve → Amazon search / cart / checkout with Trial Mode, CAD 120, and 5% checks) is working on production as shown. We are **not** making further product changes until we hear back from you.

**Please confirm / tell us:**

1. **Any changes or fixes required?** After the live demo and API/security walkthrough, do you need any updates to the integration (Product Search keywords, Cart, Ordering, Trial Mode, headers, error handling, or monitoring)? If yes, please list them so we can schedule the work.
2. **Is the current API approach accepted?** Keywords-based Product Search (not getProductsByAsins), shared Cart `2025-04-30`, Ordering `2022-10-30` with TrialMode, LWA token auth — any concern or recommended change?
3. **Security / monitoring** — is capturing `x-amzn-requestid` + order meta snapshots enough for your support path, or do you require additional logging / fields?
4. **Go-live** — when can we turn **Trial Mode off** and place the first real (non-trial) Amazon order? Who signs off on High Tide / Amazon side?
5. **Anything else blocking production use** that we should fix before go-live?

**On our side until you reply:** Trial Mode stays on; no non-trial Place; no further Amazon-path code changes unless you request them.

Happy to schedule a short follow-up once we have your list of changes (if any) or go-live criteria.

Thanks,  
Usman Zahid  
Basecamp Consulting / High Tide — ccsupplies.ca
