# ADR 0075: A "segment blast" campaign trigger - one-time/scheduled send to a segment

## Status

Accepted.

## Context

`ROADMAP.md`'s 2026-11-22 brainstorm flagged a real gap, grep-verified against the actual trigger
constants rather than trusted from README/ROADMAP prose: every registered trigger either reacts to
a real event, or - like `TRIGGER_SCHEDULED_AT`/`TRIGGER_RECURRING_SCHEDULE` - fires campaign-wide
with no `customer_id` in its own context at all (`Model\Campaign\ScheduledTriggerScanner::fire()`
confirms this directly). There is no primitive for "send this campaign now, or at a scheduled
time, to every current member of segment X" - one of the most basic features almost every
marketing-automation/ESP platform ships, and something `docs/adr/0020`'s own campaign-template-
library work already found missing when it tried (and couldn't) build a composable win-back
template on the engine's pre-existing primitives.

## Decision

- **A new trigger_event** (`segment_blast`, `CampaignTriggerInterface::TRIGGER_SEGMENT_BLAST`)
  with two own params: `segment_id` (required) and an optional `scheduled_at` (same format/
  semantics as `TRIGGER_SCHEDULED_AT`'s own field - blank starts as soon as the next scan sees the
  trigger, a future datetime delays the first scan that actually dispatches). Registered the same
  way `customer_inactive`/`customer_anniversary` already are: a dedicated Flow-canvas field set
  (`Flow::getFieldsConfig()`), a `CampaignSaveProcessor::TRIGGER_FIELDS_BY_TYPE` entry, and a
  `TriggerEvent` option - no new Pool, since triggers have never had one (see that class's own
  docblock).
- **`segment_id`'s own admin field reuses `Model\Config\Source\SegmentOptions`** (already built for
  the ad-audience-sync segment picker) flattened via `Model\Campaign\OptionSourceFlattener::toMap()`
  - the exact same source/flattener pair `Campaign\Condition\InSegment`'s own `segment_id` field
  already uses, just wired into `Flow`'s constructor instead of a condition class's.
- **Cron-driven** (`Cron\DispatchSegmentBlastCampaigns`), the same shape every other cron-driven
  trigger here uses (`CLAUDE.md`'s "everything is a cron-driven scan against claim tables"
  convention) - there is no real Magento event for "a segment now has these members," the same
  reasoning `ScheduledTriggerScanner`/`DispatchCustomerInactiveCampaigns` already established.
  Scans every enabled campaign's `segment_blast` trigger every 5 minutes (matching
  `DispatchScheduledCampaignTriggers`'s own cadence, not `customer_inactive`'s nightly one - a
  blast's whole value is reasonably prompt delivery, not a slow-moving inactivity signal), resolves
  current membership via the existing `Model\Segment\SegmentMemberResolver::getMatchingCustomerIds()`,
  and dispatches per customer via `CampaignDispatcher::dispatchScheduledTrigger()` - the same
  specific-campaign-id entry point every other scheduled-shaped trigger already uses, not the
  generic `dispatch()` that fans out to every campaign listening for an event name.
- **A one-dispatch-per-(customer_id, campaign_id)-pair-forever claim** (`ordo_segment_blast_trigger_claim`,
  claimed via the existing `Model\Cron\ReminderLogStore::claim()`/`deleteMatching()` atomic-claim-
  then-rollback-on-failure pair) - the same shape `ordo_review_request_log` already uses, not
  `customer_inactive`'s cooldown-window re-firable shape: a segment blast has no notion of a
  customer "leaving and re-entering" to re-fire from.
- **Its own enable toggle** (`Stores → Configuration → Ordo Automation → Segment Blast Trigger`),
  gating only whether the scan runs at all - matching every other cron-driven trigger feature here,
  per `CLAUDE.md`'s "everything is config-gated per feature" convention.
- **Deliberately not snapshotted** - the segment is re-resolved live on every scan, not frozen into
  a materialized member list the moment the trigger becomes due. A two-phase "snapshot then drain"
  design (insert every then-current member as a pending row, dispatch from that frozen list only)
  was considered and rejected: it adds a second claim-row state (pending vs. sent), an empty-segment
  race (a blast configured before the segment has any members would need to distinguish "not yet
  populated" from "already fully drained"), and re-editing a fired blast's `segment_id` would need
  its own, separate handling to avoid either silently never re-snapshotting or double-claiming
  customers who overlap between the old and new segment. None of that complexity is justified for
  v1 - the simpler, already-proven `DispatchCustomerInactiveCampaigns` shape (claim per customer,
  re-resolve the segment every scan, let the claim table itself be the only "already handled"
  state) is correct and sufficient.

## Consequences

- A store can now build a genuine "send this campaign to segment X, now or at a scheduled time"
  flow in the existing Flow builder, closing the exact gap the 2026-11-22 brainstorm found.
- **Known v1 shape, not an oversight, documented directly in `CampaignTriggerInterface::
  TRIGGER_SEGMENT_BLAST`'s own docblock**: because the segment is re-resolved live rather than
  snapshotted, a customer who joins the segment *after* the blast's trigger became due still
  receives it on a later scan (claimed per customer, so never twice) - this is not a frozen
  point-in-time send to "whoever was in the segment at 09:00 sharp." A store wanting a strictly
  one-time send to today's members only should disable or delete the trigger once satisfied the
  initial batch has gone out.
- Re-editing a `segment_id`/`scheduled_at` already fired once is undefined/unsupported in v1:
  `ordo_segment_blast_trigger_claim` rows are keyed by `(customer_id, campaign_id)` only, not by
  which segment or schedule produced them, so pointing a campaign's existing `segment_blast`
  trigger at a *different* segment later could leave some overlapping customers permanently
  unreachable by the new configuration (already claimed under the old one). Build a new campaign
  for a genuinely different blast rather than repointing an existing one.
- No new MFTF scenario: the admin surface is the existing Flow canvas/classic trigger form (a
  `segment_id`/`scheduled_at` field pair, same shape `customer_inactive`'s `days` field already
  has), and nothing new is browser-observable beyond what `AdminCreateMultiTriggerCampaignTest`
  already exercises generically - same reasoning `SCENARIOS.md` already gives for
  `customer_inactive`/`customer_anniversary` needing no dedicated MFTF test of their own, an
  `Integration` test covering the real scan/claim/dispatch path is the correct and complete
  coverage here too.
