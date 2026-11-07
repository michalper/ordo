# ADR 0028: Coupon-to-campaign attribution via a separate log table

## Status

Accepted.

## Context

`Model\CampaignDispatcher::runActionsFrom()` already stamps `$context['campaign_id']` before
running any campaign's actions, and `Model\Campaign\Action\GenerateCoupon` receives that context -
but it never used `campaign_id` for anything. `Model\CouponGenerator::generate()` mints the coupon
identically to how core's own mass-generator would (same `type`/`is_primary` marker, see that
class's own docblock), so nothing anywhere recorded which campaign (if any) generated a given
coupon code.

`Model\ResourceModel\RevenueAnalytics\Grid\Collection` (the revenue/channel report) already shows
a plain `coupon_code` column when an order redeemed one, and already shows `campaign_name` when
the order has an `ordo_campaign_outcome_log` row (i.e. the order followed some campaign send) - but
an order whose ONLY campaign connection was "redeemed a coupon a campaign generated" (no outcome
row at all, e.g. the customer used the code from an entirely different channel, like a social post
sharing it) showed no campaign attribution whatsoever. This was flagged as a known, open gap in
`ROADMAP.md` since ADR 0017.

## Decision

- **A new, separate satellite table** (`ordo_coupon_campaign_log`: `coupon_id`, `campaign_id`,
  `created_at`, unique on `coupon_id`), not a new column on `salesrule_coupon` (a core table this
  module never modifies) and not reusing `ordo_campaign_outcome_log` (that table's own shape -
  `customer_id`+`variant`+`sent_at`/`acted_at` - answers a different question, "did a send
  convert," not "which campaign minted this specific coupon code"; forcing one row shape to answer
  both would have meant a lot of nullable columns that only ever apply to one half of its rows).
- **Keyed by `coupon_id` (salesrule_coupon's own PK), not `code`** - a coupon's code is unique at
  any given moment but a deleted-and-regenerated code could theoretically collide over the
  table's lifetime in a way `coupon_id` (a true, never-reused auto-increment) cannot.
- **`CouponGenerator::generate()` gains an optional `?int $campaignId` parameter**, logged only
  when given (nothing changes for comparable future callers with no campaign context at all, if
  any ever exist). Logging failure is caught and logged, never thrown - by the time this runs the
  coupon itself is already saved and already returned to the caller; losing the attribution row
  must never cost the merchant the coupon.
- **`RevenueAnalytics`'s own `coupon_campaign_name` is a separate column from the existing
  `campaign_name`**, not a fallback/merge of the two - they answer genuinely different questions
  (see that class's own updated docblock) and can legitimately disagree or populate independently.
  Joined via `sales_order.coupon_code -> salesrule_coupon.coupon_id ->
  ordo_coupon_campaign_log.coupon_id -> ordo_campaign` (a second join to `ordo_campaign` under a
  distinct alias, since the table is already joined once for `campaign_name`/`outcome`).

## Consequences

- An order's coupon attribution only ever reflects the *first* campaign that generated a given
  code - by construction (unique on `coupon_id`), since a specific coupon code is normally
  consumed/used by exactly one order anyway, this isn't expected to lose information in practice.
- No backfill for coupons generated before this shipped - `ordo_coupon_campaign_log` starts empty;
  `coupon_campaign_name` is simply null for any pre-existing coupon regardless of which campaign
  (if any) actually generated it historically.
- Still cannot distinguish "a merchant manually created this exact code to look like an Ordo one"
  from "Ordo didn't generate it at all" - both show as no attribution, which is the only honest
  answer available (this module has no row for either case).
