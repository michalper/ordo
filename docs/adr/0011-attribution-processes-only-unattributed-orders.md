# ADR 0011: Attribution cron processes only not-yet-attributed orders, newest first

## Status

Accepted. Refines ADR 0005 (the linear multi-touch model and the cron itself are unchanged).

## Context

ADR 0005's cron recomputed attribution for *every* order in the window, oldest 1000 by
`entity_id` ASC, every hour: per order one click-lookup SELECT, one DELETE and N single-row
INSERTs — all redone hourly for orders whose result could no longer change. Worse, on a store
with more than 1000 window orders, newer orders past the cap were *never* attributed.

## Decision

`computeForRecentOrders()` now anti-joins `sales_order` against `ordo_campaign_attribution`
(`ca.order_id IS NULL`), newest first, and writes each order's split with one `insertMultiple()`
and no DELETE. An attributed order drops out of the candidate set permanently; a zero-touch order
writes no rows, so it stays a candidate and a late-logged click for it is still picked up while
the order is in the window.

## Consequences

- New orders are attributed within roughly one cron pass of placement, regardless of backlog.
- Steady-state cost per pass is proportional to new + zero-touch orders, not window size.
- Trade-off accepted: an order that already has attribution rows is never recomputed, so a click
  event inserted *after* that order was attributed but backdated *before* the order's placement
  (queue lag/clock skew — a click dated after placement never counted anyway) no longer updates
  the split. ADR 0005's "late click picked up on the next pass" now holds only for orders that
  have no rows yet.
- Idempotency is now structural (the anti-join) plus the unique `(order_id, campaign_id)`
  constraint, instead of delete-and-reinsert.
