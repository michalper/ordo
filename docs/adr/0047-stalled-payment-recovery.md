# ADR 0047: Stalled/failed payment recovery

## Status

Accepted.

## Context

A fresh brainstorm round (2026-11-12, checked against established marketing-automation/
subscription-commerce platforms' public feature pages) flagged a real gap: there was no trigger
or cron anywhere in this module keyed off an order sitting in Magento's own pending-payment state
(`Magento\Sales\Model\Order::STATE_PENDING_PAYMENT`) — an order one nudge away from completing
that was otherwise left to silently age out, unlike every other reminder-worthy gap this module
already closes (abandoned cart, offer expiry, credit limit, reorder cycle).

This is explicitly **not** "dunning" in the subscription-billing sense other platforms mean it —
Magento Open Source has no recurring billing for this module to hook into at all — just the same,
already-precedented "cron scan against a claim column" shape every other reminder feature here
uses (`CLAUDE.md`'s own architecture note).

## Decision

- **New `Cron\SendStalledPaymentReminders`**, hourly, scanning `sales_order` directly (no
  collection class existed for this — same "raw SQL in the cron itself" shape
  `Cron\CalculateReorderCycle` already uses) for registered-customer orders in
  `STATE_PENDING_PAYMENT` older than a configurable lead time (`Helper\Config::
  getStalledPaymentLeadHours()`, default 24 hours), excluding orders already reminded via a
  `LEFT JOIN` pre-filter against the new `ordo_stalled_payment_reminder_log` table.
- **Sent once per order, ever** — no repeat/escalation, unlike offer-expiry's two-stage
  `expiring_soon`/`expired` shape. A single nudge is the right scope here; a second one would
  risk feeling like dunning even though it isn't.
- **`ReminderLogStore::claim()`** (the same atomic check-then-claim every other reminder cron
  here uses) guards against a double-send, claimed *before* sending and rolled back on a caught
  send failure — identical shape to `Cron\SendOfferExpiryReminders`.
- **Deliberately hedged email copy.** Magento's `pending_payment` state covers genuinely
  different real-world situations this module cannot distinguish from the order row alone: a
  customer who abandoned an online gateway mid-redirect (actionable — they need to finish paying)
  versus an offline method (e.g. bank transfer) where the customer already paid and the store is
  simply waiting on manual confirmation (not actionable at all — reminding them to pay again
  would be actively wrong). `stalled_payment_reminder.html` says "if you haven't finished paying
  yet, you can pick up where you left off" *and* "if you've already paid, there's nothing more to
  do" in the same message, rather than presuming either case. This is a deliberate accuracy
  choice, not hedge-everything caution for its own sake.
- **Consent topic: `AccountUpdates`, not `Promotional`.** This is about the customer's own
  already-placed order, the same bucket as credit-limit alerts and reorder reminders — not a
  marketing/recovery send driven by business goals.
- **No dashboard response-tracking integration.** `Model\TriggerOutcomeLogger`'s existing
  "responded" semantic (`Observer\RecordTriggerOutcome::markActed()`) means "the customer placed
  a *new* order" — exactly right for win-back/offer-expiry/reorder-reminder, but wrong here: the
  desired outcome is the *same* order's payment completing, not a second order being placed.
  Wiring this into `TriggerOutcomeLogger` without fixing that mismatch would show a permanently
  0% "response rate" on the dashboard regardless of how many customers actually paid — actively
  misleading, not just incomplete. A correct version needs its own resolution-tracking observer
  (watching the *same* order's state leave `pending_payment`), deferred as a documented v1 gap,
  not faked here.

## Consequences

- Recovers real, already-placed orders that are one nudge away from completing, with zero new
  engine concepts — a cron, a claim-column log table, and a hedged email, the same shape every
  other reminder feature here already has.
- The hourly cadence (vs. offer-expiry/credit-limit's daily) matches the hour-granularity lead
  time config — a customer crossing the 24-hour threshold mid-afternoon doesn't wait until the
  next day's run.
- No campaign-engine integration (no new trigger type) — a store wanting to build a richer,
  multi-step recovery flow around this signal would need that as a follow-up, not this ADR's
  scope.
