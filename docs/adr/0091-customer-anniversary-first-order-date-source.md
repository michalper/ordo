# ADR 0091: `first_order` date source for the `customer_anniversary` trigger

## Status

Accepted.

## Context

`ROADMAP.md`'s 2026-11-28 brainstorm (sixth pass) found that `Cron\DispatchCustomerAnniversaryCampaigns`'s
`date_source` trigger param only ever offers `dob`/`registration` - both plain `customer_entity`
columns. A store whose customers' meaningful "anniversary" is their first *purchase*, not their
account registration date (genuinely different for a customer who registered browsing, then
bought weeks later), has no way to build that campaign today, despite the cron already being
generic over "which date column to compare month+day against".

## Decision

- **A third `date_source` value, `first_order`**, added to the existing enum/switch in
  `Cron\DispatchCustomerAnniversaryCampaigns::candidateCustomerIds()` and to the Flow canvas
  trigger field's `options` in `Block\Adminhtml\Campaign\Edit\Flow.php` - no new trigger type, no
  new table, no new config field (there is nothing to tune: "already fired this calendar year" is
  still the whole claim rule, same as the other two sources).
- **No single column to read**: unlike `dob`/`created_at`, a customer's first order has no column
  of its own. Resolved with the same `MIN(sales_order.created_at)`-per-customer subselect shape
  `CohortRetention\Grid\Collection::buildCohortSelect()` already establishes for "this customer's
  own first order", wrapped in an outer month/day filter instead of that class's own
  cohort-bucketing. A guest order (`customer_id IS NULL`) never contributes a first-order date,
  same reasoning that class already applies.

## Consequences

- A customer with zero orders never matches the `first_order` option, the same "silently never
  fires for this customer" shape `dob`/`registration` already have for a customer missing that
  data.
- A customer whose very first order is later canceled/refunded still counts - this reads
  `sales_order.created_at` directly with no status filter, deliberately mirroring
  `CohortRetention`'s own first-order definition rather than inventing a stricter one just for
  this trigger.
