# CC Supplies — Local Dev Sync

Custom WordPress code for **Canna Cabana Stationery Store** (`ccsupplies`).

Custom WordPress theme/plugin code + the **Knowledge/** pack (status, meetings, plans, local DB dumps).

## What is in this repo

- `wp-content/themes/adex360-canna-cabana-stationery-child/` — Amazon catalog/cart/checkout + order storage
- `wp-content/themes/adex360-cannacabana-stationery/` — parent theme
- `wp-content/plugins/wcss-internal-supplies/` — Manager portal (incl. **Amazon order view**)
- `Knowledge/` — **all** client docs, emails, plans, Approved Item Lists, ASIN sheets, demo status (was `docs/`)

## Not in this repo (on purpose)

- `wp-config.php` (DB, Amazon API, and Grand & Toy cXML secrets)
- `uploads/` media
- Large stock plugins (WooCommerce, Elementor, etc.) — install/keep from your Local site / backup
- Local by Flywheel `conf/` + `logs/`

## Local site setup (WordPress + this repo)

**Full analysis context:** start at `Knowledge/PROJECT_STATUS_AND_CHAT_HISTORY.md` and paste `Knowledge/ANALYZE_PROJECT_PROMPT.md` into Cursor.  
**DB:** restore `Knowledge/db-backups/ccsupplies-local-db-LATEST.sql` (see `Knowledge/db-backups/README.md`).

1. Create/open Local site `ccsupplies` (WP + WooCommerce working).
2. Clone this repo, then sync custom folders into the Local site:

```bash
git clone https://github.com/usman-hightide/ccsupplies.git
cd ccsupplies
SITE="/Users/YOU/Local Sites/ccsupplies/app/public"

# Backup first
cp -R "$SITE/wp-content/themes/adex360-canna-cabana-stationery-child" "$SITE/wp-content/themes/adex360-canna-cabana-stationery-child.bak" 2>/dev/null || true
cp -R "$SITE/wp-content/plugins/wcss-internal-supplies" "$SITE/wp-content/plugins/wcss-internal-supplies.bak" 2>/dev/null || true

# Sync custom code from this repo
rsync -a --delete \
  "app/public/wp-content/themes/adex360-canna-cabana-stationery-child/" \
  "$SITE/wp-content/themes/adex360-canna-cabana-stationery-child/"

rsync -a --delete --exclude='.git' \
  "app/public/wp-content/plugins/wcss-internal-supplies/" \
  "$SITE/wp-content/plugins/wcss-internal-supplies/"

rsync -a \
  "app/public/wp-content/themes/adex360-cannacabana-stationery/" \
  "$SITE/wp-content/themes/adex360-cannacabana-stationery/"

# Knowledge pack (status + client files) — already in repo root after git pull
# Open: Knowledge/PROJECT_STATUS_AND_CHAT_HISTORY.md
```

### Branches

```bash
git fetch origin
# Current storefront cheapest-price + checkout UX work:
git checkout feature/store-cheapest-pricing-checkout-ux-2026-10-30
git pull origin feature/store-cheapest-pricing-checkout-ux-2026-10-30

# Prior G&T manager + Knowledge pack (Oct 28):
# git checkout feature/gt-amazon-knowledge-demo-2026-10-28
```

Then paste `Knowledge/ANALYZE_PROJECT_PROMPT.md` into Cursor.

3. Ensure local `wp-config.php` has secrets (from live / other laptop — **do not commit**):

```php
define( 'AMAZON_CLIENT_ID', '...' );
define( 'AMAZON_CLIENT_SECRET', '...' );
define( 'AMAZON_REFRESH_TOKEN', '...' );
define( 'AMAZON_MARKETPLACE_ID', 'ATVPDKIKX0DER' );
define( 'AMAZON_SP_API_ENDPOINT', 'https://sandbox.sellingpartnerapi-na.amazon.com' );

// Optional SMTP (Office 365) for plugin mailer override
define( 'WCSS_SMTP_USER', 'no-reply@ccsupplies.ca' );
define( 'WCSS_SMTP_PASSWORD', '...' );

// Grand & Toy stage cXML (Trading Partner 312657). Do not set the production URL.
define( 'CC_GT_STAGE_ENDPOINT', 'https://stage.grandandtoy.com/B2BIntegration/SubmitPO/SubmitPO_wholesale.aspx' );
define( 'CC_GT_FROM_IDENTITY', 'CANNA312657' );
define( 'CC_GT_TO_IDENTITY', '204139083' );
define( 'CC_GT_SHARED_SECRET', '...' );
define( 'CC_GT_STOCK_SENDER', 'LOCATION1' );
```

4. In Local site shell:

```bash
wp rewrite flush
```

5. Open: `https://ccsupplies.local/manager/orders/` → **Amazon order view**

## Daily sync

```bash
cd ccsupplies
git pull
# then rsync the theme/plugin folders again (commands above)
```

## Features included

- Manager orders: **Amazon Test Order** + **Amazon order view**
- Saves Amazon cart/checkout snapshot to order meta `_wcss_amazon_order_data`
- Amazon catalog → cart → checkout keeps `order-id` in the URL
