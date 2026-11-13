# ADR 0053: Split-test winner by revenue, not just click-through rate

## Status

Accepted.

## Context

A third-round brainstorm candidate (2026-11-13) flagged a real gap: `Model\Campaign\
SplitWinnerCalculator::decideOne()` reads `ordo_message_log_event`'s opened/clicked counts
exclusively to pick a split action's winning variant. But `Model\CampaignOutcomeLogger::
getStats($campaignId)` *already* returns sent/converted/conversion_rate/revenue grouped by
variant (the same data the campaign edit page's own funnel report already shows) - a variant
with the higher click-through rate isn't necessarily the one that actually makes more money; a
clickbait-y subject line can win on CTR while converting worse, or converting customers to
lower-value orders.

## Decision

- **A new config choice (`Model\Config\Source\AbTestDecisionMetric`: CTR / conversion rate /
  revenue), not a wholesale behavior change.** Defaults to CTR - the original behavior - so a
  store that already has auto-winner enabled sees no change in what it optimizes for until an
  admin deliberately picks something else. `Helper\Config::getAbTestDecisionMetric()`.
- **Conversion rate/revenue are read from the existing `CampaignOutcomeLogger::getStats()` data,
  not a new ledger.** `SplitWinnerCalculator` gains a second private fetcher
  (`fetchOutcomeStats()`) alongside the existing `fetchStats()` (CTR, from `ordo_message_log`/
  `ordo_message_log_event`) - the two tables measure genuinely different things (opens/clicks vs.
  whether the customer actually ordered afterward), so neither replaces the other; the configured
  metric decides which one `decideOne()` reads for a given pass.
- **The minimum-sample-size gate uses whichever table the chosen metric reads from.** CTR mode
  gates on `ordo_message_log`'s own sent count; conversion-rate/revenue mode gates on
  `ordo_campaign_outcome_log`'s own sent count - the two aren't guaranteed to be identical (outcome
  logging is additive, triggered at send time alongside message logging, but from a different call
  site), so each metric stays self-consistent about what "enough data" means for its own source.

## Consequences

- A merchant can now choose to let a split test converge on whichever variant actually drives
  revenue, not just the one people click most - directly useful for promotional campaigns where
  the headline click doesn't always correlate with the resulting order value.
- `AutoPickCampaignSplitWinner`'s own cron, docblocks, and existing CTR-only tests needed no
  changes beyond what `SplitWinnerCalculator` itself required - the decision is still one-shot and
  permanent per split action exactly as before, regardless of which metric decided it.
