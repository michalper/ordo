# ADR 0031: Purchase funnel / drop-off report, scoped to registered customers only

## Status

Accepted.

## Context

A per-visitor purchase funnel ("how many people viewed a product, added to cart, started
checkout, completed an order") has been declined twice before in this exact form — both
`docs/adr/0015-product-performance-report.md` and `docs/adr/0017-revenue-channel-analytics-report.md`
note there is no `visitor_id` on `sales_order`, so a true per-visitor conversion funnel joining
anonymous browsing straight through to a specific order "can't be supported honestly" for product-
or order-level reports. Those declines were scoped to *those reports'* own per-SKU/per-order
shape, not to a differently-scoped funnel built from the pieces this module already has:
`ordo_visitor_event` (`page_view`/`product_view`/`category_view`/`element_clicked`, plus a new
`checkout_start` added here) and `Observer\StitchVisitorIdentity`'s anonymous-visitor-to-customer
backfill (already proven for push/price-watch subscriptions - see that observer's own docblock).

`ordo_visitor_event` itself is short-lived by design (`Cron\PruneVisitorEvents`, ~7 days, see that
table's own `db_schema.xml` comment) - any report built directly on it would lose history exactly
the way `Cron\CalculateProductPerformance` already had to solve for product views.

## Decision

- **Scoped to registered customers only, consistently across every stage** - not just the final
  "completed order" stage. `product_view`/`cart_add`/`checkout_start` events only count when
  `ordo_visitor_event.customer_id IS NOT NULL`, the exact same filter the "completed order" stage
  applies to `sales_order.customer_id`. This was a deliberate choice over "count every visitor at
  the top of the funnel, only known customers at the bottom": `StitchVisitorIdentity` backfills a
  visitor's PRE-login events with their customer_id once they log in (not just new events after),
  so this still covers someone who browsed anonymously and registered/logged in later in the same
  visit - what it excludes is a **pure guest checkout who never creates an account at all**, the
  same boundary ADR 0015/0017 already drew. Mixing "every visitor" at early stages with "only
  known customers" at later ones would have made the drop-off percentages between those stages
  structurally meaningless (inflated by a tracking-coverage gap, not real abandonment) - a
  dishonest number is worse than a narrower, honest one.
- **`checkout_start` is a new, publicly-loggable event type** (`Controller\Track\Event`'s own
  allow-list), fired the same documented way `product_view`/`category_view` already are - the
  theme calls `window.ordoTrack('checkout_start')` on its checkout template; there is no automatic
  page-type detection in the dependency-free JS tracker (see that file's own docblock), so this
  adds no new mechanism, just a new allowed value using the one that already exists.
  `Observer\TrackCartAdd` (`cart_add`) is already logged-in-only by its own existing design (see
  its own docblock) and needed no change at all - it already matches this report's cohort
  definition.
- **A new materializing table, `ordo_funnel_daily_stats`** (`date` unique, `product_view_visitors`/
  `cart_add_visitors`/`checkout_start_visitors`) for the three stages whose source
  (`ordo_visitor_event`) gets pruned - same reasoning `ordo_product_performance` already
  established. **No column for the "completed order" stage** - `sales_order` is never pruned, so
  `Model\ResourceModel\FunnelReport\Grid\Collection` joins it live at report-render time instead,
  the exact precedent `ProductPerformance\Grid\Collection` already set for purchase counts.
- **`Cron\CalculateFunnelMetrics` recomputes a day fresh (UPSERT), it does not advance an
  irreversible watermark** like `CalculateProductPerformance` does - unlike product views (where a
  row's SKU never changes after the fact), a visitor-event row's `customer_id` CAN change after
  the fact (the `StitchVisitorIdentity` backfill on a later login), so a one-shot "never revisit
  this row again" watermark would permanently undercount anyone who logs in after their first
  browsing activity on a given day. Daily, it recomputes whichever day is `today - 2` (a 2-day
  settling buffer, comfortably inside the 7-day retention window) from scratch via `COUNT(DISTINCT
  customer_id)` for that date, so a same-day login has a full extra day to be stitched before that
  day's numbers are read and written.

## Consequences

- Guest checkouts (and anonymous visitors who never register at all) are entirely excluded from
  every stage of this report, not just the last one - a store with a large guest-checkout share
  will see a funnel covering a minority of its real traffic. This is disclosed on the report page
  itself (`listing_intro.phtml`, same convention every other report's known-limitation banner
  uses), not hidden.
- The funnel has up to a 2-day reporting lag by design (the settling buffer) - this is a trend
  report, not a real-time dashboard, the same freshness expectation `ProductPerformance`'s own
  30-minute-cadence-but-still-eventually-consistent view already sets.
- `checkout_start` tracking requires the merchant's theme to add one `window.ordoTrack(...)` call
  to its checkout template, same integration burden `product_view`/`category_view` already carry -
  a store that never adds it simply sees that stage (and drop-off into/out of it) as zero, an
  honest "not instrumented" reading, not a silent fabrication.
