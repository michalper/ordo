# ADR 0029: Cohort retention narrowed by acquisition campaign

## Status

Accepted.

## Context

ADR 0023 shipped `Model\ResourceModel\CohortRetention\Grid\Collection` grouping retention purely
by calendar cohort month, and explicitly left "cohort-by-acquisition-campaign" as an open gap: at
the time, there was no derivable join from a customer's first order to "which campaign (if any)
converted it." `ordo_campaign_attribution` (`Model\Campaign\AttributionCalculator`) has since
existed for other reporting (`CampaignComparison`, ADR 0023 itself), closing exactly that gap - this
pass wires it into the cohort report.

## Decision

- **A new, optional `acquisition_campaign_id` filter**, not a new grouping dimension added to
  every row unconditionally. Unfiltered, the report is byte-for-byte the same pipeline ADR 0023
  shipped - `cohort_month` × `months_since_first_purchase` only, no regression to the existing,
  already-shipped view. When set, `Collection::addFieldToFilter()` special-cases this one field
  name and rebuilds the whole select pipeline (`buildCohortSelect()`) with the customer set
  narrowed to only those whose first order is attributed to that campaign, so `cohort_size`/
  `retention_rate` are genuinely recomputed against the narrowed set - not a column added to the
  aggregate output and then hidden post-hoc, which would leave the *numbers* unchanged while only
  hiding rows, defeating the point of asking "how did customers acquired by campaign X retain."
- **A plain WHERE on the final aggregate select was rejected** for the same reason - by the time
  a generic UI-component filter fires, `_initSelect()`'s subqueries are already assembled into
  literal SQL strings; narrowing after the fact can only filter which *output* rows show, not
  recompute the cohort math itself.
- **One campaign per customer, not every campaign that touched their first order** -
  `ordo_campaign_attribution` is multi-touch by design (an order can have more than one
  attribution row). `ROW_NUMBER() OVER (PARTITION BY order_id ORDER BY attributed_revenue DESC,
  campaign_id ASC)` picks exactly one (the highest-revenue-share touch, ties broken by the lower
  campaign_id) per order, so a customer is never double-counted into more than one campaign's
  cohort when filtering. The alternative - crediting the customer to every campaign that touched
  their first order - was rejected: it would let one real customer inflate more than one
  campaign's `cohort_size` at once, a worse distortion than picking a single, deterministic "main"
  campaign per order.
- **UI**: a filter-only, `visible="false"` column (`acquisition_campaign_id`) backed by the
  existing `Model\Config\Source\CampaignOptions` select source - no new column is added to the
  grid's own visible output, since no single campaign label would make sense on an unfiltered row
  that spans many campaigns.

## Consequences

- A merchant can now ask "how did customers acquired by campaign X retain compared to everyone
  else" by toggling the filter on and off - closing ADR 0023's own documented gap.
- A cohort month's customer, when the filter is unset, is still not attributed to any single
  campaign in the output - this report answers the campaign question only when explicitly asked
  (filtered), consistent with ADR 0023's original decision to keep the two questions (calendar
  cohort vs. acquisition campaign) separate rather than merging them into one default view.
- A customer whose first order has no `ordo_campaign_attribution` row at all (organic, no campaign
  involved) is excluded entirely when the filter is set - correct, since they were not acquired by
  any campaign.
