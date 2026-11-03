# ADR 0012: Periodic catch-up cron for campaign conversions missed by the real-time observer

## Status

Accepted.

## Context

`Observer\RecordCampaignOutcome` fires on `sales_order_save_after` and marks any un-acted
`ordo_campaign_outcome_log` row for that customer as converted (`CampaignOutcomeLogger::markActed()`).
This assumes the row it needs to mark already exists by the time the customer's next order saves.

It doesn't always. Campaign dispatch is async (ADR 0001: `CampaignDispatchPublisher` → DB queue,
no RabbitMQ guarantee of immediate delivery — see AGENTS.md) — the 'sent' row for a campaign's
`send_email`/etc. action is only written once the queue consumer actually processes that
dispatch. If a customer places their own next ("converting") order before that consumer catches
up, `RecordCampaignOutcome` finds nothing to mark, and nothing in the codebase ever re-checks
that row afterward — the conversion is silently and permanently lost (`acted_at` stays `NULL`
forever, even once the 'sent' row does land, moments later).

This is exactly what an Adobe Commerce Marketplace MFTF submission run surfaced in
`AdminCampaignFunnelReflectsRealSendAndConversionTest`: the funnel showed `sent=2, converted=0`
instead of the expected `sent=2, converted=1`. Confirmed locally (not just suspected) with two
direct reproductions against a real Magento install: (1) writing the 'sent' row before placing
the real converting order correctly produces `converted=1`; (2) placing the real converting order
*before* writing the (late) 'sent' row produces `sent=1, converted=0` — permanently, with no
further action ever catching it up. `Model\TriggerOutcomeLogger`/`Observer\RecordTriggerOutcome`
share the identical shape, but their own 'sent' rows are written synchronously inside each
reminder cron's own run (no queue in between), so the same race is far less likely to manifest
there — left unfixed here, deliberately, to keep this change scoped to the actual reported bug.

## Decision

Add `CampaignOutcomeLogger::catchUpMissedConversions()`, run periodically by
`Cron\CatchUpCampaignConversions` (`*/10 * * * *`, same cadence as the existing retry crons): scan
still-un-acted `ordo_campaign_outcome_log` rows within the lookback window, and for each one,
attribute the earliest real order the customer placed *after* `sent_at` (an explicit
`created_at > sent_at` check — unlike the real-time observer, a cron evaluating later can't
assume "any un-acted row was necessarily sent before now"). Same first-plausible-match semantics
as `markActed()`, just evaluated on a delay instead of only at order-save time — belt-and-braces
on top of the real-time observer, not a replacement for it (the real-time path still handles the
common case immediately, with no cron lag).

Alternatives considered:
- **Just widen the MFTF test's `wait time`.** Papers over the symptom in CI without fixing the
  real production exposure — any store on the default DB queue driver with a backlog (not just a
  slow CI box) can hit the identical race.
- **Make campaign dispatch synchronous.** Reverses ADR 0001's whole point (checkout/registration
  must never wait on condition/action evaluation).
- **Have the dispatch consumer itself re-check for a already-placed later order right after
  writing the 'sent' row.** Pushes order-lookup logic into the send path for every single send,
  for a case that's the exception, not the rule; a dedicated periodic sweep is simpler and matches
  this module's own established "cron-driven scan against `*_at` claim columns" shape used
  everywhere else a live Magento event isn't available to lean on.

## Consequences

- A conversion that the real-time observer missed is caught within one cron tick (≤10 minutes),
  not lost forever.
- The scan itself is cheap in the steady state: only rows still `acted_at IS NULL` within the
  30-day lookback, typically a handful at most, each with one indexed point lookup against
  `sales_order` (`customer_id`, `created_at`).
- `Model\TriggerOutcomeLogger`'s analogous, lower-probability exposure is explicitly not
  addressed by this change — a future report of the same symptom there should reuse this same
  shape rather than re-litigate the trade-off.
