# ADR 0090: `loyalty_points_expiring` reminder trigger

## Status

Accepted.

## Context

`ROADMAP.md`'s 2026-11-28 brainstorm (second pass) found a real gap: `Cron\ExpireLoyaltyPoints`
(when `Helper\Config::getLoyaltyPointsExpiryDays()` is set) silently deletes old earn rows via
`LoyaltyPointsManager::expireOldEarnedPoints()` with zero customer-facing notice beforehand - the
one standard loyalty-program retention lever ("you're about to lose points, come spend them")
this module's points feature didn't have, despite every *other* "something is about to expire"
signal in this codebase (offer expiry, credit limit, wishlist/cart/browse abandonment) already
following the same scan→claim→dispatch shape.

## Decision

- **A new trigger, `TRIGGER_LOYALTY_POINTS_EXPIRING`, dispatched by a new
  `Cron\SendLoyaltyPointsExpiringReminders`** - same shape as `Cron\ScanReviewRequestDue`
  (deliberately not forced into either shared abstract base, `AbstractCappedTriggerDispatchCron`
  or `AbstractTriggerRowClaimedDispatchCron` - see that class's own docblock reference to
  docs/adr/0065 for the precedent of leaving a structurally similar but not identical cron out of
  those abstractions).
- **A new config field, `expiry_warning_days`** (0 = no warning, same convention `expiry_days`
  itself uses), alongside a new `ordo_loyalty_points_expiring_reminder_log` table (unique on
  `ledger_id`) claimed the same way `ordo_review_request_log` is - at most one warning ever per
  `ordo_loyalty_points_ledger` earn row.
- **A row is "due" once its age falls inside the warning window**: not yet old enough for
  `expireOldEarnedPoints($expiryDays)` to have expired it (`created_at > now - expiryDays days`),
  but within `warningDays` of that cutoff (`created_at <= now - (expiryDays - warningDays) days`).
- **Dispatches once per ledger row, not once per customer** - `at_risk_points` in the dispatched
  context is that ONE row's own `points_delta`, not a per-customer total summed across every row
  currently in the warning window. A customer with more than one row due in the same scan
  dispatches once per row - the same reasoning `TRIGGER_WISHLIST_ABANDONED` already applies
  (dispatches once per wishlist item, not once per customer), chosen over aggregating for the same
  reason: aggregating would need the claim to cover a *set* of rows per dispatch rather than one
  row per claim, real added complexity for a case (several separate earn batches aging out in the
  same short window for the same customer) that will be rare in practice.
- **Deliberately does NOT check the customer's current balance** the way
  `expireOldEarnedPoints()` itself caps expiry at it - this is the same simpler, documented FIFO
  approximation ADR 0044 already accepts for the expiry side itself. A row whose points were
  already spent via a later redemption may still trigger a warning here (and may still get
  "expired" - zero actual points at that point - by the other cron); fixing this honestly needs
  real per-row FIFO lot tracking, the same follow-up ADR 0044's own Consequences already names as
  out of scope for v1.

## Consequences

- No built-in reminder email/SMS of its own - a store wires whatever action it wants
  (`send_email`/`send_sms`/...) onto the `loyalty_points_expiring` trigger via the campaign
  builder, same "dispatch a trigger with no built-in action" shape as `wishlist_abandoned`/
  `browse_abandoned`.
- Scheduled one hour before `Cron\ExpireLoyaltyPoints` (`etc/crontab.xml`: 04:00 vs 05:00) - purely
  a readability choice ("warn, then act" ordering within the same day), not a correctness
  requirement, since the warning window itself is already computed independently of when either
  cron actually runs.
- A store that never configures `expiry_warning_days` (the default) gets no behavior change at
  all - both new config fields default to a no-op, same as `expiry_days` itself already does for
  installs that never configure point expiry in the first place.
