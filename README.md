# Magenx_BestSellerGraphQl

GraphQL coverage for Magento best sellers. Stock Magento aggregates best-seller
data in the `sales_bestsellers_aggregated_*` report tables but exposes **none**
of it over GraphQL, and those tables have **no per-category dimension**. This
module adds that coverage without touching any core module (the same
"extend with GraphQL" split as `Magento_CatalogGraphQl` beside `Magento_Catalog`).

## What it adds

- **`bestSellers(category_id: Int, pageSize: Int): BestSellers`** — the top-N
  best-selling products, store-wide or scoped to a single category, ordered by
  quantity sold over the configured report period. Each `BestSellerItem` carries
  a `rank` and resolves a full `ProductInterface` (so the storefront can select
  any product fields it needs).
- **`ProductInterface.best_seller: BestSellerBadge`** — an Amazon-style
  per-category badge (`rank`, `category_id`, `category_name`, `category_url_key`)
  computed as the product's best rank across the categories it belongs to, or
  `null` when it doesn't rank within the configured threshold. **Runs per
  product** — query it on the PDP only, not on listing grids.
- **`StoreConfig` flags** — `best_seller_enabled`, `best_seller_count`,
  `best_seller_period`, `best_seller_badge_enabled`, `best_seller_badge_max_rank`
  (via `extendedConfigData`, the same pattern as `product_alert_allow_price`).

## Ranking

Reads `sales_bestsellers_aggregated_<period>` filtered to the request's store id,
summing `qty_ordered` per product. Per-category ranking joins
`catalog_category_product`; a product's badge rank in a category is
`1 + (number of products in that category that sold more)`. The aggregation cron
(Reports → refresh statistics, or `bin/magento` report aggregation) must have run
for the tables to be populated.

## Configuration

Admin → Stores → Configuration → **Catalog → Best Sellers**:

- **General**: Enable Best Sellers, Number of Products (default 20), Report Period
  (Daily / Monthly / Yearly, default Monthly).
- **Product Badge**: Enable Per-Category Badge, Maximum Badged Rank (default 10).

When the feature is disabled the `bestSellers` query returns an empty list and the
`best_seller` field resolves to `null`, so the feature is fully inert.

## Install

```
bin/magento module:enable Magenx_BestSellerGraphQl
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode
```
