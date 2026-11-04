# ADR 0015: Product performance report — split live/materialized data, store-wide abandon rate

## Status

Accepted.

## Context

Analytics/reporting-gap roadmap item (`ROADMAP.md` "Candidate new features"): a report showing,
per product, how many times it was viewed, how many times it was purchased, and an abandon rate.
Nothing in this module computed any of these before this change.

Investigation found two source-data problems that make a single, uniform "live query" approach
wrong, not just slow:

1. **View data is short-lived by design.** `ordo_visitor_event` (the on-site tracking table every
   `product_view` lands in) is deleted by `Cron\PruneVisitorEvents` after
   `Helper\Config::getTrackingRetentionDays()` (default 7 days) — a deliberate, documented
   GDPR-leaning choice, not an oversight (see that table's own `db_schema.xml` comment). Purchase
   data (`sales_order_item`) is never pruned. A report reading both live would silently redefine
   "views" as "views in the last ~7 days" while "purchases" stays all-time, producing an abandon
   rate that gets more wrong every day as history is purged — a correctness bug baked into the
   source table's lifecycle, not a performance tradeoff to accept.
2. **A true per-visitor "viewed but never bought" funnel is infeasible, not just harder.** A
   guest who never logs in has no `customer_id` on `ordo_visitor_event`, and `sales_order`/
   `sales_order_item` have no `visitor_id` column anywhere in this schema — that slice of views
   can never be joined to a purchase, for any retention window.

Also considered and rejected: Magento core's own `Magento_Reports` module
(`report_event`/`report_viewed_product_index`). Confirmed enabled but empty until a merchant
explicitly runs "Refresh Statistics" — opt-in, and a second, disconnected view-tracking pipeline
next to Ordo's own (the same `ordo_visitor_event` pixel already drives
`Cron\SendBrowseAbandonmentReminders`). Reusing Ordo's existing tracking keeps one source of truth
for on-site behavior, per this module's own "Shared foundation" architecture principle.

## Decision

1. **Purchase counts are a live query, mirroring `Model\Recommendation\ProductRecommender`'s own
   `rankedBestSellerSkus()`** (`sales_order` ⋈ `sales_order_item` GROUP BY `oi.sku`, excluding
   canceled orders) — unlimited/paginated here instead of capped at 200, since
   `sales_order_item` is never pruned and this join is already proven cheap in production via the
   recommender.
2. **View counts are materialized into a new `ordo_product_performance` table** (`sku` unique,
   `view_count`), kept current by a new incremental cron, `Cron\CalculateProductPerformance`,
   which reads `ordo_visitor_event` past a watermark (stored via Magento's own
   `Magento\Framework\FlagManager`, not a bespoke mechanism) rather than rescanning from scratch
   every run — this only ever needs a running `SUM`, not `CalculateReorderCycle`'s per-order date
   history. Scheduled `*/30 * * * *`, matching `SendBrowseAbandonmentReminders`'s own cadence
   (another `ordo_visitor_event` consumer) — hours of margin ahead of `PruneVisitorEvents`'s
   once-daily 04:00 run, so no view is ever deleted before being counted.
3. **Abandon rate is defined store-wide**: `1 - purchase_count / view_count` per SKU across
   everyone, computed at report-render time. Not a per-visitor funnel, for the reason in Context
   above — the admin report's own intro text says this explicitly, not just this ADR.
4. **The admin report grid** (`Model\ResourceModel\ProductPerformance\Grid\Collection`) mirrors
   `Model\ResourceModel\Rfm\Grid\Collection`'s own shape: a `SearchResult` with no entity table of
   its own, `mainTable = catalog_product_entity` (its `sku` column is flat, not EAV, so no product
   name/EAV join is needed to identify a row), `joinLeft` to the materialized view-count table and
   to a derived live purchase-count subselect. Unlike the RFM grid, `resourceModel` is left `null`
   (no mass action here needs `Magento\Ui\Component\MassAction\Filter::getCollection()`'s real
   `getIdFieldName()` fallback).

## Consequences

- View counts can lag real traffic by up to ~30 minutes (the cron's own cadence) — acceptable for
  a BI report, called out in the report's own intro text, unlike a live threshold-triggering
  feature where that lag would matter.
- One new table, one new lightweight cron, one new `FlagManager` watermark flag
  (`ordo_automation_product_performance_watermark`) — a real but modest increase in moving parts,
  the minimum needed to make "views" and "purchases" mean the same all-time window on day 1 and
  day 100.
- Surfaces as a dashboard card (`ordo/productperformance/index`), own ACL resource
  (`Ordo_Automation::product_performance`), no new top-level `menu.xml` entry — same convention
  the RFM report already established for sub-features.
