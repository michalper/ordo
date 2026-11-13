# ADR 0052: Churn-risk customers in the sales-rep digest

## Status

Accepted.

## Context

A third-round brainstorm candidate (2026-11-13) flagged a real gap: `Cron\SendSalesRepDigest`
groups customers by `TagInactiveCustomers::TAG_INACTIVE` and `TagReorderCycleAtRiskCustomers::
TAG_REORDER_AT_RISK` only. The newer, more general `churn_risk_at_least` signal
(`Model\ChurnRisk\ChurnRiskCalculator`, ADR 0045) has no persistent tag at all — unlike
reorder-cycle risk, nothing ever calls `CustomerTagManager::addTag()` for a churn-risk customer,
so there's structurally no tag for the digest to group by, even though the underlying signal is
already good enough to back a segment/campaign condition.

## Decision

- **New `Cron\TagChurnRiskCustomers`, mirroring `Cron\TagReorderCycleAtRiskCustomers`'s exact
  add-then-clear-on-recovery shape** — tags a customer `churn_risk` when `ChurnRiskCalculator::
  getChurnRiskRatiosForAllCustomers()` puts them at or above a standing threshold, clears the tag
  once they recover (ordered again). Pure data classification; `Cron\SendSalesRepDigest` is the
  one that emails.
- **Its own config group (`ordo_scoring/churn_risk`), not a shared toggle.** `ChurnRiskCalculator`
  itself is always-on (it backs the `churn_risk_at_least` segment condition unconditionally,
  same as CLV), so there's no natural existing master switch to reuse the way
  `TagReorderCycleAtRiskCustomers` reuses `isReorderReminderEnabled()` — churn risk isn't tied to
  the reorder-reminder feature area. `isChurnRiskDigestEnabled()` + `getChurnRiskAtRiskRatio()`
  (default 0.75, same reasoning and same default as `getReorderAtRiskDriftRatio()`: noteworthy
  before a customer would be considered fully overdue).
- **`SendSalesRepDigest` gets a third parallel group** (`groupCustomersByRep(TagChurnRiskCustomers
  ::TAG_CHURN_RISK)`), alongside the existing inactive/at-risk ones — same "one digest per rep,
  not one per signal" shape, same `has_X`/`X_count`/`X_customer_names` template variable triple
  per section so a rep with only one signal still gets exactly one email.

## Consequences

- A rep now sees churn-risk customers in the same weekly digest as inactive and reorder-at-risk
  ones, without the admin having to build a throwaway segment/campaign just to get a list.
- Three independent cron jobs now classify three overlapping-but-distinct "at risk" signals
  (inactive, reorder-cycle-at-risk, churn-risk) — a customer can legitimately appear in more than
  one section of the same digest email if they match more than one signal, which is intentional:
  each signal measures something genuinely different (no purchase at all vs. drifted from a
  SKU-specific cycle vs. drifted from their own overall cadence).
