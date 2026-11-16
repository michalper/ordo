# ADR 0065: Shared base for the (customer_id, event_key)-capped dispatch-only crons

## Status

Accepted.

## Context

A `/simplify` review of the `Cron/` claim-scan family (the same review that produced the
`ReminderLogStore::claimAndRun()` extraction) flagged `SendBrowseAbandonmentReminders` and
`SendWishlistAbandonmentReminders` as near-identical end to end, not just in the claim wrapper:
both scan for a `(customer_id, event_key)` pair that hasn't converted, cap dispatches per pair via
a `reminders_sent < maxReminders` HAVING clause, capture a `runStartedAt` freshness guard before
the SELECT runs (so a concurrently-overlapping run's own claim can't slip past the cap check), and
dispatch a bare campaign trigger with no built-in action of their own. Only the SELECT (which
source table/join defines "due") and the trigger code/label actually differ.

The same review also flagged `ScanReviewRequestDue` as belonging to "that family" in a vaguer
sense — it's also a claim-before-dispatch-a-bare-trigger cron with no built-in action — but its
dedup semantics are genuinely different, not just a smaller instance of the same shape:

- It dispatches **exactly once per order**, deduped on a unique `order_id`, not capped at N per
  `(customer_id, event_key)` pair.
- It has **no `reminders_sent`/HAVING cap** at all — the LEFT JOIN's `l.entity_id IS NULL` filter
  is a plain existence check, not a count.
- It has **no concurrent-run freshness guard** — `claimAndRun()`'s own atomic re-check against
  `order_id` is already sufficient, since there's no "under N" threshold a second overlapping run
  could race past; the browse/wishlist freshness guard exists specifically to close that race for
  the capped case, which doesn't apply here.

## Decision

Extract `AbstractCappedTriggerDispatchCron`, shared by `SendBrowseAbandonmentReminders` and
`SendWishlistAbandonmentReminders` only. Each subclass supplies `isEnabled()`, `logTable()`,
`triggerCode()`, `triggerLabel()`, and `fetchDueRows()` (the per-feature SELECT); the base class
owns the freshness-guard timestamp, the claim/dispatch/rollback loop, and the summary/failure
logging.

`ScanReviewRequestDue` is deliberately **not** forced into this abstraction. Fitting it in would
mean either padding the base class with optional parameters it doesn't use (a `maxReminders`
nobody caps at, a freshness guard nobody needs) or growing a second, more generic abstraction
whose only two real users would again be the browse/wishlist pair — paying for generality this
codebase doesn't yet need a second instance of. It stays a small, standalone class; if a third
"capped per (customer_id, event_key)" cron shows up later, extending this base costs one class and
one `fetchDueRows()` override.

## Consequences

- `SendBrowseAbandonmentReminders`/`SendWishlistAbandonmentReminders` each shrink to just their
  SELECT and four one-line hook methods; the claim/dispatch/rollback/freshness-guard bugfix
  surface area for this pair drops from two copies to one.
- `SendWishlistAbandonmentReminders`' SELECT now aliases its SKU column as `event_key` (it already
  wrote `event_key` into the log table's own matching column; only the in-PHP array key changed)
  so both subclasses hand the base class the same `{customer_id, event_key, reminders_sent}` row
  shape. The dispatched trigger payload (`product_sku`) is unchanged.
- `ScanReviewRequestDue` stays exactly as it was before this change — this ADR documents why it
  wasn't touched, not a plan to revisit it later.
