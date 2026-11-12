# ADR 0045: Churn-risk segmentation condition

## Status

Accepted.

## Context

A fresh brainstorm round (2026-11-12, checked against established marketing-automation/loyalty/
subscription-commerce platforms' public feature pages) flagged a real gap this module's own RFM
conditions don't close: every one of them (`RecencyDaysAtMost`, `OrderFrequencyAtLeast`,
`MonetaryTotalAtLeast`, the three `*PercentileAtLeast` conditions) reads a point-in-time snapshot
against the whole customer base, not a customer's own historical pattern.
`Campaign\Condition\ReorderCycleAtRisk` is the one existing self-relative "drifting from their
own baseline" signal, but it only has a ratio for a customer with an already-detected,
per-(customer, sku) cyclical pattern — `Cron\CalculateReorderCycle` requires a configurable
minimum number of orders of the *same* SKU (default 3) before it writes a row at all, so an
irregular or mixed-basket buyer never gets one and the condition fails closed for them, same as
it fails closed for a customer with zero detected cycles.

## Decision

- **A new `Model\ChurnRisk\ChurnRiskCalculator`**, byte-for-byte the same shape as
  `Model\ReorderCycle\ReorderCycleDriftCalculator` (per-process snapshot cache,
  `resetSnapshotCache()`, a single-customer path and a set-level
  `getChurnRiskRatiosForAllCustomers()` path that never drift apart on the same math) but
  computed at the **customer** level, over `sales_order` directly, not per-(customer, sku) — any
  mix of SKUs counts toward one customer's own order history. The ratio is elapsed days since
  the customer's last non-canceled order, divided by the **median** interval between their own
  past orders (same outlier-resistance reasoning `Cron\CalculateReorderCycle` already uses median
  over mean for: a single anomalous gap shouldn't skew the whole baseline). A customer needs at
  least 2 non-canceled orders to have an interval to measure at all — fewer fails closed, same
  "no data to compare against" convention as `RfmCalculator::getRecencyDays()` and
  `ReorderCycleDriftCalculator`.
- **A new `Model\Campaign\Condition\ChurnRiskAtLeast`** (`BulkResolvableConditionInterface`, so
  `Segment\SegmentMemberResolver` dispatches to its `resolveCustomerIds()` automatically with no
  resolver-side change needed — the same generic wiring ADR 0018 already established). Registered
  in `ConditionPool` as `churn_risk_at_least`, in the "Order & Purchase History" category
  alongside `reorder_cycle_at_risk`.
- **Its own dedicated param/field name, `churn_risk_ratio_at_least`**, deliberately not a reuse of
  `ReorderCycleAtRisk`'s `ratio_at_least`. `Model\Campaign\ParamFieldsNormalizer` reads/writes
  `$row[$field['name']]` directly from `ConditionInterface::getParamFields()`, so a dedicated
  field's name, its `getParamFields()` entry, and its saved params key must all be the same
  string — and the classic editor's `<field>` element has one fixed, non-dynamic label per field
  (`ordo_segment_form.xml`/`ordo_campaign_form.xml` have no per-selected-type label swapping,
  only visibility toggling via `switcherConfig`). Reusing `ratio_at_least` would mean reusing
  `ReorderCycleAtRisk`'s own field, whose classic-editor label ("Reorder Cycle Drift Ratio, At
  Least...") would be visibly wrong when editing a churn-risk condition instead.
- **Wired through every surface a condition type touches in this codebase**: `TypeLabels`
  (condition label + category), the classic `ordo_segment_form.xml`/`ordo_campaign_form.xml`
  dynamicRows `switcherConfig` (one new rule, one new false-action appended to every existing
  rule), and `segment-group-modal.js`'s nested-group `VALUE_FIELD_BY_TYPE` map (its own,
  self-contained per-type label, unrelated to the classic editor's fixed field label).

## Consequences

- Generalizes `ReorderCycleAtRisk`'s signal to every customer with at least 2 orders, not just
  ones with a detected SKU-specific cycle — a real, previously-unreachable churn-risk population
  (irregular buyers, mixed-basket shoppers) can now be targeted by a win-back/retention campaign
  or segment.
- `churn_risk_at_least` and `reorder_cycle_at_risk` can both fire for the same customer with
  different ratios (one SKU-specific, one whole-order-history) — not a conflict, just two
  different lenses on the same underlying "going quiet" signal; a campaign builder can use
  either, or both, depending on which pattern they want to catch.
- `segment-group-modal.js`'s own per-type label map is plain hardcoded English, not run through
  `$t()`/`__()` at all — a pre-existing gap this condition's new map entry inherits unchanged
  (ADR 0019 covered `campaign-flow-editor.js`/`tag-chip-editor.js`, not this file). Not fixed
  here — out of scope for a single new condition type, flagged for whoever eventually audits
  this file's i18n the same way ADR 0019 did for the other two.
