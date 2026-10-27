# FINAL PRE-IMPLEMENTATION GATE — Amazon + Grand & Toy

**Date:** 2026-10-27  
**Status:** Decision document only — **no code or data changes in this round**  
**Branch:** `rejection-notices` (preserve uncommitted Amazon ASIN work + G&T cXML untracked file)  
**Sources rechecked:** Git diff, audit CSVs, Tomas xlsx/json, Chemical Approved CSV, call transcript, admin product edit still open (`post=7632`), prior audit 2026-10-27

Legend: **VERIFIED** | **PROPOSED DEFAULT** | **BLOCKED** | **REQUIRES APPROVAL**

---

### A. Verified current state

Unchanged vs first audit on all material counts and architecture. Recheck confirms:

| Item | State | Label |
|------|--------|-------|
| Tomas High Tide Product List | **112** unique ASINs (`asin` + `description` only) | VERIFIED |
| Woo published products (prod export CSVs) | **118** | VERIFIED |
| With `amazon_asin` | **26** (15 in Tomas list + **11** legacy ASINs) | VERIFIED |
| Without `amazon_asin` | **92** | VERIFIED |
| Reconciliation CSV statuses | `MATCHED_TOMAS_ASIN` 15 / `LINKED_NON_TOMAS_ASIN` 11 / `NO_AMAZON_ASIN` 92 | VERIFIED |
| CSV agreement | miss 92 + link 26 = 118 = recon 118 | VERIFIED |
| Chris “119” list / “7” gap | **Not in workspace** | BLOCKED (external file) |
| Amazon ASIN search + Sync OEM/title | Implemented; live for linked SKUs | VERIFIED |
| Amazon cart / checkout / Trial Mode / approval / $120 / 5% | Implemented | VERIFIED |
| “Scan Now” dual-vendor UI | **Does not exist** in codebase | VERIFIED |
| G&T cXML client | Present (`grand-and-toy-cxml.php`, stage-only); **untracked** in git | VERIFIED |
| G&T production endpoint | Code refuses non-stage host | VERIFIED |
| G&T Incapsula 403 | Documented historically; **not re-proven this round** | Not currently reproduced |
| Admin ASIN edit | Custom field `amazon_asin` (e.g. product 7632 = `B006ZZGDIW`) + Woo SKU | VERIFIED |
| Dedicated G&T part meta / G&T-only flag | Not present | VERIFIED |
| Official G&T approved catalogue file | **Not in workspace** (Chemical SDS sheet ≠ catalogue) | VERIFIED absence |
| Automated tests for match/compare | None found | VERIFIED |
| Uncommitted work to preserve | `amazon-apis-integration.php` ASIN path; vendor `&amp;` fixes; untracked `amazon-asin-catalog.json`, `grand-and-toy-cxml.php` | VERIFIED |

**Meeting number confusion (resolved for engineering):**  
112 = Tomas Amazon preferred list · 118 = Woo catalog · 26/92 = Woo ASIN meta coverage · 119/7 = Ops list comparison **not available here**.

---

### B. Confirmed requirements

Supported by **Chris/Basit call transcript** and/or **existing implemented product decisions**:

1. Store places order in Woo; Ops procures afterward. **VERIFIED** (call + portal)  
2. Ops must be able to consider **Amazon and G&T as separate suppliers**, not Amazon-only. **VERIFIED** (call)  
3. Some SKUs will **never** have an Amazon ASIN (G&T price/pack). Missing ASIN ≠ invalid product. **VERIFIED** (call)  
4. Ops **manually** chooses vendor (price vs speed vs pack). **VERIFIED** (call) — Chris did **not** request auto-cheapest or auto-place  
5. Amazon path: ASIN search → cart → order (trial until smoke/Jai). **VERIFIED** (code + prior Ed thread)  
6. G&T path: stage cXML first; production only when configured. **VERIFIED** (code comments + handoff)  
7. SenderIdentity for G&T PO: **store email accounts**. **VERIFIED** (Chris Teams)  
8. Backorder behaviour: secondary option and/or store note + Ops finds replacement. **VERIFIED** (Chris Teams); cXML default **BO** already in code  
9. Chris/Alyshia can enter ASINs in admin for Amazon-buyable items. **VERIFIED** (call + Custom Fields)  
10. Do not release Amazon to all store users until architecture/process gates (Jai) — **VERIFIED** from Ed thread (still in force unless Basit overrides)

**Not specified by Chris (do not invent as confirmed):** exact UI name “Scan Now”; mandatory field list for comparison UI; Staples/Walmart in v1; automatic vendor selection; mandatory rewrite of the 11 legacy ASINs; that Store SKU ≡ G&T part for 100% of catalog.

---

### C. Decisions already resolved

| Topic | Decision | Label |
|-------|----------|-------|
| Force ASINs onto all 92 | **No** | VERIFIED (business + audit) |
| Treat 92 as “broken” | **No** | VERIFIED |
| Change 11 legacy ASINs now | **No** — leave until Ops reviews | PROPOSED DEFAULT / safe |
| First compare UI = read-only + **manual** select | **Yes** | PROPOSED DEFAULT (matches Chris) |
| Auto-select cheapest / auto-PO | **No** | VERIFIED / PROPOSED DEFAULT |
| Production G&T endpoint now | **No** — stage only | VERIFIED |
| Start with existing `amazon_asin` custom field | **Yes** for Phase 1 | PROPOSED DEFAULT |
| Dedicated ASIN Product Data field | Nice-to-have, not a hard gate for mapping | PROPOSED DEFAULT (defer or small Phase 1) |
| Explicit eligibility field (`amazon` / `gt` / `both` / `unset`) | Useful; default `unset` = current behaviour | PROPOSED DEFAULT |
| Store SKU as G&T part ID | **Candidate only** — validate via stock check; explicit map if fails | PROPOSED DEFAULT |
| Ambiguous Amazon pack/equivalent | Ops decides; system must not auto-equate by name | VERIFIED (call) |
| One vendor API fails | Show available side; block only that vendor’s path | PROPOSED DEFAULT |
| No Amazon ASIN → still G&T-eligible | **Yes** | VERIFIED (call) |
| Amazon Place Order | Keep existing approval / Trial Mode / caps | VERIFIED |
| Multi-vendor Staples etc. | **Defer** | VERIFIED (call = long-term) |
| Chemical “Approved Item List” for Amazon mapping | **Out of scope** for ASIN work | VERIFIED |

---

### D. Final questions for Chris / Alyshia

Reply once; use the attached CSVs (`Amazon_Missing_SKU_ASIN_Mapping.csv`, `AUDIT_Product_Reconciliation.csv`).

1. **Classify the 92** (or return filled sheet): for each row, set one of:  
   `AMAZON_OK` (provide ASIN) | `GT_ONLY` | `BOTH` (ASIN + confirm G&T part) | `UNKNOWN`  
2. Confirm whether the **11 legacy ASINs** (listed in reconciliation as `LINKED_NON_TOMAS_ASIN`) should **KEEP**, **REPLACE** with Tomas ASIN, or **CLEAR**.  
3. If a **119-row list** exists for the “7” gap vs Tomas 112: please share that file (or confirm no separate 119 list).  
4. Confirm G&T **SupplierPartID**: is **Woo Store SKU** the official part number for all G&T-buyable SKUs? If not, provide SKU → G&T part map.  
5. Confirm pack-size rule in one line: if Amazon only sells multipacks and store needs each, default = **GT_ONLY** unless Ops explicitly accepts multipack — OK?

---

### E. Final questions for Basit

1. Approve **Phase 1–3** scope below as the first build (mapping hygiene + G&T stage connectivity + read-only compare / manual select)? Y/N  
2. Priority if time-limited: **(A)** finish Amazon-buyable ASINs only, or **(B)** dual-vendor compare ASAP even if G&T stage is flaky?  
3. Confirm G&T approved catalogue was shared — if yes, **resend path/file to Usman** (not in this workspace).  
4. Is Amazon **store-user release** still blocked on Jai architecture approval? Y/N  
5. Approve leaving **11 legacy ASINs** untouched until Chris answers D2? Y/N  

---

### F. Technical blockers for Usman

| Blocker | Impact | Label |
|---------|--------|-------|
| No G&T approved catalogue in workspace | Cannot complete G&T product link matrix | BLOCKED (Basit/Chris file) |
| Classification of 92 | Cannot finish Amazon mapping or eligibility flags correctly | BLOCKED (Ops) |
| G&T stage reachability | Stock/PO not proven this round; historical Incapsula handling exists in code | BLOCKED / TBD on next safe stage test |
| Production G&T URL | Unavailable / unapproved by design | BLOCKED until G&T configures |
| Alyshia 119 list | Optional for engineering; needed only to close Ops “7” narrative | BLOCKED (external) if required for stakeholder closure |

**Can begin immediately (no blocker):** preserve Amazon flow; Phase 1 admin hygiene (optional ASIN field, eligibility meta defaulting to unset); staging harness for G&T using sample parts; prepare compare UI against Amazon-linked + mocked G&T responses — **without** writing production POs or changing the 11 legacy ASINs.

---

### G. Implementation plan

#### Phase 1 — Product mapping and safe admin improvements  
**Prerequisites:** Basit Phase 1 OK; Chris may still be filling ASINs in parallel.  
**Likely files:** `amazon-apis-integration.php` (keep Sync); optional Product Data field in theme/`functions.php`; optional `procurement_eligibility` post meta; do **not** wipe legacy ASINs.  
**Acceptance:** Ops can set/view ASIN easily; products without ASIN still sellable in Woo; Sync still OEM-safe; no regression to linked Search Amazon.  
**Depends on:** None to start admin hygiene; full classification for complete mapping.  
**Label:** PROPOSED DEFAULT start now  

#### Phase 2 — Grand & Toy connectivity and normalized vendor data  
**Prerequisites:** Catalogue or confirmed SKU=part rule; stage network path.  
**Likely files:** `grand-and-toy-cxml.php`; wp-config `CC_GT_*` (secrets stay local); optional part meta.  
**Acceptance:** Admin `/?gtoy_stock=1` returns parseable price/qty for known parts; errors surfaced (including Incapsula) without crashing; production host still blocked in code.  
**Depends on:** F blockers on catalogue + stage access.  
**Label:** BLOCKED until stock check succeeds once  

#### Phase 3 — Read-only comparison UI and manual vendor selection  
**Prerequisites:** Phase 1 eligibility useful; Phase 2 stock OK **or** Amazon-only row + G&T “unavailable/error”.  
**Likely files:** manager orders UI (`wcss-internal-supplies` frontend); thin REST that returns normalized `{vendor, asin/part, price, qty, eta?, error?}`.  
**Acceptance:** Ops opens an order, sees Amazon and/or G&T rows, **selects** vendor manually; no auto-PO; one-side failure still shows the other.  
**Depends on:** Basit E1/E2.  
**Label:** REQUIRES APPROVAL on priority  

#### Phase 4 — Procurement integration and staging acceptance tests  
**Prerequisites:** Phase 3 selection; G&T stage PO OK; Amazon Trial Mode unchanged.  
**Likely files:** checkout/order bridges; `cc_gt_submit_order` with store-email SenderIdentity; existing Amazon place-order gates.  
**Acceptance:** Staging scripts: ASIN present / missing / GT-only / both / Amazon timeout / G&T 403 / pack mismatch flagged for Ops; no live production PO.  
**Depends on:** Chris D1/D4/D5; Basit.  

#### Phase 5 — Production readiness and release  
**Prerequisites:** Staging sign-off; Jai/Amazon gates if still required; G&T production URL only if G&T enables it.  
**Acceptance:** Documented smoke (Amazon trial + one controlled path); no silent production G&T endpoint; regression checklist on existing Amazon flow.  
**Label:** REQUIRES APPROVAL  

**Must not regress:** Amazon ASIN search, cart, Trial Mode place-order, approval gate, $120 cap, 5% tolerance, existing 26 linked behaviours.

---

### H. Single final approval checklist

**Basit — reply Y/N (or edit):**

- [ ] Approve Phases 1–3 as described (manual compare, no auto-buy, stage G&T only)  
- [ ] Priority if short on time: ☐ A Amazon mapping first  ☐ B Dual-vendor compare first  
- [ ] I will resend / confirm G&T approved catalogue location: _______________  
- [ ] Amazon store-wide release still needs Jai OK: ☐ Y  ☐ N  
- [ ] Leave 11 legacy ASINs until Chris answers: ☐ Y  

**Chris / Alyshia — reply with file or filled CSV:**

- [ ] Returned classification for 92 (`AMAZON_OK`+ASIN / `GT_ONLY` / `BOTH` / `UNKNOWN`)  
- [ ] Decision on 11 legacy ASINs: KEEP / REPLACE / CLEAR  
- [ ] Shared 119 list **or** confirmed N/A for the “7”  
- [ ] Confirmed Store SKU = G&T part **or** attached part map  
- [ ] Pack-size default GT_ONLY when Amazon multipack-only: ☐ Agree  

**Usman — starts when Basit checks Phase 1 (and proceeds into 2–3 as answers arrive):**

- [ ] Preserve uncommitted Amazon + G&T files; no production endpoint  
- [ ] No force-ASIN on all 92; no auto vendor select  

---

### What can start immediately vs wait

| Start now | Wait for |
|-----------|----------|
| Phase 1 admin hygiene + keep Amazon stable | — |
| Stage G&T connectivity attempt (safe test URLs only) | Allowlist / network if 403 |
| Stub/normalize compare DTO | Basit priority for full UI |
| Full ASIN coverage / eligibility population | Chris D1 |
| G&T part matrix complete | Catalogue or D4 |
| Production G&T / store release | Explicit approvals |

**Next step after this document:** implementation of Phase 1 (and Phase 2 connectivity tests), **not** another discovery cycle — unless Basit rejects the scope checklist above.
