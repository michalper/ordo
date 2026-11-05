# ADR 0022: One shared catalog walk per store for every product feed format, not one per format

## Status

Accepted.

## Context

`Cron\RefreshProductFeed` (and the identical double loop in `Controller\Adminhtml\ProductFeed\
RefreshNow`) looped `foreach ($generators as $generator) { foreach ($stores as $store) {
$generator->generate($storeId); } }`. Each `FeedGeneratorInterface::generate(int $storeId): array`
call independently walked the *whole* catalog via its own `CatalogFeedProductFetcher::
fetchByPage($storeId)` call. With 3 registered formats (`google_merchant`, `meta_catalog`,
`ai_agent`) that's 3 full paged catalog walks per store, even though every generator scopes the
walk identically — `CatalogFeedProductFetcher::makeCollection()` applies the exact same
enabled/catalog-or-search-visible/final-price/stock-join filter regardless of which generator
calls it; the three formats only ever differ in how they *render* a product (XML/CSV/JSON) and
which per-product fields they require before including it (price, image, and — Meta only — a
configured default brand). On a large catalog that's redundant DB load and PHP object
hydration proportional to (format count × store count) instead of just (store count).

## Decision

- `FeedGeneratorInterface::generate(int $storeId): array` became three methods: `startFeed(int
  $storeId): void` (reset per-store state — store/currency scope, item accumulator, any format
  header), `addProduct(Product $product): void` (render-or-skip exactly one product into that
  accumulator, the generator's own business rule), and `finishFeed(): array{content, productCount}`
  (finalize and return what `generate()` used to return directly). Each of
  `GoogleMerchantFeedGenerator`/`MetaCatalogFeedGenerator`/`AiAgentFeedGenerator` now holds that
  accumulator state as ordinary (non-readonly) instance properties, reset by every `startFeed()`
  call — a real, deliberate departure from the previous pure-function-per-call shape.
- New `Model\ProductFeed\ProductFeedRefresher::refreshAll()` owns the shared walk and is the one
  place both `Cron\RefreshProductFeed` and `Controller\Adminhtml\ProductFeed\RefreshNow` now
  delegate to (mirroring `ProductFeedCacheWriter` already being the one shared write path for
  both callers). Per store: every generator enabled for it gets `startFeed($storeId)`, then
  `CatalogFeedProductFetcher::fetchByPage($storeId)` is walked exactly ONCE, offering each
  product to every still-active generator's `addProduct()`, then each gets `finishFeed()`.
- A generator that throws during `startFeed()`/`addProduct()` is dropped out of that store's
  active set immediately (reported failed, same `writeError()`/run-log path as before) without
  aborting the shared walk for the generators still active — preserving the pre-existing "one
  store's or one format's failure doesn't affect another's cached feed" guarantee despite the
  walk now being shared rather than per-generator.
- `FeedGeneratorPool` and `CatalogFeedProductFetcher` are both unchanged — the SQL-level scope
  genuinely doesn't vary per generator today, so one fetcher feeding every generator the same
  batch of products is correct as-is. If a future format ever needs a *different* catalog filter,
  that's new ground `ProductFeedRefresher` doesn't cover and would need revisiting then, not
  speculatively solved here.
- `Controller\ProductFeed\AbstractFeedAction` (the public route that *serves* the cached feed) is
  untouched — it only ever calls `getContentType()`/`isEnabled()`/`getFeedCode()` against the
  cached DB row, never `generate()`/the new three methods, so this reshape doesn't touch it.

## Consequences

- `RefreshProductFeed`/`RefreshNow` now walk the catalog once per store instead of once per
  (format, store) pair — for N stores and the current 3 formats, 3×N paged collection loads become
  N.
- `FeedGeneratorInterface` implementations are now stateful across a `startFeed()`/`addProduct()`*/
  `finishFeed()` call sequence instead of being a pure function of `$storeId`. Callers must follow
  that sequence in order (never skip `startFeed()`, never call `finishFeed()` without it) — safe
  for `ProductFeedRefresher`'s own single-threaded, sequential per-store loop, since `startFeed()`
  fully resets accumulator state every time, but a generator instance is no longer safely callable
  as a one-shot `generate($storeId)` the way `Test/Unit` previously exercised it directly.
- Existing generator/cron/controller unit tests needed rewriting to drive the new three-call
  sequence instead of asserting on a single `generate()` return value; the paging-specific
  assertions (`setPageSize`/`setCurPage`/`load`/`clear` call counts) moved out of the generator
  tests entirely into a new, previously-missing `CatalogFeedProductFetcherTest`, since paging is no
  longer something a generator does itself.
