# CURSOR-HANDOFF — ccsupplies (Canna Cabana Internal Supplies)

> Equivalent to master-prompt `PROJECT_CONTEXT.md` (filename kept as `CURSOR-HANDOFF.md`).  
> **Audit date:** 2026-10-25

## 1. Project Overview

| Item | Detail |
|------|--------|
| **Live** | https://ccsupplies.ca |
| **Local** | https://ccsupplies.local |
| **Client / brand** | Canna Cabana (High Tide Inc.) — internal stationery / supplies portal |
| **Type** | Login-only employee procurement (not public retail) |
| **Path** | `/Users/macbookpro/Local Sites/ccsupplies` |
| **GitHub** | https://github.com/usman-hightide/ccsupplies.git |
| **Branch (this Mac)** | `rejection-notices` |

## 2. Client Requirements and Scope

**Explicit (from store docs + G&T / Amazon threads):**

- Internal requisitions with manager approval, monthly quota/budget per store (~223 locations).
- Amazon Business ordering path for managers (catalog → cart → checkout / trial mode → order view).
- Grand & Toy B2B **cXML**: Live Stock Check + Submit PO for Trading Partner **312657** (stage first).
- Rejection notices / approval gates / monthly Amazon spend controls as implemented on feature branches.

**Acceptance (G&T):** Stage stock check returns price/qty; PO accepted on stage; then wire to store flow — production endpoint only when G&T configures it.

## 3. Client Communications and Feedback

| Person | Role / evidence |
|--------|-----------------|
| Usman Zahid | Developer of record (Git author); client updates |
| Basit | Amazon / product-list clarifications |
| Chris | Open G&T decisions: account bucket, BO vs FP, part list |
| Jai | Architecture emails (`docs/EMAIL_TO_JAI_*.md`) |
| Junaid | Weekly G&T checklists |
| Gabriel / Marketing | N/A for this store (see Cabana Club USA for giveaway) |

Sources: Cursor chats `e670fe40`, `00d148cc`, `861f8ed0`, `36884f80`, `11b24b3c`; `docs/STORE_DOCUMENTATION.md`.

## 4. Technical Architecture

- WordPress + WooCommerce + Elementor; Local by Flywheel.
- Child theme `adex360-canna-cabana-stationery-child` (Amazon + `grand-and-toy-cxml.php`).
- Plugin `wcss-internal-supplies` (manager portal).
- Secrets only in local `wp-config.php` (`AMAZON_*`, `WCSS_SMTP_*`, `CC_GT_*`) — not in git.
- G&T stage URL guard; helpers `cc_gt_stock_check()` / `cc_gt_submit_order()` (not yet hooked to checkout).

## 5. Completed Work

- Local restore from Updraft; store documentation.
- Amazon ordering E2E (trial), approval gate, catalog search, monthly cap, rejection emails, order-view tax/shipping fixes.
- G&T cXML client + local credentials; admin test `/?gtoy_stock=1`; files deployed to production for stage tests.
- Git sync README for multi-laptop theme/plugin rsync.

## 6. Current Implementation Status

- Amazon path: working in trial/sandbox sense (verified in Oct chats).
- G&T code: present; stock check often blocked by Incapsula **HTTP 403** before cXML is read.
- G&T not connected to per-store checkout yet.

## 7. Pending Tasks and Priorities

| Priority | Task |
|----------|------|
| P0 | Clear G&T stage 403 / allowlist; confirm stock XML response |
| P0 | Chris decisions: bucket model, BO vs FP, official part/price/UOM list |
| P1 | Validate stage PO; then wire into store order flow |
| P2 | Production G&T endpoint when TP configured |
| P3 | Older manager UI polish (pagination, reporting) |

## 8. Blockers and Risks

1. Incapsula 403 on stage.  
2. PO not validated on stage.  
3. Open Chris business decisions.  
4. No production G&T URL for TP 312657.  
5. Secrets must stay out of git / handoff files.

## 9. Environment and Setup

- Local WP site `ccsupplies`; PHP/MySQL via Local.
- Clone repo; rsync theme/plugin per `README.md`.
- Copy `wp-config.php` secrets from secure store.
- `wp rewrite flush`.
- Manager: `/manager/orders/`.

**Never document secret values here** — only constant *names*.

## 10. Important Files and Directories

- `README.md` — sync + secrets checklist  
- `docs/` — store, Amazon, meeting materials  
- `app/public/wp-content/themes/adex360-canna-cabana-stationery-child/` — Amazon + G&T  
- `app/public/wp-content/plugins/wcss-internal-supplies/` — manager  
- `.pre-sync-backup/` — optional old sync backup (candidate to delete if confirmed obsolete)

## 11. Testing and Deployment

- Amazon: manager Amazon Test Order → trial checkout → order view.  
- G&T: admin `/?gtoy_stock=1` against stage only.  
- Deploy: custom child theme + plugin files only — not entire `wp-content`.  
- Verified: Amazon trial E2E (Oct); G&T stage still failing gateway in last session.

## 12. AI Session Handover

| Chat | Focus |
|------|--------|
| `e670fe40-…` | Backup → Local |
| `00d148cc-…` | Usman Amazon testing / GitHub |
| `861f8ed0-…` | Amazon analysis, rejection-notices |
| `36884f80-…` | G&T cXML + 403 blockers |
| `11b24b3c-…` | G&T meeting / email prep (API Issue workspace) |

Transcripts: `~/.cursor/projects/Users-macbookpro-Local-Sites-ccsupplies/agent-transcripts/`

## 13. Cleanup History

- **2026-10-25:** Removed `.live-sync-tmp/` (~47 MB) and `.DS_Store`.  
- Left `.pre-sync-backup/` (18 MB) pending owner confirmation.  
- No source/docs/secrets deleted.

## 14. Next Actions

1. Read this file + `README.md` + `docs/STORE_DOCUMENTATION.md`.  
2. Restore `wp-config` secrets on new Mac.  
3. `git fetch && git checkout rejection-notices && git pull`.  
4. Verify manager + Amazon trial locally.  
5. Resume G&T only after network/Chris progress.

## 15. Context Sources and Confidence

| Source | Confidence |
|--------|------------|
| `docs/STORE_DOCUMENTATION.md`, Amazon docs | Verified on disk |
| Git log / branches | Verified |
| Cursor chats listed above | Verified via local transcripts / search |
| Live production state after last deploy | Partially verified — re-check on new Mac |
| Exact shared-secret values | **Not** stored here (by design) |

**Last audit:** 2026-10-25
