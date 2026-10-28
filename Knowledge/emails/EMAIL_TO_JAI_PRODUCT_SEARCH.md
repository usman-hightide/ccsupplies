# Email to Jai — Product Search method and parameters in use

Copy/paste ready. Details below are taken from the live catalog code path on ccsupplies (`amazon-apis-integration.php`). No secrets included.

---

**To:** Jai  
**Cc:** Ed Petrovacki; [High Tide / internal as needed]  
**Subject:** High Tide / ccsupplies — Product Search method and parameters currently in use

---

Hi Jai,

Per Ed’s next steps from the October 14 recap, below is the **Product Search method and parameters we are using today** on production (ccsupplies.ca). This is what runs when a manager clicks **Search Amazon** on the Amazon catalog page.

### Method in use

- **API:** Amazon Business Product Search  
- **Not used:** `getProductsByAsins` / ASIN-based lookup (not implemented in our current search path)  
- **HTTP:** `GET`  
- **Endpoint:**  
  `https://na.business-api.amazon.com/products/2020-08-26/products`  
- **Auth:** Login with Amazon — refresh token exchanged server-side for an access token (`POST https://api.amazon.com/auth/o2/token`), then sent on the search request as `x-amz-access-token`

### Query parameters (exact)

| Parameter | Value in use |
| --- | --- |
| `keywords` | WooCommerce **product name** (title) for the selected store product |
| `productRegion` | `CA` |
| `locale` | `en_US` |
| `facets` | `OFFERS,IMAGES` |
| `pageSize` | `1` (we take the top match only) |
| `category` | `OFFICE_PRODUCTS` |

### Request headers (exact)

| Header | Value in use |
| --- | --- |
| `x-amz-access-token` | Access token from LWA refresh |
| `x-amz-user-email` | `ecom@hightideinc.com` |
| `Accept` | `application/json` |

### How it is called in our UI

1. Manager opens the Amazon catalog (from **Amazon Test Order** or Amazon catalog nav).  
2. Catalog lists our **published store products** (not Amazon’s full browse catalog).  
3. **Search Amazon** calls Product Search with `keywords` = that product’s WooCommerce name and `pageSize=1`.  
4. We show the first returned product (ASIN, offer/price from `OFFERS`, image from `IMAGES`) and allow **Add to Amazon Cart**.

### Example request shape

```
GET https://na.business-api.amazon.com/products/2020-08-26/products
  ?locale=en_US
  &productRegion=CA
  &facets=OFFERS,IMAGES
  &pageSize=1
  &keywords={WooCommerce product name}
  &category=OFFICE_PRODUCTS
```

### Next step on ASIN-based search

We understand the recommendation is to move to **ASIN-based search** for the Buy Box offer on the curated catalog (~117 products), with ASINs from Tomas. Once we have that ASIN list and your preferred Product Search / products-by-ASIN parameters, we will update the integration accordingly.

Please confirm if anything above should change before we implement the ASIN path, or send the exact ASIN lookup endpoint + parameters you want us to use.

Thanks,  
Usman Zahid  
Basecamp Consulting — High Tide / ccsupplies.ca  
uzahid@basecampconsulting.co
