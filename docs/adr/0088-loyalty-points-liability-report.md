# ADR 0088: Loyalty points liability & redemption report

## Status

Accepted.

## Context

`ROADMAP.md`'s 2026-11-28 brainstorm found a real gap: `ordo_loyalty_points_ledger` is append-only
(earn/redeem/expire rows, balance = `SUM(points_delta)`, per its own schema comment and
`Model\LoyaltyPointsManager`/docs/adr/0044), but nothing in this module reads it in aggregate. A
store running the points program has no way to see outstanding point liability (the unredeemed
balance across every customer - the real "owed" number a finance team needs), redemption rate, or
an earn-vs-expire trend over time.

## Decision

- **A new standalone report, `ordo/loyaltypointsreport/index`**, same shape as
  `Controller\Adminhtml\CohortRetention\Index` (a satellite page linked from the Dashboard's
  "Audience & Targeting" card grid, not a top-level admin menu item - `activeMenu()` marks the
  shared `Ordo_Automation::campaigns` node, same as every other report page in this module).
- **One row per calendar month**, aggregated by `Model\ResourceModel\LoyaltyPointsReport\Grid\
  Collection`, which replaces its `SearchResult`'s `FROM` clause entirely in `_initSelect()` -
  the same deliberate one-off departure `CohortRetention\Grid\Collection`'s own docblock already
  explains and flags as the only other place doing it. There is no month entity table to join
  against here either.
- **`outstanding_liability` is a running total** (`SUM(net) OVER (ORDER BY month)`, the same
  window-function technique `CohortRetention\Grid\Collection`'s own `ROW_NUMBER() OVER` already
  establishes as safe for this module's MySQL 8 target). The latest row's value is the real,
  current, store-wide unredeemed/unexpired point balance across every customer right now - the
  exact "owed" number the ROADMAP candidate asked for - so no separate summary widget or REST
  endpoint was added just to surface that one number; it falls out of the trend column for free.
- **`redemption_rate` is scoped to that month only** (`redeemed ÷ earned × 100` for that month's
  own rows), guarded with `NULLIF(earned, 0)` rather than a bare division - a month with zero
  points earned reads `null`, not `0`, since "nobody redeemed yet" (a real 0%) and "nothing to
  redeem against" (no meaningful rate at all) are different facts an admin reading the grid
  shouldn't have to tell apart by eye.

## Consequences

- Pure read-only SQL over an existing table - no new table, column, or cron, exactly matching the
  ROADMAP candidate's own framing.
- `Test/Integration/LoyaltyPointsReportGridCollectionTest.php` backdates its fixture rows 35-37
  months into the past, deliberately far from "now" - the only other real-DB writer to
  `ordo_loyalty_points_ledger` in this suite (`LoyaltyWalletPassEndpointTest`) dates its own row at
  the current moment and never cleans it up (a real, pre-existing gap in that test, left
  untouched here - fixing someone else's test's cleanup is out of scope for this change). Picking
  months nothing else in the suite has ever written to makes the running-total assertions fully
  deterministic instead of flaky against accumulated dev-database history.
- No filter/drill-down by customer - this report answers the store-wide liability question
  ROADMAP asked for, not "which customers hold the most points" (that's a different report, not
  requested here, and would need a new per-customer-ranked view rather than extending this one's
  month-bucketed shape).
