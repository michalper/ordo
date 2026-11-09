# ADR 0044: Points-based loyalty/rewards program

## Status

Accepted.

## Context

Fourth and last candidate from the 2026-11-09 feature brainstorm, and the one flagged with the
highest build effort: a new, ongoing earn/redeem points balance - explicitly distinct from the
existing `Model\LoyaltyTierCalculator` (pure bronze/silver/gold tier derivation from spend, no
points, no table, no redemption at all). This needed real product-side scoping before any code
(points-to-currency ratio, expiry rules, how redemption actually produces a usable discount)
rather than being a drop-in clone of an existing pattern the way the other three batch items were.

## Decision

- **An append-only points ledger** (`ordo_loyalty_points_ledger`), not a mutated running balance
  column - a customer's real balance is always `SUM(points_delta)`, same auditable shape as
  `Model\CampaignOutcomeLogger`'s own ledger. Three row types (`Model\LoyaltyPointsManager::
  TYPE_EARN`/`TYPE_REDEEM`/`TYPE_EXPIRE`).
- **Earned on order placement, not invoice/payment capture, and never reversed on cancellation
  or refund.** A deliberate v1 simplification: tracking "points reversed on refund" would need its
  own correctness work (what if the points were already redeemed?) disproportionate to this
  batch's remaining scope. `Observer\EarnLoyaltyPointsForOrder` listens on `sales_order_save_after`
  specifically, NOT `sales_order_place_after` - a fresh order's `entity_id` is genuinely
  unpopulated at `sales_order_place_after` time for every normal checkout, a real, previously-
  confirmed trap in this exact codebase (see `Observer\DispatchOrderPlacedCampaigns`'s own
  docblock). `earnForOrder()` is itself idempotent per `order_id`, since `sales_order_save_after`
  can fire more than once across an order's lifetime.
- **Redemption reuses the existing `Model\CouponGenerator`/cart-price-rule mechanism**
  (`Model\Campaign\Action\GenerateCoupon`'s own dependency) rather than inventing a second
  discount-code system - the admin sets up the actual discount (e.g. "$5 off") as an ordinary cart
  price rule with coupon type "Specific Coupon" once, and redemption just mints a fresh code
  against it at a configured points cost. This also means v1 is **fixed-tier redemption, not a
  continuous points-to-currency conversion** - one configured cost (default 500) always redeems
  against one configured rule; a store wanting multiple redemption tiers needs a future
  iteration, not this one.
- **A new, dedicated service contract** (`Api\LoyaltyPointsManagementInterface`,
  `getMyBalance()`/`selfRedeem()`), exposed through BOTH REST (`/V1/ordo/loyalty-points/mine` GET,
  `/V1/ordo/loyalty-points/redeem` POST) and GraphQL (`ordoLoyaltyPointsBalance` query,
  `redeemOrdoLoyaltyPoints` mutation) - the exact same "one service, two transports, identity via
  the service's own `UserContextInterface`" shape already established by `CreditLimitManagement`/
  `OfferManagement` (see ADR 0032's own live-verification note that `UserContextInterface` is
  correctly populated for a GraphQL customer-token request too). No new admin screen, no new
  frontend block - a headless/PWA storefront (or a theme's own account page) renders the balance
  and a redeem button however it wants from these two transports, same reasoning as the module's
  existing Offer/Credit Limit self-service APIs.
- **Expiry (`Helper\Config::getLoyaltyPointsExpiryDays()`, default 0 = never) is a daily cron**
  (`Cron\ExpireLoyaltyPoints`/`LoyaltyPointsManager::expireOldEarnedPoints()`) that ages out the
  OLDEST unexpired earn rows first, each expiring at most its own points **capped at the
  customer's current balance** - a deliberate, documented simplification of real FIFO lot
  tracking. A redemption doesn't record which specific earn rows it drew from, so an old cohort
  that's already been partially/fully consumed by a later redemption is never double-counted
  (expiring more than the current balance would make it negative, which this explicitly guards
  against) - a close, not perfect, approximation of "redemptions consume oldest points first."
- **No "don't show to a logged-in customer who's already enrolled" concept at all** - unlike the
  email-capture popup (ADR 0043), there's no signup step; a points balance simply exists (starting
  at zero) for every customer the moment they place their first order.

**A real bug was caught and fixed via live verification against the sandbox, not just unit
tests**: `LoyaltyPointsManagement`'s guest check initially only tested `=== null`, but
`UserContextInterface::getUserId()` returns `0` for an anonymous GraphQL request, not `null` -
the exact trap `Resolver\CreditLimit`'s own docblock already documents for its own service. An
anonymous `ordoLoyaltyPointsBalance` query silently returned `0` (reading customer id 0's
nonexistent balance) instead of a real authorization error, until `resolveCustomerId()` was fixed
to check for `0` explicitly too. REST is unaffected - webapi.xml's own `resource="self"` ACL gate
already rejects an unauthenticated request with 401 before this class is ever reached, confirmed
live both before and after the fix.

## Consequences

- A store can run a real points program with five config fields, no new admin screen, and no new
  frontend UI of its own - the storefront theme renders the balance/redeem button via REST or
  GraphQL, same self-service pattern as Credit Limit/Offers.
- Points earned on an order that's later canceled or fully refunded are never clawed back - a
  known, accepted v1 gap, not a silent oversight. A store running a loyalty program on a
  high-cancellation-rate catalog should weigh this before enabling it.
- Redemption is fixed-tier (one cost, one rule), not a flexible points-to-currency slider - a
  store wanting several redemption tiers (e.g. 250/500/1000 points for three different coupon
  rules) needs a follow-up iteration, not this one.
- Expiry's FIFO approximation can, in a genuinely adversarial sequence of earns/redemptions/expiry
  runs, expire slightly more or less precisely than a full lot-tracking ledger would - acceptable
  for the common case (redemptions are infrequent relative to earns), but worth revisiting if a
  store's real usage pattern stresses it.
