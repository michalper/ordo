# ADR 0007: Deduplicate campaign entry with a pending-row check, not a DB unique constraint

## Status

Accepted. Introduced in #45.

## Context

A customer already mid-flow in a campaign (waiting on a `delay_minutes` resume) could be entered
into the same campaign again by a new trigger, accumulating a second, independent action chain in
parallel. This codebase usually deduplicates with a DB unique constraint.

## Decision

`ordo_campaign_scheduled_action` got a nullable `customer_id` column (denormalized from the
dispatch context, where the customer identity previously lived only inside the row's JSON
`context` blob) and a `(campaign_id, customer_id, executed_at)` index.
`Model/Campaign/CampaignEntryGuard.php::hasPendingEntry()` checks for a still-unexecuted row, and
`CampaignDispatcher::dispatch()` / `dispatchScheduledTrigger()` skip entering the campaign from
action 0 if one exists.

It is deliberately not a unique constraint: a completed chain leaves a row with `executed_at`
set, and a customer can legitimately re-enter the same campaign on a future, unrelated trigger.
Only unexecuted rows count as "still in flight".

## Consequences

- A customer can be in a given campaign at most once at a time, but can re-enter it later.
- `resumeScheduledAction()` stays unguarded, since it continues an already-entered chain rather
  than starting a new one.
