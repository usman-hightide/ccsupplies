# Knowledge — CC Supplies (Canna Cabana)

**Canonical home for client files, meeting notes, emails, architecture, plans, audits, screenshots, local DB backups, and project status.**

**Last updated:** 2026-10-31  

| Start here | Why |
|------------|-----|
| [`PROJECT_STATUS_AND_CHAT_HISTORY.md`](./PROJECT_STATUS_AND_CHAT_HISTORY.md) | Current production + local flow, people, meetings, done/pending, QA |
| [`ANALYZE_PROJECT_PROMPT.md`](./ANALYZE_PROJECT_PROMPT.md) | Paste into Cursor to analyze the full project and understand every flow |
| [`plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md`](./plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md) | Full task list after 29 Oct internal demo (Aman / Chris / Basit / Bhaumik) |
| [`plans-audits/CLAUDE_BRIEF_Supply_Manager_Dashboard_Revamp.md`](./plans-audits/CLAUDE_BRIEF_Supply_Manager_Dashboard_Revamp.md) | Requirements prompt to revamp Supply Manager dashboard + reports UI |
| [`db-backups/`](./db-backups/README.md) | Local MySQL dumps — restore `ccsupplies-local-db-LATEST.sql` |

## Folder map

| Folder | What lives here |
|--------|-----------------|
| `client-shared/` | Ops Excel/CSV exports, ASIN sheets, meeting PDF + notes |
| `emails/` | Draft / MOM emails (Jai, Amazon demo attendees) |
| `architecture/` | Amazon architecture MD/DOCX + diagrams |
| `plans-audits/` | Product plans, audits, store docs, Aman plan, Claude UI brief |
| `screenshots/` | Demo UI screenshots |
| `tests/` | Local G&T / vendor compare test scripts |
| `db-backups/` | Local DB SQL dumps (tracked for restore) |

## Meetings (must read)

| Date | File |
|------|------|
| Chris call (ops / G&T) | `client-shared/meetings/Call with Chris and 1 other.docx` |
| **29 Oct 2026** internal Amazon + G&T demo | `client-shared/meetings/MEETING_2026-10-29_INTERNAL_DEMO.md` + PDF + transcript |

## Related code plans (repo `docs/`)

- `docs/PLAN-vendor-cheapest-pricing-and-checkout-ux.md` — storefront cheapest vendor price + checkout UX (in progress on branch `feature/store-cheapest-pricing-checkout-ux-2026-10-30`)

> Do not put API secrets or passwords in new Knowledge files beyond documented **QA test accounts**. Amazon / G&T secrets stay in `wp-config.php` only.
