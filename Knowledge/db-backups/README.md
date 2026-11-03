# Local DB backups — not in GitHub

SQL dumps stay **local / USB only**. They are gitignored on purpose (size + secrets in options tables).

## Where to get a dump

1. Desktop portable zip: `~/Desktop/ccsupplies-local-FULL-2026-11-03.zip` (DB + wp-config + mail mu-plugin + restore guide)  
2. Or this folder: `ccsupplies-local-db-LATEST.sql` (refreshed 3 Nov 2026 — includes all stores @ CA$125 / 1 order)

## Restore

See the zip’s `00-RESTORE-ON-NEW-LAPTOP.md`, or from Local **Open site shell**:

```bash
wp db import /path/to/ccsupplies-local-db-LATEST.sql
wp rewrite flush
```
