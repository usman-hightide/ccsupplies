# CC Internal Supply

Production deploy package for the **Canna Cabana Stationery Store** (High Tide Inc.).

This repository holds the custom WordPress theme and plugin code used on hosting. WordPress core, WooCommerce, Elementor, media uploads, and secrets stay on the server — they are not committed here.

## Contents

| Path | Purpose |
|------|---------|
| `app/public/wp-content/plugins/wcss-internal-supplies/` | Supply Manager portal (`/manager`), policy, approvals, reports, emails, REST API |
| `app/public/wp-content/themes/adex360-canna-cabana-stationery-child/` | Storefront: cheapest-vendor pricing, Amazon / Grand & Toy, cart & checkout UX |
| `app/public/wp-content/themes/adex360-cannacabana-stationery/` | Parent theme |
| `tests/` | Optional local PHP smoke tests (not required on production) |

## Deploy to hosting

1. Pull or download the latest `main` from this repository.
2. Sync **only** the custom folders onto the host (backup first):

```bash
SITE="/path/to/wordpress"

rsync -a --delete \
  "app/public/wp-content/plugins/wcss-internal-supplies/" \
  "$SITE/wp-content/plugins/wcss-internal-supplies/"

rsync -a --delete \
  "app/public/wp-content/themes/adex360-canna-cabana-stationery-child/" \
  "$SITE/wp-content/themes/adex360-canna-cabana-stationery-child/"

rsync -a \
  "app/public/wp-content/themes/adex360-cannacabana-stationery/" \
  "$SITE/wp-content/themes/adex360-cannacabana-stationery/"
```

3. On the host, flush caches and permalinks:

```bash
wp rewrite flush
wp cache flush 2>/dev/null || true
# Also purge any host/CDN/page-cache plugin (Breeze, LiteSpeed, Cloudflare, etc.)
```

4. Confirm:
   - Storefront shop / cart / checkout
   - Supply Manager at `/manager`
   - No stale CSS/JS (assets use `filemtime` versioning)

## Do not commit

- `wp-config.php` or API / SMTP credentials
- `wp-content/uploads/`
- Stock plugins (WooCommerce, Elementor, WP Mail SMTP, …)
- Meeting notes, plans, internal Knowledge packs, Cursor/agent files

## Branch

Deploy from **`main`**.
