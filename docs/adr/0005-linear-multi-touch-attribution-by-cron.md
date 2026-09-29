# ADR 0005: Equal-weight linear multi-touch attribution, recomputed by cron

## Status

Accepted. Introduced in #117.

## Context

`Model/CampaignOutcomeLogger.php` (`ordo_campaign_outcome_log`) already answers "did this campaign
convert" with a first-plausible-match, single-touch heuristic. It credits one campaign per order,
under-crediting every other campaign that also touched the customer. Merchants need a second,
complementary answer: how much of an order's revenue each campaign should be credited with.

## Decision

`Model/Campaign/AttributionCalculator.php` writes `ordo_campaign_attribution` (one row per
order + campaign): an order's revenue split equally across every distinct campaign the customer
clicked through within a configurable lookback window (default 14 days), counting only the most
recent click per campaign. Two campaigns clicked before a 100 order get 50 each.

Linear was chosen over the alternatives deliberately (full reasoning in the class docblock):

- First-touch / last-touch credit only one campaign — exactly what the existing outcome log
  already does.
- Time-decay is defensible, but adds a decay-rate constant that needs tuning and is much harder
  for a merchant to explain ("why does this touch count for 61.8% and not 50%?"). Equal weight has
  no free parameter.

`Cron/ComputeCampaignAttribution.php` recomputes it hourly and idempotently (rows for an order are
deleted and reinserted each run), instead of an inline observer on order placement: an order's
attribution depends on the customer's whole recent click-through history, not on anything
available at the moment the order is placed.

## Consequences

- The table is additive; the single-touch outcome log is unchanged and still used where it was.
- Attribution lags orders by up to an hour.
- Re-running never double-counts, and a late click event is picked up on the next pass as long as
  the order is still inside the window.
