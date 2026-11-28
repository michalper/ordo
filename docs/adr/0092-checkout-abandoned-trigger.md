# ADR 0092: `checkout_abandoned` trigger

## Status

Accepted.

## Context

`ROADMAP.md`'s 2026-11-28 brainstorm (sixth pass) found that `cart_abandoned` conflates two
different signals current cart-recovery tooling treats as separate: a shopper who never started
checkout vs. one who started checkout and dropped - the same distinction most established
cart-recovery feature sets draw between "Added to Cart" and "Started Checkout" events, since the
latter is a materially higher-intent signal. This module already tracks the exact signal needed
(`checkout_start`, a theme-integration tracker event written to `ordo_visitor_event` - see
`view/frontend/web/js/tracker.js`'s own docblock, already read by `Cron\CalculateFunnelMetrics`
for the funnel report) but nothing dispatched a campaign off it.

## Decision

- **A new trigger, `TRIGGER_CHECKOUT_ABANDONED` (`checkout_abandoned`)**, dispatched by a new
  `Cron\SendCheckoutAbandonmentReminders` - deliberately a standalone class, not forced into
  either shared abstract base (`AbstractCappedTriggerDispatchCron`'s own context shape hardcodes
  a per-item `event_key`/`product_sku`, which `checkout_start` has none of - see
  `tracker.js`'s own docblock; `AbstractTriggerRowClaimedDispatchCron` is for triggers scoped to
  one specific `campaign_id`, not this `dispatch()`-fan-out-to-every-campaign shape). Same
  "structurally similar but not identical, don't force it into an existing abstraction"
  precedent docs/adr/0065 and `Cron\SendLoyaltyPointsExpiringReminders`'s own docblock already
  establish.
- **The SELECT joins three things**: the same "active quote, `items_count > 0`, `customer_id IS
  NOT NULL`, `updated_at <= cutoff`, `subtotal >= min_subtotal`" base `Cron\
  SendAbandonedCartReminders` already uses; an `EXISTS` check against `ordo_visitor_event` for a
  `checkout_start` row at or after the quote's own `created_at` (proving THIS cart actually
  reached checkout, not just any cart ever); and the same "no order since" `NOT EXISTS` against
  `sales_order` `Cron\SendBrowseAbandonmentReminders`/`SendWishlistAbandonmentReminders` already
  use. Capped per quote via `ordo_checkout_abandoned_reminder_log` + `ReminderLogStore::
  claimAndRunUnderLimit()`, the same per-cart cap shape `cart_abandoned`'s own fixed reminder
  uses - not `wishlist_abandoned`/`browse_abandoned`'s "once ever per item" shape, since a quote
  can plausibly reach checkout, get abandoned, and be revived more than once.
- **Dispatches a trigger only, with no built-in reminder email of its own** - same reasoning
  `browse_abandoned`/`wishlist_abandoned` already establish: a store wires whatever action
  (email/SMS/popup/...) it wants onto the trigger via the campaign builder, distinctly worded and
  distinctly timed from `cart_abandoned`'s own fixed reminder, without touching that cron's
  existing behavior at all.
- **Covered by a `Test/Integration/` test, not MFTF** - unlike `cart_abandoned`/
  `browse_abandoned` (both MFTF-covered per `Test/Mftf/SCENARIOS.md`), this closes a backend-only
  gap: the `checkout_start` JS tracking and the generic trigger-dropdown/system.xml-field
  mechanisms it reuses are already shipped and already covered elsewhere, so there is no new
  browser-visible surface for MFTF to exercise - same reasoning `wishlist_abandoned`'s own
  Integration-only coverage already documents (SCENARIOS.md's §33 reference).

## Consequences

- A new `checkout_abandonment` config group (`enabled`/`delay_minutes`/`min_subtotal`/
  `max_reminders`), off by default like every sibling abandonment feature - no behavior change
  for a store that never enables it.
- A guest quote (no `customer_id`) is never a candidate - same limitation `cart_abandoned`'s own
  campaign-trigger half already has (its fixed email, unlike the trigger, has no such
  restriction; this trigger has no fixed email at all).
