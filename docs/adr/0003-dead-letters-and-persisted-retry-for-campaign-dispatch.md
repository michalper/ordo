# ADR 0003: Persist failed campaign dispatches as dead letters and retry failed resumes with backoff

## Status

Accepted. Introduced in #73.

## Context

`Model/Queue/CampaignDispatchConsumer.php` silently dropped anything it couldn't process: an
undecodable message, or an exception `CampaignDispatcher::dispatch()` didn't swallow itself,
propagated uncaught and vanished with only a framework-level error log.
`Cron/RunScheduledCampaignActions.php` had a similar gap: a failed resume of a delayed action was
"marked executed and stayed failed" forever. In both cases a broken campaign stopped working with
nothing visible to the merchant.

## Decision

- The consumer catches those failures and persists them to `ordo_campaign_dispatch_dead_letter`
  (trigger event, raw message, error). Nothing auto-reprocesses these yet — they exist so a broken
  dispatch is visible instead of silent.
- The first time a scheduled-action resume fails, it is enqueued in `ordo_campaign_action_retry`
  (`Model/Campaign/ActionRetryQueue.php`). `Cron/RetryFailedCampaignActions.php` re-attempts due
  rows every 10 minutes with exponential backoff (5 min, 10 min, 20 min, ... capped at 120 min),
  claimed with the same atomic-conditional-UPDATE pattern `ResourceModel\Campaign\ScheduledAction::claim()`
  uses.
- A row still failing after `MAX_ATTEMPTS` (5) is left in place as a permanent dead letter rather
  than retried forever.

## Consequences

- Failures become inspectable data instead of log lines.
- Dead letters are not replayed automatically; someone has to look at them.
- This covers `resumeScheduledAction()` itself throwing (e.g. a DB error). A send that exhausts
  `SendRetrier`'s own in-process retries is handled separately (it is logged to `ordo_message_log`
  before it would ever reach this cron); that gap was later closed by the per-channel send retry
  queues (`Model/Campaign/MessageSendRetryQueue.php`, and ADR 0004 for push).
