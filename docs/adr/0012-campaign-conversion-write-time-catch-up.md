# ADR 0012: Catch a missed campaign conversion at write time, not with a polling cron

## Status

Accepted. Supersedes this ADR's own first version (a `*/10 * * * *` polling cron), reverted
before shipping once a deeper fix was found during review — see "History" below.

## Context

`Observer\RecordCampaignOutcome` fires on `sales_order_save_after` and marks any un-acted
`ordo_campaign_outcome_log` row for that customer as converted (`CampaignOutcomeLogger::markActed()`).
This assumes the row it needs to mark already exists by the time the customer's next order saves.

It doesn't always. Campaign dispatch is async (ADR 0001: `CampaignDispatchPublisher` → DB queue,
no RabbitMQ guarantee of immediate delivery — see AGENTS.md) — the 'sent' row for a campaign's
`send_email`/`send_sms`/`send_whatsapp` action is only written once the queue consumer actually
processes that dispatch, inside `Model\Sms\MessageLogWriter::recordSent()` → `CampaignOutcomeLogger
::logSent()`. If a customer places their own next ("converting") order before that consumer
catches up, `RecordCampaignOutcome` finds nothing to mark, and nothing re-checks that row
afterward — the conversion is silently and permanently lost.

This is exactly what an Adobe Commerce Marketplace MFTF submission run surfaced in
`AdminCampaignFunnelReflectsRealSendAndConversionTest`: the funnel showed `sent=2, converted=0`
instead of the expected `sent=2, converted=1`. Confirmed locally (not just suspected) with two
direct reproductions against a real Magento install: writing the 'sent' row before the real
converting order correctly produces `converted=1`; placing the real converting order *before*
writing the (late) 'sent' row produces `sent=1, converted=0` — permanently, with no further
action ever catching it up.

## History: the first version of this ADR (polling cron) and why it was reverted

The first fix shipped was `Cron\CatchUpCampaignConversions` (`*/10 * * * *`): rescan every
still-un-acted row within a 30-day lookback and look up a qualifying order per row. A later review
pass (before this was released) found two real problems with it, not just style:

1. **Wrong altitude.** `logSent()` is the one and only place a 'sent' row is ever created — it
   already knows, at that exact moment, whether this is a delayed send. Checking there closes the
   race at zero lag (not up to 10 minutes) and zero steady-state cost, instead of re-scanning the
   same rows over and over on a timer for a race that only ever happens once, at write time.
2. **The cron's own filter was wrong, and it would not actually have caught the race it was built
   for.** It matched rows via `sales_order.created_at > ordo_campaign_outcome_log.sent_at`. But in
   the exact race this exists to fix, the 'sent' row's `sent_at` (set to "now" at write time) ends
   up *later* than the already-existing converting order's `created_at` — precisely because the
   write was delayed past that order. The cron's own timestamp filter would have excluded the very
   order it was supposed to find. (The real-time observer's own `markActed()` never had this
   problem — it doesn't compare timestamps at all, because firing synchronously makes "sent before
   now" implicit.) This was never exercised by a real end-to-end test, only by unit tests that
   mocked the query result directly, which is how it went unnoticed until review.
3. Secondary: the "un-acted" population isn't the rare few rows genuinely affected by dispatch
   lag — `acted_at IS NULL` is true for every send that simply hasn't converted *yet* (the normal,
   common case for most campaigns). At realistic send volume this made the per-row lookup a real,
   continuously-recurring N+1, not the "cheap, a handful at most" cost originally assumed.

## Decision

Check for an already-existing conversion **inside `CampaignOutcomeLogger::logSent()` itself**,
right after inserting the 'sent' row, instead of on a timer:

- `Observer\DispatchOrderPlacedCampaigns` now also publishes `order_id` (the triggering order's
  own entity id) in the dispatch context — alongside the referral-converted trigger, which already
  did this for an unrelated reason.
- `SendEmail`/`SendSms`/`SendWhatsApp` read `$context['order_id']` and pass it through
  `MessageLogWriter::recordSent()`'s new `$triggerOrderId` parameter to
  `CampaignOutcomeLogger::logSent()`'s new `$excludeOrderId` parameter.
- `logSent()`, after inserting the row, looks up the customer's most recent order **excluding**
  that exact id (so the triggering order can never credit itself as its own conversion) and, if
  one already exists, immediately calls the existing `markActed()` — no new matching logic, just
  reusing it at a different trigger point.
- When no `order_id` is available (every trigger type other than `order_placed`/
  `referral_converted` — `customer_registered`, `tag_added`, `score_threshold_crossed`, the
  cron-driven B2B/lifecycle triggers, etc.), `logSent()` simply skips the check, exactly as before
  this change. Those triggers have no "order that produced this exact send" to exclude, and the
  real-time observer already covers them as the common case.

Ordering by `entity_id DESC` (not `created_at DESC`) to find "the customer's most recent order" —
simpler and exactly as correct given Magento's own auto-increment order ids, with no timestamp
precision or index shape to worry about.

`Cron\CatchUpCampaignConversions` and its crontab entry are removed entirely — the race it existed
for is now closed at the source, so there's nothing left for a periodic sweep to catch.

## Consequences

- A conversion that the real-time observer would have missed is now caught at the exact moment
  the delayed send finally lands — zero lag, not up to 10 minutes.
- No new cron, no steady-state table scan, no N+1 against `sales_order` for the (common, not rare)
  case of a send that simply hasn't converted yet.
- `Model\TriggerOutcomeLogger`'s analogous, lower-probability exposure (its own sends currently
  happen synchronously inside cron runs, not behind a queue) is still explicitly out of scope —
  this ADR's shape (an exclude-id passed through at write time) is the one to reuse if that one is
  ever reported, not a reason to revisit this decision.
