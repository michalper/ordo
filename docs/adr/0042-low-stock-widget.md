# ADR 0042: "Only N left in stock" low-stock widget

## Status

Accepted.

## Context

A fresh brainstorm round (2026-11-09, after the GA4/Meta/TikTok batch) flagged a near-universal
PDP urgency pattern comparable marketing-automation tools offer that Ordo didn't: showing real
stock scarcity on the product page. The same gap this module already closed once for view counts
with `Controller\Track\SocialProof`/ADR 0039 applies here - the theme is always the one that knows
where things live on its own product page, and this module still injects nothing into
`catalog_product_view.xml`.

## Decision

- **A new theme-integration-point JS function**, `window.ordoRenderLowStock(sku,
  containerSelector)`, byte-for-byte the same shape as `window.ordoRenderSocialProof()` - the
  theme calls it with the SKU it already has and a container element it has placed in its own
  markup. No new layout XML, no new frontend Block/ViewModel/template beyond the existing
  `TrackerViewModel`/`tracker.phtml` gaining one more flag.
- **Real stock quantity via `Magento\CatalogInventory\Api\StockRegistryInterface`
  (`Model\Track\ProductStockChecker`)**, not a new table and not MSI-aware - this module has no MSI
  dependency anywhere else (`Cron\ScanBackInStockAlerts` reads `$product->isSalable()`, a boolean,
  not a quantity), and `StockRegistryInterface::getStockItem()` already returns the right
  single-source-compatible qty either way. A product with no stock item (e.g. a deleted/invalid
  SKU) returns `null`, same as a SKU `Controller\Track\LowStock` can't resolve at all - both show
  no message.
- **The message is formatted server-side** (`Controller\Track\LowStock`, from `Helper\Config::
  getLowStockMessageTemplate()`'s `{qty}`-templated string) - same reasoning ADR 0039 already gave
  for the social proof widget: ordinary per-store-view-scoped admin config text, not a hardcoded
  string in a static JS file.
- **A configurable threshold** (`Helper\Config::getLowStockThreshold()`, default 5) - the message
  shows only at or below this quantity, and never at or below zero (zero is out of stock, a
  different message entirely - see `TRIGGER_BACK_IN_STOCK`/`Cron\ScanBackInStockAlerts`). Checked
  server-side, not left to the theme, same as the social proof widget's own minimum-views floor.
- **Its own enable toggle**, gated also behind the top-level `tracking > enabled` toggle - matching
  every other tracker.js-driven feature in this module.

## Consequences

- A store can show real stock urgency on its PDP with one call the theme adds to its own
  template - no new admin UI beyond three config fields, no new table, no new cron.
- `Controller\Track\LowStock` resolves the SKU to a product via `ProductRepositoryInterface::get()`
  on every call rather than caching - acceptable at PDP-view volume (the same per-request cost
  Controller\Track\SocialProof already accepts for its own `COUNT(*)` query); a store seeing real
  load here can add caching later without changing this controller's own contract.
- A single-source assumption: a store running Magento's multi-source inventory (MSI) with
  per-source stock will see `StockRegistryInterface`'s own default-scope aggregate qty, not a
  per-source breakdown - consistent with this module not using MSI anywhere else, but worth
  revisiting if a B2B store with real multi-warehouse MSI usage asks for it.
