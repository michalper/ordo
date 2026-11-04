# ADR 0016: "Opened/clicked campaign" condition reuses existing tracking, adds no new infrastructure

## Status

Accepted.

## Context

Fourth of the five analytics/reporting-gap roadmap items. `ROADMAP.md`'s original wording
("email open/click tracking") turned out to be stale: an earlier commit (`32a2955`) already
shipped the hard infrastructure —

- `ordo_message_log_event` (one row per open/click, FK to `ordo_message_log`, `event_type`
  `opened`/`clicked`, nullable `url` for click targets).
- `Controller\Email\StatusCallback` already handles SendGrid's Event Webhook `open`/`click`
  events, writing them via `Model\MessageLogEventWriter`.
- `Model\CampaignFunnelStats`/the campaign edit page's Funnel widget already surface per-campaign
  open/click-rate reporting from this data.
- SendGrid's own account-level "Open Tracking"/"Click Tracking" settings inject the pixel and
  rewrite links — this module never needed to build either itself.

**What was actually missing**: a condition that reads this already-captured data, so a marketer
can build a follow-up campaign/segment targeting customers by engagement with an earlier one.

## Decision

1. **New `Model\Campaign\Condition\MessageEngaged`**, implementing both `ConditionInterface` and
   `Api\Campaign\BulkResolvableConditionInterface` — mirrors `ClvAtLeast`'s shape, not the older
   `EventOccurred` (which predates `BulkResolvableConditionInterface` and needed a dedicated
   `SegmentMemberResolver` switch arm). Implementing the interface means
   `SegmentMemberResolver`'s existing `default:` arm picks it up automatically — zero changes to
   that switch.
2. **New `Model\Email\MessageEngagementResolver`**, reusing the exact join
   `Model\CampaignFunnelStats::getSendStats()` already uses (`ordo_message_log_event` ⋈
   `ordo_message_log` on `message_log_id`/`entity_id`, filtered by `campaign_id`/`event_type`) —
   adding a `customer_id` filter for the single-customer case and a `created_at` cutoff for
   `within_days`, same idiom `Model\Event\EventOccurredResolver::cutoff()` uses.
3. **Params use `engagement_type`/`engagement_within_days`, not `event_type`/`within_days`** —
   deliberately different field names from `EventOccurred`'s own same-shaped params. Both
   conditions' admin forms are legacy `dynamicRows` switcher-toggled single-row forms where a
   field name is shared state across every condition type in that row; reusing `event_type` would
   mean sharing one `OptionSourceInterface`/notice text between two concepts that don't match
   (`EventOccurred`'s is `cart_add`/`wishlist_add` against `ordo_visitor_event`'s 7-day retention;
   this condition's is `opened`/`clicked` against `ordo_message_log_event`, which isn't pruned at
   all — see point 4).
4. **No retention/pruning cron exists for `ordo_message_log`/`ordo_message_log_event`** —
   confirmed: no `Prune*` cron touches either table, unlike `ordo_visitor_event`
   (`Cron\PruneVisitorEvents`, `Helper\Config::getTrackingRetentionDays()`). Rows accumulate
   forever today. Out of scope for this change — flagged explicitly in the new admin field's own
   notice text and as a separate, small `ROADMAP.md` candidate item, not silently left undocumented.

## Consequences

- Admin UI: wired into both editors that actually read `ConditionPool` — the campaign Drawflow
  canvas (`Block\Adminhtml\Campaign\Edit\Flow::getFieldsConfig()`) and the segment `dynamicRows`
  editor (`ordo_segment_form.xml`'s switcherConfig, following its own documented convention of one
  dedicated field set per registered condition type).
- `Model\Segment\SegmentSaveProcessor`/`Model\Campaign\CampaignSaveProcessor`'s own
  `DEDICATED_PARAM_FIELDS` lists both needed the 3 new field names added, or the posted values
  would never reach the condition's `params_json` at all — same mechanism every other
  dedicated-field condition already relies on.
- No schema change, no webhook change, no new reporting surface — this is additive at the
  condition-type layer only.
