# ADR 0046: Gamified loyalty milestones

## Status

Accepted.

## Context

A fresh brainstorm round (2026-11-12, checked against established marketing-automation/loyalty
platforms' public feature pages) flagged a real gap: `Model\LoyaltyTierCalculator` (bronze/silver/
gold) and the points ledger (ADR 0044) are both pure, continuous derivations — there's no discrete
"you just unlocked something" moment anywhere in this module, which several loyalty platforms lean
on for engagement (badges/challenges/milestone rewards layered on top of tiers a customer already
has).

## Decision

- **No new trigger type, no new table.** A milestone is just another tag, added the moment a
  customer crosses it. `CustomerTagManager::addTag()` is already idempotent — it no-ops (and
  fires no `ordo_customer_tag_added` event) if the customer already carries the tag — and the
  existing `tag_added` trigger (`Observer\DispatchTagAddedCampaigns`) already fires for any newly
  added tag. A campaign reacts to one specific milestone with the existing `Has Tag` condition on
  that tag's exact value; nothing new to build on the dispatch side at all.
- **New `Cron\TagLoyaltyMilestones`**, one daily job covering two milestone families:
  - **Order-count milestones** (`milestone_order_{N}` for N in a fixed `[1, 5, 10, 25, 50]`) —
    reuses `RfmCalculator::getAggregatesForAllCustomers()`'s existing bulk frequency count (the
    same data `OrderFrequencyAtLeast` already reads), batched per milestone level via
    `CustomerTagManager::getCustomerIdsWithTagFromSet()` so this can run over the whole customer
    base without an N+1 query per customer.
  - **Tier milestones** (`milestone_tier_silver`, `milestone_tier_gold`) — reuses
    `CustomerScoreManager::getCustomerIdsWithScoreAtLeast()` against
    `LoyaltyTierCalculator::getMinimumScoreForTier()`'s own thresholds. Bronze gets no milestone
    tag — it's the implicit floor every customer starts at, not an achievement.
- **The fixed `[1, 5, 10, 25, 50]` order-count list is a deliberate v1 choice, not an oversight**
  — same reasoning ADR 0044 already gave for loyalty points' fixed redemption tiers. A store
  wanting a different set needs a follow-up iteration (an admin-configurable list), not a config
  field threaded through this one cron.
- **Milestones are never un-earned.** Unlike `TagReorderCycleAtRiskCustomers`, which clears its
  tag once a customer recovers, this cron only ever adds tags, never removes one. A later order
  cancellation dropping a customer's count back below a threshold doesn't strip a badge they
  already unlocked — matching how every real achievement/badge system works, and avoiding a
  confusing flip-flop for a customer whose count hovers right at a threshold.
- **Its own enable toggle** (`Stores → Configuration → Ordo Automation → Loyalty Milestones`),
  independent of whether loyalty points or tiers themselves are in use — a store can run the
  milestone cron even without the points ledger enabled, since order-count milestones don't
  depend on it at all.

## Consequences

- A merchant can build a "Welcome back after your first order" or "Congrats on reaching Gold"
  campaign today, with zero new engine concepts — just a `tag_added` trigger and a `Has Tag`
  condition, the same primitives every other tag-driven campaign in this module already uses.
- No admin UI beyond the one config toggle. The milestone tag values themselves surface through
  the existing tag autocomplete (`CustomerTagManager::searchTags()`) once at least one customer
  has earned one, the same "no special-cased UI" treatment every other tag value gets.
- A customer's milestone tags are a point-in-time record of *when* they crossed each threshold —
  not a live status check (that's still `OrderFrequencyAtLeast`/`LoyaltyTierAtLeast` for a
  standing segment condition). The two serve different purposes and are expected to coexist.
