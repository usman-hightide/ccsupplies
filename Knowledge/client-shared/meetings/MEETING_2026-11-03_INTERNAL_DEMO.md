# Internal demo — Supply Manager (post–29 Oct build)

**When:** 3 November 2026 (~23 minutes)  
**Source:** [`docs/Internal Demo.pdf`](../../../docs/Internal%20Demo.pdf)  
**On the call:** Usman Zahid, Bhaumik Dhandhukia, Basit Munir, Moiz  

**Follow-ups:** [PLAN_After_Internal_Demo_2026-11-03.md](../../../docs/PLAN_After_Internal_Demo_2026-11-03.md) · [DEMO_SCRIPT_2026-11-04.md](../../DEMO_SCRIPT_2026-11-04.md) · [IMPLEMENTATION_GUIDE_2026-11-02.md](../../IMPLEMENTATION_GUIDE_2026-11-02.md)

**Deadline agreed on the call:** **Thursday 5 November** — ready for UAT (Bhaumik informs Fiaz), then production after Salman QA + Moiz + Chris.

---

## What was shown / approved

| Area | Feedback |
|------|----------|
| Dashboard | Period filters, KPIs, Amazon vs G&T, coverage — approved |
| Reports | Tabs, filters, presets — approved |
| Excel / PDF export | **"Beautiful, this is great"** |
| Policy + auto-approve path | Direction confirmed: in-policy → auto; exceptions → Supply Ops |
| Ordering policy UI | Needs polish (see Usman tasks below) |

---

## Decisions / direction from the call

1. **Out-of-policy is not a hard stop for launch.** Default remains *send for Supply Ops approval*. Block-at-checkout exists but is not the recommended demo/launch mode.
2. **Store must understand the exception path before checkout** — confirm pop-up when over budget / over order limit / outside window; wording that approval happens first, then supplier.
3. **Cart limit UI must be simple** — one short line, not a usage table.
4. **Loader / thank-you must not say “sending to supplier”** when the order still needs approval.
5. **Ordering policy = one place** — pre-filled fields; apply limits from the same pop-up (bottom), not a second button.
6. **Access cleanup** — Haroon (Adex) off emails and access; Aman already off local shop_managers (confirm production).
7. **Live data before UAT** — $125 + 1 order on all stores; window example 7–14 (Ops can change).
8. **Release path:** Usman finishes → Salman QA → Moiz approves → inform Chris → production → client UAT. Moiz owns vendor/Amazon calls going forward; weekly Amazon calls to close after the next pointer call.

---

## Usman tasks (status as of 4 Nov)

| # | Task | Status |
|---|------|--------|
| 1 | Over-budget / over-limit **confirm pop-up** before checkout | ✅ Local |
| 2 | **One-line** limit banner (cart) | ✅ Local |
| 3 | Place-order loader / thank-you: **“Sending for approval”** when out of policy | ✅ Local |
| 4 | Ordering policy redesign: dates + repeat, selected stores, review default | ✅ Local (plus per-store overrides on Edit store) |
| 5 | Remove **Haroon** local + live | ✅ Done |
| 6 | E2E **notification** test | ✅ Done (already verified) |
| 7 | Apply **$125 / 1** (+ window) on **live** | ⬜ Open |
| 8 | **Commit + push** + QA note for Salman | ⬜ Open (UX still uncommitted) |

> **Not a task:** Brampton / province data. Mentioned while testing Reports filters; data is correct — dropped from the plan.

---

## Also shipped around this call (not all called out on the PDF)

- Checkout billing **editable** again (prefilled from store; locked Store Summary removed).
- Usage **cards removed from checkout**; Approval required card instead of dual Woo error icons.
- Topbar **Refresh** limited to Dashboard.
- Policy emails **deferred** to shutdown so the policy save feels instant.
- Demo accounts: `storeowner` / `StoreOwner1!` → Test store; `uzahid` temp link + `store_employee` for Users list.
- Amazon walkthrough SKU: **Epson T6716** (Thermal roll is cheapest on woo).

---

## Explicitly not in this sprint

- Ordering windows **per store group** (global + per-store only).
- Alyshia **exception form** (B5).
- Site timezone still **UTC** (needs OK to switch to Edmonton).
- Vendor email volume (up to 3 per order) — decide post-launch.
