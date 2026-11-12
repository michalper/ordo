# ADR 0050: Surface CLV and churn risk on Customer 360

## Status

Accepted.

## Context

A second-round brainstorm candidate (2026-11-12) flagged a real gap: `Model\Customer360\
Customer360SnapshotBuilder::build()` already pulls lead score, loyalty tier, RFM, tags, NPS, and
matching segments onto one page, but never calls `Model\Clv\ClvCalculator::getProjectedClv()` or
`Model\ChurnRisk\ChurnRiskCalculator::getChurnRiskRatioForCustomer()` — both single-customer
getters that already exist and already back their own segment conditions (`clv_at_least`,
`churn_risk_at_least`). A rep looking at one customer today has to go build a segment to find out
whether that customer is even eligible to match either condition.

## Decision

- **Zero new calculation logic.** `Customer360SnapshotBuilder` now injects `ClvCalculator`/
  `ChurnRiskCalculator` and calls their existing single-customer getters, same as every other
  field it already gathers (`CustomerScoreManager`, `RfmCalculator`, etc.) — purely wiring
  already-computed values into the existing snapshot and its view template.
- **`Customer360Snapshot` gets two new readonly fields**: `projectedClv` (always a `float` — 0.0
  is `ClvCalculator`'s own "no order history" floor, not a null/unknown case) and
  `churnRiskRatio` (`?float` — null means "fewer than 2 non-canceled orders", `ChurnRiskCalculator`'s
  own "no baseline interval to derive a ratio from" case). The template renders the null case as
  "Not enough order history", distinct from CLV's 0.0 which renders as a real currency value
  (`$0.00`) — the two calculators have genuinely different "no data" semantics and the UI
  reflects that rather than flattening both into one generic placeholder.
- **Both new stat rows join the existing "RFM / order history" card**, not a new card — CLV and
  churn risk are both order-history-derived metrics, the same grouping `RfmCalculator`'s own
  recency/frequency/monetary fields already use on this screen.

## Consequences

- A rep can now see, on the same screen as everything else this module knows about a customer,
  whether they're a high-CLV or churn-risk-flagged customer without building a throwaway segment
  first — the stated goal of the ROADMAP candidate.
- `Customer360SnapshotBuilder::build()` now runs two more single-customer queries
  (`ClvCalculator`/`ChurnRiskCalculator`'s own `sales_order` lookups) per page view — acceptable
  for a human looking up one customer at a time, the same "single-customer path, not the
  whole-base one" tradeoff this builder's own class docblock already states for segment matching.
