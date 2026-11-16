# ADR 0068: CampaignDispatcher fails closed when a known action type throws

## Status

Accepted.

## Context

A security/robustness audit found that `CampaignDispatcher::runOneAction()` calls
`ActionInterface::execute()` with no local exception handling at all. Tracing the actual
propagation:

- In `dispatch()` and `dispatchScheduledTrigger()`, the exception bubbles up through
  `runOneActionOrSplit()` → `runActionsFrom()`'s loop → `runCampaign()`, and is caught by each
  method's own per-campaign `try`/`catch` — so today an action's exception only aborts the rest of
  *that one campaign's* action chain; other matched campaigns, and other customers, are
  unaffected. This part already behaved reasonably, just by accident of exceptions propagating
  through several layers of callers that each happened to have a catch.
- In `resumeScheduledAction()` (the path `Cron\RunScheduledCampaignActions` uses to resume a
  `delay_minutes`-paused chain), there was **no catch at all**. The exception propagated to the
  cron, which logged it and enqueued a retry via `ActionRetryQueue` — but that retry re-runs from
  the same `resume_action_id`, re-running any actions between the resume point and the actual
  failure that had already succeeded.

Separately, this module already has an established fail-closed convention for an *unknown*
action/condition type (one this install's `di.xml` doesn't register): skip it, log it, don't crash
the dispatch. A *known* type's own thrown exception had no equivalent treatment.

## Decision

Extend the same fail-closed philosophy to a known action's thrown exception: wrap the single call
site that runs one action (`runOneActionOrSplit()`) in a new private
`runOneActionOrSplitStoppingChainOnFailure()`, used by both `runActionsFrom()`'s loop and
`resumeScheduledAction()`'s direct call onto the just-elapsed action. On a caught exception, log it
and signal the caller to stop — no further action in that chain runs, and the exception is never
re-thrown.

The alternative — catch, log, and *continue* to the next action — was rejected: a later action in
the same chain can depend on context an earlier, now-failed action never wrote (a generated coupon
code, AI-generated content substituted into a message), so continuing past a failure risks sending
something that references data that was never produced. Stopping the chain matches what already
happens today for `dispatch()`/`dispatchScheduledTrigger()` (even if only incidentally, as
described above) and brings `resumeScheduledAction()` in line with it instead of leaving it as the
one path with no containment at all.

## Consequences

- `resumeScheduledAction()` no longer lets an action's exception escape uncaught — a failure there
  is logged once and the chain stops, instead of propagating to `RunScheduledCampaignActions`'
  retry (which could otherwise re-run already-succeeded actions on retry).
- Nothing admin-facing surfaces "campaign X's action Y failed" today beyond the log line this adds
  — `ordo_campaign_dispatch_dead_letter` exists for whole-dispatch failures, but a single failed
  action inside an otherwise-successful dispatch has no equivalent visibility yet. Not addressed by
  this change; a candidate for a follow-up if failed actions turn out to be common enough to need
  admin-facing surfacing.
- `Model\Ai\OllamaClient::generate()` had an unrelated, narrower version of the same class of bug
  (`OutboundRateLimiter::throttle()` called before its `try` block, contradicting the method's own
  "never throws" docblock) — fixed alongside this, same underlying lesson, not worth its own ADR.
