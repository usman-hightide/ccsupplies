# Local DB backups

SQL dumps of the Local by Flywheel **ccsupplies** site database (`DB_NAME=local`).

These dumps are **tracked in git** under `Knowledge/db-backups/` so the project can be restored with matching products, stores, orders, and portal data used in recent work.

## Files

| File | Notes |
|------|--------|
| **`ccsupplies-local-db-LATEST.sql`** | Always the newest dump — **restore this first** |
| `ccsupplies-local-db-YYYYMMDD-HHMMSS.sql` | Point-in-time copies |
| Older dated dumps | Keep for rollback if needed |

**Latest dump as of Knowledge update:** 2026-10-31 (~11 MB, includes `cc_vendor_prices` / cheapest-price work tables when present).

## Prerequisites to restore

1. Local site `ccsupplies` running (MySQL up).  
2. WordPress + WooCommerce already installed on that site.  
3. After restore, sync theme/plugin from this repo (root `README.md`).  
4. Re-apply `wp-config.php` secrets (`AMAZON_*`, `CC_GT_*`) — not stored in the dump as usable production secrets documentation; dump may still contain option rows — treat as **private**.

## Restore (Local)

Socket path for this machine’s Local site (from `wp-config.php` `DB_HOST`):

`localhost:/Users/macbookpro/Library/Application Support/Local/run/Ogtx2md2v/mysql/mysqld.sock`

```bash
MYSQL="/Users/macbookpro/Library/Application Support/Local/lightning-services/mysql-8.4.0+2/bin/darwin/bin/mysql"
SOCK="$HOME/Library/Application Support/Local/run/Ogtx2md2v/mysql/mysqld.sock"
DUMP="Knowledge/db-backups/ccsupplies-local-db-LATEST.sql"

"$MYSQL" --protocol=SOCKET -S "$SOCK" -u root -proot local < "$DUMP"
```

Adjust `MYSQL` / `SOCK` if Local’s service versions or site run ID differ. Or use Local’s Database UI → Import.

Then:

```bash
wp rewrite flush
```

## Taking a new dump

```bash
MYSQLDUMP="/Users/macbookpro/Library/Application Support/Local/lightning-services/mysql-8.4.0+2/bin/darwin/bin/mysqldump"
SOCK="$HOME/Library/Application Support/Local/run/Ogtx2md2v/mysql/mysqld.sock"
OUT="Knowledge/db-backups/ccsupplies-local-db-$(date +%Y%m%d-%H%M%S).sql"
"$MYSQLDUMP" --protocol=SOCKET -S "$SOCK" -u root -proot --single-transaction --quick --routines --triggers local > "$OUT"
cp "$OUT" Knowledge/db-backups/ccsupplies-local-db-LATEST.sql
```

**Note:** Dumps may contain site content and credentials in options tables. Keep the GitHub repo private; do not share dumps publicly.
