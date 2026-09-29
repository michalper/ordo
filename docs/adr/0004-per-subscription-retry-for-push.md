# ADR 0004: Retry Web Push per subscription, not per campaign action

## Status

Accepted. Introduced in #125.

## Context

`send_email`, `send_sms` and `send_whatsapp` retry a failed send by re-running the whole action
through a persisted queue (`Model/Campaign/MessageSendRetryQueue.php`). `send_push` was
deliberately excluded from that queue: a single `execute()` fans out to every push subscription a
customer has registered, so re-running the whole action would risk re-sending to subscriptions
that already succeeded the first time.

## Decision

Push gets its own retry unit — a single subscription's send, not a whole action:

- `ordo_push_send_retry` table + `Model/Push/PushSendRetryQueue.php`, with the same
  exponential-backoff/atomic-claim shape as `ordo_message_send_retry`.
- `Model/Push/PushSubscriptionSender.php` is extracted from `SendPush`'s per-subscription loop and
  shared by `SendPush` and `Cron/RetryFailedPushSends.php`, so the two can't drift on what counts
  as a permanently dead subscription vs. a transient failure.
- The cron goes straight through `PushSubscriptionSender`, not `Model\Campaign\ActionPool`, since a
  retry isn't a whole campaign action. If the subscription no longer exists, the retry row is
  dropped.

## Consequences

- A customer with several devices never gets a duplicate push because one device failed.
- Push has a separate retry table and cron from the other channels, so the two retry paths have to
  be kept in mind separately.
