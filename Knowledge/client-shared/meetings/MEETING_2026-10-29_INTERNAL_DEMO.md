# Internal demo — Amazon & Grand & Toy

**When:** 29 October 2026, 5:59 PM UTC (about 42 minutes)  
**Source PDF:** [Internal Demo of Amazon and Grand Toy 2026-10-29.pdf](./Internal%20Demo%20of%20Amazon%20and%20Grand%20Toy%202026-10-29.pdf)  
**Searchable transcript:** [Internal Demo of Amazon and Grand Toy 2026-10-29 transcript.txt](./Internal%20Demo%20of%20Amazon%20and%20Grand%20Toy%202026-10-29%20transcript.txt)

**On the call:** Usman Zahid (demo), Muhammad Basit Munir, Bhaumik Dhandhukia, Aman Sood, Chris Bilton, Alyshia Digiovannantonio, Muhammad Moiz.

**Ops approvers named (not all on the call):** Alyshia, Hillel, Chris. Aman asked to stop calling this screen “Manager Dashboard” because the company has many managers. Preferred name: **Supply Manager** or **Approving Manager**.

**Follow-up demo they booked:** next Wednesday after 29 Oct = **4 November 2026**, one hour. Aman is required (Chris to check calendar with Harleen). Bhaumik and Basit must QA internally before that demo. Copy Aman on decision emails until a decision is made.

**Implementation plans (canonical):**

- Full task list: [`../../plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md`](../../plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md)
- Claude prompt for Supply Manager UI revamp: [`../../plans-audits/CLAUDE_BRIEF_Supply_Manager_Dashboard_Revamp.md`](../../plans-audits/CLAUDE_BRIEF_Supply_Manager_Dashboard_Revamp.md)
- Storefront cheapest price (already in progress): `docs/PLAN-vendor-cheapest-pricing-and-checkout-ux.md`

---

## What the call decided

### Store side (stores must not choose a vendor)

Chris and Aman (repeated from the prior week, then restated for Aman):

- Stores only add to cart and place one requisition. They do not need to know Amazon vs Grand & Toy.
- Hide the vendor dropdown and hide the vendor name on checkout.
- Show one price. That price is the **cheapest** of Amazon and Grand & Toy (Bhaumik: call both, keep the lower price). This was an original requirement.
- Basit: do not hit vendor APIs on every page view. Sync on a schedule (he said daily; local build uses hourly) and read cached prices.
- Stores still need to see **where they are in their budget**. A set catalog price is fine if it stays close to the real vendor price (a few dollars). A $20–$30 swing is not acceptable.
- If the real vendor price is a little cheaper and the store has room left, Ops can call the store and ask if they want to add something. That is an Ops process, not a store screen.
- Move the “budget exceeded” alert to the **top** of checkout (Bhaumik).
- Add a **Back to cart** button. Do not rely on the browser back button (Aman).

### Budget and how often a store can order

- Demo was still using **$160**. Chris: every store is now **$125**. The old tiered budgets are gone. Thermal rolls are auto-shipped and are no longer part of this budget.
- **One order per store per month** (“one and done”). No second order inside the month.
- Ordering is also limited to a **window** that Chris and Alyshia can change in the back end. Example Aman gave: first group of stores may order only from the 1st through the 7th; the next group from the 7th through the 15th.
- A monthly order-count quota already exists on the store record. The **week window** does not.
- If a store is over budget, outside the window, or needs a second/emergency order, do **not** only block them. That case goes to Alyshia. For launch, an out-of-cycle form/email to Alyshia is enough. A full in-system exception flow can wait about a month so it does not hold launch (Aman; Alyshia agreed). Bhaumik noted an email-only path will be missing from future analytics; Aman said either way is fine if it does not delay launch.

### Approval (this replaces the manual approve step for normal orders)

Aman, with Chris and Bhaumik agreeing:

- If the order is inside the window, inside the $125 budget, first order of the month, and the cheapest vendor was already chosen, it is **pre-approved**.
- Do not send those ~235 store orders to Alyshia.
- On auto-approve, **submit straight to that vendor**. Do not make a person pick the vendor again or fill checkout fields by hand. Fields should already be filled.
- The current approve / switch-vendor / manual checkout screens can **stay** for now. Skip them on the happy path. Do not spend the launch window ripping them out (Aman, ~30:39).
- Alyshia approves only: over budget, outside the ordering window, or a second/emergency order.

Basit said an earlier meeting had asked to put the manual approval step back. Aman asked that his original auto-approve request not be changed without telling him. Direction on this call: **roll manual approval back** for in-policy orders.

### After the order is placed

- The store gets a “order submitted” email (already intended).
- Also send a copy to a **supply mailbox that is not monitored**, so there is a record if Grand & Toy later says they never got the PO. Alyshia can search that mailbox. Not built at demo time.
- Stop test emails to Aman (`asood@hightideinc.com`). He was still getting them the morning of the demo from `storeowner.gt.qa@ccsupplies.local` (last one 10:48 a.m.).

---

## Manager dashboard — what exists vs what they asked for

The home screen they were shown is `/manager/` (`frontend/pages/dashboard.php`). Bhaumik walked Aman through it from about 33:00 to 37:30. Aman rejected it as the reporting tool.

### What is on the screen today

| Block | What it shows |
|--------|----------------|
| Pending / Approved / Rejected | Counts for the portal |
| Orders cost (this month) | One total |
| Stores | Total stores, and how many were active this month |
| Top vendors (this month) | Vendor name, order count, revenue |
| Orders trend (last 6 months) | Bar chart of **order counts** by month |
| Store budgets (active this month) | Store name, store id, orders, quota, spend, budget |
| Export CSV | Downloads that same overview (`reports/overview.csv`) |
| Export PDF | Prints that same dashboard (`reports/overview.pdf`) |

There are no date filters, no product filter, no store filter, no user filter, and no custom report builder. Amazon vs Grand & Toy spend is only the small “Top vendors” table, not a report Aman can slice.

### What Aman asked for (two different things)

1. **Analytics** — charts and stats. He said this is separate from reports. The current cards and 6-month bar are a start, and he did not treat them as the main gap.
2. **Reports Ops can pull** — this is the gap. He does not want Alyshia to export a CSV and build a pivot in Excel.

Concrete question he could not answer from the page:

> How much did we order with Grand & Toy this month, and how much with Amazon?

He also asked that Alyshia be able to **build custom reports** and filter, then export to **Excel** (filters, not a PDF snapshot). Bhaumik’s reply on the call:

- Add filters for **product, vendor, store, user, and order fields**.
- Export that filtered data (CSV is already there; they will extend it).
- Vendor spend (Amazon vs Grand & Toy) has to be added on the report side. The PDF export is only a copy of the current dashboard, and Aman said that PDF is not useful.

He framed the scale as about **$50,000 a month** in supplies and about **235 stores**.

### Rename

The nav label **Manager Dashboard** should become **Supply Manager** or **Approving Manager**, so it is not confused with store managers. The people on this screen are Alyshia, Hillel, and Chris.
