# ADR 0006: Implement quiet hours by deferring through the existing scheduled-action mechanism

## Status

Accepted. Introduced in #46.

## Context

Campaign sends (email, SMS, WhatsApp, push) should not reach a customer during their local quiet
hours (e.g. 21:00–08:00). The engine already had a way to run an action later: `delay_minutes`
actions write a row to `ordo_campaign_scheduled_action`, which `Cron/RunScheduledCampaignActions.php`
resumes when due.

## Decision

`Model/Campaign/QuietHoursGate.php` is called from every Send* action alongside the existing
`FrequencyCapGate`. A send due during quiet hours is deferred until they end by reusing the same
scheduled-action row — `CampaignDispatcher::deferActionUntil()`, a public counterpart to the private
`scheduleResume()`, sharing the same write path. The window logic itself
(`Model/Campaign/QuietHoursCalculator.php`) is pure hour-of-day math, including overnight windows
like 21–8. `CampaignDispatcher::runOneAction()` stamps the action's `entity_id` into
`$context['ordo_action_id']` so a Send* action can identify itself to the gate.

## Consequences

- Because it's the same mechanism, campaign entry dedup (ADR 0007) and the resume cron apply to a
  deferred send automatically, with no extra code on either side.
- Known limitation: a synthetic split-variant action has no real `ordo_campaign_action` row to
  defer against, so quiet hours don't apply to it — the gate sends rather than silently dropping
  the message (same limitation as `delay_minutes` inside split variants).
