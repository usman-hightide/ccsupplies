# Paste into Cursor (analyze CC Supplies)

You are working on **CC Supplies** (Canna Cabana internal supplies portal).

**Repo:** https://github.com/usman-hightide/ccsupplies  
**Branch:** `feature/knowledge-clean-continue-2026-11-01`  
**Live:** https://ccsupplies.ca · **Local:** https://ccsupplies.local  

## Before analyzing

1. `git fetch && git checkout feature/knowledge-clean-continue-2026-11-01 && git pull`
2. Sync theme/plugin into Local if needed (root `README.md`)
3. DB is **not** in git — restore from USB portable package or local `Knowledge/db-backups/*.sql` if present
4. `wp-config.php` must have `AMAZON_*` and `CC_GT_*` (never invent secrets)
5. Read **only** these, in order:
   1. `Knowledge/PROJECT_STATUS_AND_CHAT_HISTORY.md`
   2. `Knowledge/client-shared/meetings/MEETING_2026-10-29_INTERNAL_DEMO.md`
   3. `Knowledge/plans-audits/PLAN_Aman_Requirements_Post_Demo_2026-10-29.md`
   4. `docs/PLAN-vendor-cheapest-pricing-and-checkout-ux.md`
   5. `Knowledge/plans-audits/PLAN_Separate_Amazon_GT_Dashboards_2026-10-27.md`
   6. `Knowledge/plans-audits/CLAUDE_BRIEF_Supply_Manager_Dashboard_Revamp.md`

Do not invent requirements. Prefer Knowledge + code. If they conflict, say so.

## Rules

- Amazon ≠ Grand & Toy (separate paths)
- Store: Woo requisition; no Amazon place; no vendor picker; cheapest price auto
- Supply Manager `/manager/`: today Approve + Amazon/G&T Test Order; target = auto-approve in-policy + exception queue + real reports

## Flows to map

- **A** Store Woo → Awaiting Approval  
- **B** Manager Amazon Test Order  
- **C** Manager G&T Test Order  
- **D** Local cheapest pricing  
- **E** Planned automation + Supply Manager reports (Aman plan)

## When I say analyze

1. Roles  
2. Flows A–E (live / local / planned)  
3. Key theme + plugin files  
4. Gap list vs Aman plan  
5. No secrets  

Start by reading the files above, then give a short project analysis.
