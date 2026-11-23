# ADR 0079: Per-customer claim closes a segment bulk-action queue-redelivery gap

## Status

Accepted.

## Context

A full-repo correctness audit (2026-11-23) flagged a real gap in
`Model\Queue\SegmentBulkActionConsumer`: Magento's message queue is at-least-once delivery - if
the consumer process crashes (deploy, OOM, server restart) after partially working through a
chunk's `customer_ids` loop but before the message is acknowledged, the broker redelivers the
same message. The consumer had no checkpoint at all, so a redelivered chunk would re-run its
action against every customer already processed on the crashed attempt, not just the ones never
reached. For `add_tag` this is harmless (`CustomerTagManager::addTag()` is itself idempotent),
but for `add_points` (`Model\Campaign\Action\AddPoints`, purely additive) a redelivery would
silently double-credit every customer processed before the crash.

## Decision

- **A new table, `ordo_segment_bulk_action_claim`, not a reuse of an existing one.** Keyed by
  `(batch_id, customer_id)` with a unique constraint - `Model\Queue\SegmentBulkActionClaimStore::
  claim()` always attempts the insert and treats the resulting `DuplicateException` as "already
  claimed," the same atomic-insert-relying-on-a-real-constraint idiom
  `CustomerTagManager::addTagIfAbsent()` uses. A per-row claim (not a single per-message flag) is
  what makes this correct for BOTH failure shapes: a crash partway through (the unprocessed tail
  of the list still needs to run on redelivery) and a crash just after finishing (every customer
  is already claimed, so the whole redelivered message becomes a no-op).
- **`batch_id` is a fresh UUID per published chunk** (`Magento\Framework\DataObject\
  IdentityGeneratorInterface`, generated in `SegmentBulkActionPublisher::publish()`), not derived
  from `segment_id`/`action_type` - the same segment and action type can legitimately be
  bulk-run again later as a deliberate, separate operation, and that must get its own fresh claim
  space, not collide with a previous run's.
- **No change to the action classes themselves** (`AddTag`, `AddPoints`) - the idempotency lives
  entirely in the consumer/claim layer, which protects every action type registered in
  `ActionPool` uniformly, regardless of whether that specific action happens to be naturally
  idempotent.
- **Pruned on a fixed 7-day retention** (`Cron\PruneSegmentBulkActionClaims`, extending the
  existing `AbstractSingleColumnRetentionPruneCron`) - a claim only needs to outlive the broker's
  own redelivery window (seconds to hours in practice), not accumulate forever the way an audit
  log would.
- **`CampaignDispatchConsumer` (the other queue consumer with the same theoretical redelivery
  exposure) is explicitly out of scope for this change.** It already has partial protection via
  `CampaignEntryGuard`'s dedup, a materially different message shape (one trigger/context per
  message, not a per-customer loop within one message), and sits on the highest-traffic, most
  failure-sensitive path in the module. Revisiting it is left as deliberate future work rather
  than bundled into this fix.

## Consequences

- One new table and one new cron job to maintain.
- `SegmentBulkActionPublisher::publish()` and `SegmentBulkActionConsumer::execute()` both gained
  a constructor dependency (`IdentityGeneratorInterface`, `SegmentBulkActionClaimStore`
  respectively); any other direct instantiation of either (none found outside DI/tests at the
  time of this change) would need updating too.
- A message with no `batch_id` (e.g. one already in flight from before this change deployed) is
  now dropped with a logged error rather than processed - acceptable since such a message is a
  transient artifact of the deploy itself, not a sustained pattern.
