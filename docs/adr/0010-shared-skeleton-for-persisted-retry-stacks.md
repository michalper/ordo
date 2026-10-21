# ADR 0010: Share one skeleton across the three persisted-retry stacks

## Status

Accepted.

## Context

ADR 0003 (persisted retry for campaign dispatch) and ADR 0004 (per-subscription retry for push)
deliberately gave each retry stack its own table and its own retry unit. The implementations,
however, had grown three byte-identical copies of everything around those units: the cron's
claim/attempt/reschedule/dead-letter loop (`Cron/RetryFailed{CampaignActions,MessageSends,PushSends}.php`),
the resource models' atomic `claim()` UPDATE, the collections' `addDueFilter()`, the queues'
exponential-backoff `nextRetryAt()` math, and (in `Model/CampaignActionRetry.php`) a copy of the
accessor trio that `Model/RetryRecordFieldsTrait.php` already provides. The queue docblocks even
justified the duplication ("a separate class … even though the math is identical today") — but
that reasoning covers the *queues and tables*, which retry structurally different things, not the
mechanical code around them, and three copies had already begun to need three identical fixes per
change.

## Decision

Keep the tables, queues and retry units separate, exactly as ADRs 0003/0004 require. Deduplicate
only the mechanics around them:

- `Cron/AbstractRetryFailedCron.php` (modeled on `Cron/AbstractPriceWatchScanCron.php`) owns the
  claim/attempt/reschedule/dead-letter loop. A subclass supplies what a single attempt means and
  the labels/limits around it; `attempt()` reports non-failure resolutions through
  `Cron/RetryAttemptOutcome.php` (sent / dead-lettered / dropped) instead of each cron hand-rolling
  that bookkeeping. Each cron keeps its own public `MAX_ATTEMPTS` (referenced by tests), passed
  down rather than unified.
- `Model/ResourceModel/ClaimsRetryRowTrait.php` + `RetryRowResourceInterface.php` carry the shared
  atomic `claim()`; `Model/ResourceModel/RetryDueFilterTrait.php` carries `addDueFilter()`, so the
  claim and its "due" filter can't drift apart.
- `Model/Retry/RetryBackoff.php` holds the one copy of the backoff math, parametrized by each
  queue's own `BASE_DELAY_MINUTES`/`MAX_DELAY_MINUTES` constants — the values are identical today,
  but each queue keeps owning its cadence.
- `Model/RetryRecordInterface.php` names the `attempts`/`last_error`/`next_retry_at` trio that
  `Model/RetryRecordFieldsTrait.php` implements (now adopted by `Model/CampaignActionRetry.php`
  too), so the shared plumbing can type against "a retry row" regardless of table.

## Consequences

- A fix to claiming, backoff or dead-lettering lands once instead of three times, and the three
  stacks can no longer drift behaviorally by accident.
- Diverging one stack's loop now means overriding a hook (or, if a hook doesn't fit, promoting the
  divergence into this ADR's successor) instead of editing a private copy.
- One deliberate unification of edge-case behavior: a `delete()` that throws during the push cron's
  dropped-subscription path now hits the shared failure/backoff path like every other attempt
  failure, instead of aborting the whole cron run.
- The tables, queue classes and `webapi`/cron entry points are untouched; ADRs 0003/0004 stand.
