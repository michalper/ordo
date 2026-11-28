# ADR 0089: `add_loyalty_points` campaign action

## Status

Accepted.

## Context

Checking the loyalty-points area for other gaps (`ROADMAP.md`'s 2026-11-28 brainstorm, second
pass) found a real, previously-undetected mistake in this file's own history: the 2026-11-13
brainstorm round (fourth pass) claimed non-order loyalty-point-earning was already shipped, citing
`Model\Campaign\Action\AddPoints` as "already a generic action attachable to any trigger, not
order-only." That class is real and is already generic - but it calls
`CustomerScoreManager::addPoints()`, the **lead-scoring** system's own score
(`score_threshold_crossed` trigger, `ScoreAtLeast` condition), a completely different "points"
concept from the **loyalty rewards** points balance `Model\LoyaltyPointsManager` backs
(`ordo_loyalty_points_ledger`, redeemable via `LoyaltyPointsManagement`, the Google Wallet pass).
There was no campaign action anywhere that awards *loyalty* points -
`Observer\EarnLoyaltyPointsForOrder` was the only writer of `type = earn` rows, hardcoded to fire
proportionally to an order's own total, not callable from any other trigger.

## Decision

- **A new campaign action, `add_loyalty_points`** (`Model\Campaign\Action\AddLoyaltyPoints`), same
  params shape as `AddPoints`'s own (`{"points": "100"}`) and the same "non-positive value is a
  no-op" guard - deliberately named and shaped as `AddPoints`'s sibling, not a variant of it, so
  the two are never confused for one another again the way the research round above found they
  already had been once.
- **A new `LoyaltyPointsManager::awardBonus(int $customerId, int $points, string $reason): void`**,
  a thin wrapper around the same private `insertRow()` every other ledger write already goes
  through, parallel to `earnForOrder()` but with no `order_id`. Deliberately **not idempotent** -
  `earnForOrder()`'s own idempotency check is keyed on `order_id`, which this call has none of; a
  campaign action calling this is responsible for its own frequency control (the trigger's own
  once-per-occurrence nature, or `FrequencyCapManager` if the wiring risks a double-fire) the same
  way every other non-order-keyed write in this module already is.
- **`$reason` is logged, not stored as a new ledger column** - same shape
  `expireOldEarnedPoints()`'s own `$reason` parameter already uses. A bonus award is a notable
  event worth a log line for debugging; nothing reads a bonus's reason back later the way
  `order_id`/`coupon_code` are read back by other code paths, so a new column would be pure,
  unused schema growth.
- **Respects `Helper\Config::isLoyaltyPointsEnabled()`**, same guard every other entry point into
  this ledger already has (`earnForOrder()`, `redeem()`) - a disabled points program silently
  no-ops this action rather than crediting points nobody can see or redeem.

## Consequences

- Closes the real gap the research round found: a store can now build a signup-bonus campaign
  (`customer_registered` trigger), a referral bonus distinct from `generate_coupon`
  (`referral_converted`/`referral_signed_up` triggers), or a time-boxed "double points this
  weekend" promotion (`segment_blast`/`scheduled_at` trigger, points computed into the action's own
  `params` by whoever builds the campaign) - none of which this module could build before this
  action existed, despite the points program itself (ADR 0044) being otherwise complete.
- No new admin UI beyond the existing "points" dedicated field (shared with `add_points`, already
  wired into both the classic campaign form's `switcherConfig` and the Flow canvas's
  `ActionPool`-driven field config) - this action reuses that field, not a new one.
- Not idempotent by design, as noted above - a store wiring this to a trigger that can genuinely
  fire more than once for the same real-world occurrence (e.g. `order_placed`'s own documented
  multi-fire trap) would double-award. This is a deliberate v1 scope limit, not an oversight: the
  alternative (a generic idempotency key threaded through every action's `execute()` context) is
  real engine work affecting every action type, not something to bundle into one new action.
