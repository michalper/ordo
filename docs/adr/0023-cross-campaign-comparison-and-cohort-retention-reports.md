# ADR 0023: Cross-campaign comparison and cohort retention as two separate read-only reports

## Status

Accepted.

## Context

ROADMAP.md's "Candidate new features" list asked for "a cross-campaign revenue/LTV comparison
view, and cohort/retention analysis — ranks/compares campaigns against each other, or groups
customers by first-purchase month/campaign and tracks how their repeat-purchase rate evolves over
time, on top of the existing per-campaign funnel view and `RfmCalculator`/`ClvCalculator`."

These are two genuinely different shapes of data, not one report with two sections:
- Ranking campaigns against each other is a flat, one-row-per-campaign comparison.
- Cohort retention is inherently a matrix (cohort month × months-since-first-purchase), with no
  natural "campaign" dimension at all once a customer is grouped into a cohort by first-order
  month.

Combining them into one page would force one of the two into an awkward shape just to share a
route. Every other analytics report this module has shipped (`docs/adr/0015`, `docs/adr/0017`) is
its own controller/grid/ACL resource, so splitting these into two reports matches that convention
rather than deviating from it to save a route.

## Decision

- **`Controller\Adminhtml\CampaignComparison\Index`** / `Model\ResourceModel\CampaignComparison\
  Grid\Collection` — one row per campaign (not per split-test variant; `Model\CampaignFunnelStats`
  already gives the per-variant breakdown for a single campaign's own edit page). `ordo_campaign`
  is the main table, so a campaign that has never sent anything still shows as an honest 0 row
  instead of being silently omitted.
  - `sent`/`converted`/`conversion_rate` come from `ordo_campaign_outcome_log`, grouped by
    `campaign_id` only.
  - `revenue` comes from `ordo_campaign_attribution` (`Model\Campaign\AttributionCalculator`'s
    equal-weight multi-touch split, already unique per `order_id`+`campaign_id`) — **not**
    `ordo_campaign_outcome_log.order_id`, which isn't unique (`markActed()` can credit more than
    one row to the same order) and would double-count revenue the same way
    `RevenueAnalytics\Grid\Collection` had to guard against with its own `MIN(campaign_id)`
    tie-break. Using the already-deduplicated attribution table sidesteps that problem entirely
    instead of re-solving it.
  - `avg_converted_clv` reads `ordo_customer_clv_score` (`Model\Clv\ClvCalculator`'s stored
    projection, not recomputed here) averaged across the *distinct* customers each campaign
    converted — `null`, not `0`, when a campaign hasn't converted anyone yet, so "no data" can't
    be misread as "converts low-value customers."
- **`Controller\Adminhtml\CohortRetention\Index`** / `Model\ResourceModel\CohortRetention\Grid\
  Collection` — one row per `(cohort_month, months_since_first_purchase)` pair. A cohort is a
  registered customer's own first non-canceled order's calendar month (guest orders have no
  identity to track repeat purchases against, so they're excluded, same reasoning
  `RevenueAnalytics\Grid\Collection`'s new-vs-returning join already gives). "Retained" at offset N
  means placed another non-canceled order N calendar months after their own first one — MySQL's
  `PERIOD_DIFF()` does the month arithmetic, so offset 0 is always 100% by definition. No
  materialized table or cron: unlike `docs/adr/0015`'s product performance report (whose source
  data is pruned after ~7 days), `sales_order` is never pruned, so this computes live on every
  grid request, the same `docs/adr/0017` philosophy.
  - This is the one Grid\Collection in the module that **replaces** its base `SearchResult`'s
    `FROM` clause (`$this->getSelect()->reset(Select::FROM)`) instead of extending it with joins —
    every other report grid has a real per-row entity (an order, a product, a campaign) to join
    onto; a cohort row has no natural entity of its own, it's a pure aggregate. Flagged here
    explicitly since it's a deliberate, one-off departure from this module's usual "extend the
    real select, don't replace it" shape.
  - No campaign dimension: attributing a *cohort's* retention to "the campaign that brought them
    in" would need "which campaign (if any) converted this specific customer's first order" — a
    real, derivable join (first-order-id ∩ campaign-attribution-by-order-id) that doesn't exist
    anywhere in this codebase yet. Deferred rather than bolted on half-built; if real demand
    surfaces for cohort-by-acquisition-campaign specifically, that's its own follow-up, not a
    column squeezed into this report to technically cover the roadmap bullet's wording.
- Both reports: standard read-only listing (no mass actions, `listing_intro.phtml` explaining
  scope/limitations), own ACL resource (`campaign_comparison` sortOrder 750, `cohort_retention`
  sortOrder 760), no menu entry (this module's menu is a single flat item — every report surfaces
  as a dashboard card instead, same as every other report before it), cards added to the
  dashboard's existing "Audience & Targeting" group right after Revenue Analytics.

## Consequences

- A merchant can now rank campaigns against each other by revenue/conversion/customer-quality in
  one place, instead of opening each campaign's own funnel view one at a time.
- Cohort retention gives a repeat-purchase curve per acquisition month, independent of which
  campaign (if any) was involved — a different, complementary question to campaign comparison, not
  a breakdown of it.
- Neither report needed a new table, cron, or computation class — both are pure live SQL over
  tables this module already writes (`ordo_campaign`, `ordo_campaign_outcome_log`,
  `ordo_campaign_attribution`, `ordo_customer_clv_score`, `sales_order`), matching ADR 0017's
  "compute live, don't materialize" precedent rather than ADR 0015's cron+table one.
- Cohort-by-acquisition-campaign (grouping retention curves by which campaign brought the cohort
  in, not just by calendar month) remains an open gap, tracked in ROADMAP.md, not solved here.
