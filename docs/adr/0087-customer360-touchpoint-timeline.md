# ADR 0087: Customer 360 touchpoint timeline

## Status

Accepted.

## Context

`ROADMAP.md`'s 2026-11-28 brainstorm (second pass) found a real gap: `Customer360SnapshotBuilder::build()`
(docs/adr/0050) aggregates point-in-time summary stats - latest NPS score, matching segments, order
stats, CLV/churn-risk - but nothing in this module reads `ordo_message_log`/`ordo_message_log_event`/
`ordo_campaign_outcome_log` *per customer, in chronological order* anywhere. Every existing report
(`CampaignOutcomeLogger::getStats()`, `RevenueAnalytics`, `MessageEngaged`'s own condition query) reads
those tables aggregated across customers or scoped to one campaign, never as one customer's own
sent/opened/clicked/converted history laid out as a timeline - the question an admin actually asks
when a customer calls in ("what has this specific person actually received and done, in order?") had
no answer on this screen.

## Decision

- **A new `Model\Customer360\CustomerTouchpointReader::getTimeline(int $customerId)`**, merging three
  independent queries in PHP rather than one SQL `UNION` - `ordo_message_log` ("sent"),
  `ordo_message_log_event` joined back to `ordo_message_log` for channel ("opened"/"clicked"/"read"),
  and `ordo_campaign_outcome_log` where `acted_at IS NOT NULL` ("converted"). Each query is
  independently capped and sorted before the merge (`PER_TYPE_LIMIT = 50`), not the merged result -
  a customer with thousands of old sends but no recent activity would otherwise have "sent" rows
  alone crowd out every "converted"/"opened" row once a single global `LIMIT` ran across a `UNION`.
  Each row carries the matching `ordo_campaign.name` via a `LEFT JOIN` (null-safe - a since-deleted
  campaign, or a direct admin test send with no `campaign_id` at all, still produces a row).
- **A new `CustomerTouchpoint` read-only DTO** (`type`, `occurredAt`, `channel`, `campaignName`,
  `orderId`) added to `Customer360Snapshot` as a new `touchpoints` array field - gathered in
  `Customer360SnapshotBuilder::build()` alongside every other per-customer data source, same
  "nothing here is new data, just gathered into one place" philosophy the rest of that class already
  documents, with this one exception called out directly in its own docblock (it merges live rows,
  not a precomputed per-customer value).
- **Excludes `ordo_message_log` rows with `status` `opted_out`/`suppressed`** - the same exclusion
  `Model\ResourceModel\MessageLog\Collection::addRealSendAttemptFilter()` already applies for
  `FrequencyCapManager`, for the same reason: a suppressed/opted-out row never actually reached the
  customer, so showing it as a "Sent" touchpoint would be misleading, not just noisy.
- **Rendered as a new "Touchpoint timeline" card** on the existing Customer 360 page
  (`view/adminhtml/templates/customer360/index.phtml`), one row per touchpoint (type badge, localized
  date via `Magento\Framework\Stdlib\DateTime\TimezoneInterface::formatDateTime()`, channel/campaign
  name/order number) - no new page, grid, or layout handle.

## Consequences

- Pure read-only SQL over existing tables, no new table or cron - same shape every other analytics
  addition in this module already uses.
- Capped at 50 rows per source type (so up to 150 merged, then nothing further trims the merged
  result) - deliberately not paginated. A customer with more activity than that sees only their most
  recent touchpoints per type; full historical detail for a single campaign already exists on that
  campaign's own Funnel widget, so this card's job is a quick cross-channel glance, not a
  from-the-beginning audit log.
- `CustomerTouchpointReader` is a new, separate read path alongside `CampaignOutcomeLogger::getStats()`
  and `Model\Campaign\Condition\MessageEngaged`'s own query - all three now read the same two tables
  for different shapes (aggregated-by-campaign, one-customer-one-campaign-scoped, one-customer-
  cross-campaign-chronological respectively). No shared query object was extracted - each shape's
  `WHERE`/`GROUP BY`/`ORDER BY` differs enough that a shared abstraction would need more parameters
  than it would save lines, the same judgment call this module's `Test/Mftf/SCENARIOS.md` has
  recorded for similarly-shaped-but-distinct report queries before.
