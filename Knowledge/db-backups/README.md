# Local DB backups — not in GitHub

SQL dumps stay **local / USB only**. They are gitignored on purpose (size + secrets in options tables).

## Where to get a dump

1. USB portable zip (`ccsupplies-local-FULL-*.zip` → `DATABASE/`)  
2. Or files in this folder on a machine that already has them, e.g. `ccsupplies-local-db-LATEST.sql`

## Restore

See USB package `00-RESTORE-ON-NEW-LAPTOP.md`, or:

```bash
mysql --protocol=SOCKET -S "$LOCAL_MYSQL_SOCK" -u root -proot local < Knowledge/db-backups/ccsupplies-local-db-LATEST.sql
```
