# ADR 0039: "N people viewed this today" social proof widget

## Status

Accepted.

## Context

`ROADMAP.md` flagged a real gap from the 2026-11-08 brainstorm: `ordo_visitor_event` already
aggregates per-product view counts for `Cron\CalculateProductPerformance`, but nothing surfaces
that data back to the storefront itself. This module has an established pattern for
PDP-integration-points it deliberately does NOT inject into automatically - `window.ordoTrack()`
for `product_view`/`checkout_start`, `window.ordoSubscribeToPriceWatch()` for the "Notify me"
button - because there is no `catalog_product_view.xml` override anywhere in this module and no
PDP-scoped block of any kind: the theme is always the one that knows where things live on its own
product page, so every PDP-facing integration this module has ever added works by handing the
theme a documented JS function to call with the data it already has, not by assuming a layout
position.

## Decision

- **A new theme-integration-point JS function**, `window.ordoRenderSocialProof(sku,
  containerSelector)`, same shape as the existing two above - the theme calls it on the PDP with
  the SKU it already has and a container element it has placed in its own markup (starting
  hidden, revealed only once a real message comes back). No new layout XML, no new frontend
  Block/ViewModel/template - this module still injects nothing into `catalog_product_view.xml`.
- **A live query against `ordo_visitor_event`, not `ordo_product_performance`.**
  `Cron\CalculateProductPerformance` writes only an all-time running total
  (`view_count = view_count + N`, no date bucket at all) - it cannot answer "how many views
  today." `ordo_visitor_event` is pruned only after `Helper\Config::getTrackingRetentionDays()`
  (default 7 days, `Cron\PruneVisitorEvents`), well past "today," so a live `COUNT(*)` scoped to
  `created_at >= midnight` is both correct and safe - a different cost/freshness tradeoff than the
  product performance report's own reasoning for materializing instead (ADR 0015), since this
  reads one SKU at a time, on demand, not the whole catalog in a batch.
- **A new composite index** (`event_type`, `event_key`, `created_at`) on `ordo_visitor_event` -
  the existing indexes (`visitor_id`, `customer_id`, `created_at` alone) don't cover this query's
  own access pattern, and unlike `CalculateProductPerformance`'s own nightly batch read, this runs
  on every PDP view once the feature is enabled.
- **The message is formatted server-side** (`Controller\Track\SocialProof`, from `Helper\Config::
  getSocialProofMessageTemplate()`'s `{count}`-templated string), not the raw count sent for
  tracker.js to template client-side - keeps the visible copy as ordinary per-store-view-scoped
  admin config text, consistent with every other admin-authored string in this module, rather
  than a hardcoded string baked into a static JS file.
- **A configurable minimum view count** (`Helper\Config::getSocialProofMinimumViews()`, default
  3) below which the endpoint returns no message at all, checked server-side rather than left to
  the theme - "1 person viewed this today" reads as weak evidence, not social proof, so there is
  a floor below which saying nothing is strictly better than saying something unconvincing.
- **Its own enable toggle**, gated also behind the top-level `tracking > enabled` toggle - matching
  every other tracker.js-driven feature in this module.

## Consequences

- A store can show real, live social proof on its PDP with one call the theme adds to its own
  template - no new admin UI to configure beyond three config fields, no new content type to
  author (unlike a popup/notification, there is no per-instance message to write, the template
  is store-wide).
- `ordo_visitor_event` now takes one more composite index to maintain - acceptable, this table is
  bounded by `Cron\PruneVisitorEvents`' own retention window regardless.
- A store with a genuinely low-traffic catalog will simply never see the widget on most products
  (by design, via the configurable minimum) rather than show a number that undermines the exact
  impression it's meant to create.
