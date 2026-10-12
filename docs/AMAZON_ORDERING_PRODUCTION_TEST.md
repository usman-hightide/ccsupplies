# Amazon ordering: tasks, what is done, production test

Site: https://ccsupplies.ca  
Log in as a user who can manage WooCommerce orders.  
Do not turn Trial Mode off. Do not click **Place Amazon Order** during these checks.

The cap uses the store order total (goods only, no tax, no shipping). The amount on the right of checkout is the shared Amazon cart. Those two numbers are not the same.

---

## 1. Tasks received

From Chris Bilton, 8 Oct 2026. Basit said to follow that email.

| Task | Status |
| --- | --- |
| Every order needs manager approval before Amazon. No auto-approve under $120. | Done. Live. |
| Search, cart, and shipping stay available before approval. Approval blocks only Place Amazon Order. | Done. Live. |
| Search Amazon from the published store catalog. | Done. Live. |
| Flat monthly cap of CAD 120 per store. Old per-store budgets are not used. | Done. Live. Not on GitHub yet. |
| 5% price check. If the Amazon price is more than 5% above the store price, Place stays off. | Done locally. Not deployed. |
| Rejection email to the placing store and to Operations. | Not started. |
| Ink (printer cartridges) sits outside the CAD 120 cap. | Not started. Chris said this needs a separate High Tide discussion first. |
| Package tracking. | Not started. Waits for the first real order after Chris signs off. |
| Returns stay with Operations. No Returns API. | No code. |
| Pay by Invoice continues. | Already in use. |
| Preferred pricing. | Chris believes this is already loaded. No code change. |
| Consolidated monthly invoice to Concur. | Not started. |
| Launch by province after development confirms. | Not a code task. |

---

## 2. What is done

1. **Approval.** Place Amazon Order works only when the WooCommerce order status is exactly Approved. Any other status keeps the button off after shipping is calculated.
2. **Catalog search.** The Amazon catalog page lists published store products. Type at least 2 letters to filter that list. **Search Amazon** calls Amazon for that one product and returns the top match. **Add to Amazon Cart** adds it. Drafts and the placeholders "Test Product" and "Product 1" are excluded. Live catalog count on 9 Oct 2026 was 118 products.
3. **CAD 120 cap.** For the store on the order, the check adds this order's goods total to that store's other Approved, Processing, and Completed orders created in the current calendar month. If the total is over CAD 120.00, Place stays off. Search, cart, and shipping still work. An order with no store attached skips the cap.
4. **5% price check.** Each Amazon cart unit price is compared with the linked store product price. Place stays off when the Amazon price is above the store price by more than 5%. A price at or below that limit is allowed. Search, cart, and shipping still work. The item must be added with **Add to Amazon Cart** so the store product is linked to that ASIN.

Files:

- `app/public/wp-content/themes/adex360-canna-cabana-stationery-child/amazon-checkout.php` (approval, cap, and 5% price check)
- `app/public/wp-content/themes/adex360-canna-cabana-stationery-child/amazon-apis-integration.php` (catalog search and ASIN link)

GitHub:

- Approval is on branch `amazon-order-approval-gate`.
- Catalog search is on branch `amazon-catalog-store-search`.
- The CAD 120 cap is on local branch `amazon-monthly-cap` only. It is not committed and not pushed. It is already on production because `amazon-checkout.php` was deployed.
- The 5% price check is in the same local files. It is not on production until both files above are deployed again.

---

## 3. Production test

Use a real Canadian address. This one was used on 10 Oct 2026:

- Full Name: Usman Zahid
- Phone: 416-555-0123
- Address: 100 Queen Street West
- City: Toronto
- Province: Ontario (ON)
- Postal Code: M5H 2N2
- Email is already filled with ecom@hightideinc.com

There is one shared Amazon cart. If checkout says the cart is empty, Calculate Shipping fails and Place stays off for that reason. Add one catalog item first (steps in 3.1).

Read the dollar amount on the page. The figures below were correct on 10 Oct 2026. A newer Approved order for the same store in October changes them.

### 3.1 Catalog search

1. Open https://ccsupplies.ca/amazon-catalog/
2. Confirm the line: "Type at least 2 letters to search the catalog."
3. Type `n`. The list stays hidden.
4. Type `ni`. Store products whose name contains "ni" appear. On 9 Oct 2026 this showed 8 matches.
5. Click **Search Amazon** on one row, for example Hilroy.
6. Confirm a result appears with an ASIN, a CAD price, and **Add to Amazon Cart**. Hilroy's known top match is ASIN B00L2B2C0C. The Amazon title can differ from the store title. That is the one-result search, not a cap bug.
7. Click **Add to Amazon Cart**.
8. Confirm the page says the item was added.
9. Open https://ccsupplies.ca/amazon-cart/
10. Confirm the same item is in the cart.

### 3.2 Order line match

1. Open https://ccsupplies.ca/amazon-catalog/?order-id=8196
2. Confirm the page shows Order #8196 and a match row for each store line on that order.
3. A line with no Amazon match shows no Add button for that line. That is a search miss, not a failed cap.

### 3.3 Under the cap (Place turns on)

Order 8196, store Westbrook, goods total CAD 99.98 on 10 Oct 2026. Status must stay Approved.

1. Open https://ccsupplies.ca/amazon-checkout/?order-id=8196
2. Confirm the cart summary is not empty. If it is empty, go back to 3.1 and add one item.
3. Confirm the note: "This store is at CAD 99.98 of the CAD 120.00 monthly cap with this order." If October spend has changed, the first amount may differ. It must still be CAD 120.00, and it must say "at", not "over".
4. Confirm **Place Amazon Order** is off before shipping is calculated.
5. Fill the address in the list above. Country stays Canada (CA).
6. Click **Calculate Shipping**.
7. Wait until the green line says: "Shipping, tax, and total calculated successfully. You can now place the order."
8. Confirm **Place Amazon Order** is now on (dark, clickable).
9. Stop. Do not click it.

### 3.4 Over the cap (Place stays off)

Order 8202, store Shawnessy, goods total CAD 133.51 on 10 Oct 2026. Status must stay Approved. Use the same cart as 3.3. The right-side Amazon total can stay small, such as CAD 14.38. The cap still uses CAD 133.51.

1. Open https://ccsupplies.ca/amazon-checkout/?order-id=8202
2. Confirm the note names Shawnessy and says the store is over the CAD 120.00 monthly cap, and that this order is CAD 133.51.
3. Confirm the note also says search, cart, and shipping can still be used, and Place stays off until the order fits the cap.
4. Fill the same address.
5. Click **Calculate Shipping**.
6. Wait until the green line repeats the over-cap sentence.
7. Confirm **Place Amazon Order** is still off.
8. Stop. Do not click it.

### 3.5 No store on the order (cap does not run)

Order 8207 has no store attached. Its store-order total is about CAD 188.99, but the cap does not apply.

1. Open https://ccsupplies.ca/amazon-checkout/?order-id=8207
2. Confirm there is no sentence about the CAD 120.00 monthly cap.
3. This order already has a trial Amazon order (Amazon identifier 701-7666983-5405030, placed 9 Oct 2026). Do not place it again.

### 3.6 Approval blocks Place

Do not change order 8196 or 8202 for this check. Setting an order back to Approved sends the normal approval email.

1. Open checkout for an order whose status is not Approved, for example Awaiting Approval: `https://ccsupplies.ca/amazon-checkout/?order-id=ORDER_ID`
2. Confirm the note says the order must be approved before it can be placed on Amazon, and it shows the current status.
3. Fill the address and click **Calculate Shipping**.
4. Confirm shipping still calculates.
5. Confirm the green line says the order still needs approval.
6. Confirm **Place Amazon Order** stays off.

### 3.7 Price within 5% (Place can turn on)

Deploy the local `amazon-checkout.php` and `amazon-apis-integration.php` first. This check is not on production until then. Use an Approved order that is also under the CAD 120 cap, such as order 8196.

1. Open https://ccsupplies.ca/amazon-catalog/
2. Search a store product and click **Search Amazon**, then **Add to Amazon Cart**.
3. Open `https://ccsupplies.ca/amazon-checkout/?order-id=8196`
4. Confirm the note: "Amazon prices are within 5% of the store prices."
5. Fill the address and click **Calculate Shipping**.
6. Confirm **Place Amazon Order** turns on.
7. Stop. Do not click it.

If the Amazon unit price is more than 5% above that store price, the note names the product, both prices, and the 5% limit. **Place Amazon Order** stays off after Calculate Shipping.

### 3.8 What a failed check looks like

- Cart is empty, so Calculate Shipping errors and Place stays off. Add one catalog item and calculate again.
- Two cart items add up to more than CAD 120, but the URL order is under CAD 120. Place still turns on. The cap does not add cart lines.
- The right-side total is under CAD 120 while the note says the store order is over CAD 120. Place stays off. The right-side total is the Amazon cart.
