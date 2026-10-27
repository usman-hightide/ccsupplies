# Local DB backups

SQL dumps of the Local by Flywheel `ccsupplies` site database (`DB_NAME=local`), taken before major feature work so we can restore if needed.

## Files

- `ccsupplies-local-db-YYYYMMDD-HHMMSS.sql` — full dump of database `local`

## Restore (Local)

Use Local’s Database tool, or:

```bash
MYSQL="/Users/macbookpro/Library/Application Support/Local/lightning-services/mysql-8.4.0+2/bin/darwin/bin/mysql"
SOCK="$HOME/Library/Application Support/Local/run/Ogtx2md2v/mysql/mysqld.sock"
"$MYSQL" --protocol=SOCKET -S "$SOCK" -u root -p local < docs/db-backups/<dump-file>.sql
```

**Note:** Dumps may contain site content and credentials in options tables. Do not share publicly.
