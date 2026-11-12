<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\LoyaltyTierCalculator;
use Ordo\Automation\Model\Rfm\RfmCalculator;

/**
 * Tags a customer the moment they cross a milestone — the discrete "you just unlocked
 * something" moment `Model\LoyaltyTierCalculator` (pure tier derivation) and the loyalty points
 * ledger (ADR 0044) don't have on their own, both being continuous/point-in-time rather than
 * event-like. Reuses the existing tag primitive instead of a new table: `CustomerTagManager::
 * addTag()` is already idempotent (no-ops, no duplicate "ordo_customer_tag_added" event, if the
 * customer already carries the tag) and the existing `tag_added` trigger
 * (`Observer\DispatchTagAddedCampaigns`) already fires for any newly-added tag — a campaign
 * reacts to a specific milestone with the existing `Has Tag` condition, no new trigger type
 * needed. See `docs/adr/0046-gamified-loyalty-milestones.md`.
 *
 * Milestones are never un-earned (no untagging here, unlike TagReorderCycleAtRiskCustomers'
 * at-risk tag) - a later order cancellation dropping a customer's count back below a threshold
 * doesn't strip a badge they already unlocked, matching how every real achievement/badge system
 * works.
 */
class TagLoyaltyMilestones
{
    /**
     * Fixed v1 milestone list, not admin-configurable - same "documented v1 gap, not an
     * oversight" choice ADR 0044 already made for loyalty points' redemption tiers. A store
     * wanting a different set needs a follow-up iteration (an admin-configurable list), not a
     * config field threaded through this one cron.
     *
     * @var int[]
     */
    private const array ORDER_COUNT_MILESTONES = [1, 5, 10, 25, 50];

    public function __construct(
        private readonly Config $config,
        private readonly RfmCalculator $rfmCalculator,
        private readonly CustomerScoreManager $customerScoreManager,
        private readonly LoyaltyTierCalculator $loyaltyTierCalculator,
        private readonly CustomerTagManager $customerTagManager,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isLoyaltyMilestonesEnabled()) {
            return;
        }

        $tagged = $this->tagOrderCountMilestones() + $this->tagTierMilestones();

        $this->cronRunLogger->logSummary(sprintf('tagged %d new loyalty milestone(s)', $tagged));
    }

    /**
     * One pass over every customer's own order frequency (already computed in bulk by
     * RfmCalculator for the order_frequency_at_least condition), tagging every milestone
     * threshold a customer has reached, not just the highest - a customer with 12 orders has
     * earned both the 1st-order and 5th-order badges too, not only a "reached 10" one. Batched
     * per milestone level (one getCustomerIdsWithTagFromSet() call for the whole candidate list),
     * same "one query for the batch, not one per customer" shape as
     * TagReorderCycleAtRiskCustomers - this can run over the whole customer base.
     */
    private function tagOrderCountMilestones(): int
    {
        $aggregates = $this->rfmCalculator->getAggregatesForAllCustomers();
        $tagged = 0;

        foreach (self::ORDER_COUNT_MILESTONES as $milestone) {
            $qualifyingIds = [];
            foreach ($aggregates as $customerId => $aggregate) {
                if ($aggregate['frequency'] >= $milestone) {
                    $qualifyingIds[] = $customerId;
                }
            }

            if ($qualifyingIds === []) {
                continue;
            }

            $tag = self::orderCountTag($milestone);
            $alreadyTagged = array_flip(
                $this->customerTagManager->getCustomerIdsWithTagFromSet($qualifyingIds, $tag)
            );

            foreach ($qualifyingIds as $customerId) {
                if (!isset($alreadyTagged[$customerId])) {
                    $this->customerTagManager->addTag($customerId, $tag);
                    $tagged++;
                }
            }
        }

        return $tagged;
    }

    /**
     * Silver and Gold each get their own tag - a customer who has reached Gold has, by
     * definition, already crossed the (lower) Silver threshold too, so both fire for them, not
     * just Gold. Bronze is the implicit floor every customer starts at, not an achievement, so
     * it gets no milestone tag at all.
     */
    private function tagTierMilestones(): int
    {
        $tagged = 0;

        foreach ([LoyaltyTierCalculator::SILVER, LoyaltyTierCalculator::GOLD] as $tier) {
            $threshold = $this->loyaltyTierCalculator->getMinimumScoreForTier($tier);
            if ($threshold === null) {
                continue;
            }

            $tag = self::tierTag($tier);
            $alreadyTagged = array_flip(
                $this->customerTagManager->getCustomerIdsWithTag($tag)
            );

            foreach ($this->customerScoreManager->getCustomerIdsWithScoreAtLeast($threshold) as $customerId) {
                if (!isset($alreadyTagged[$customerId])) {
                    $this->customerTagManager->addTag($customerId, $tag);
                    $tagged++;
                }
            }
        }

        return $tagged;
    }

    public static function orderCountTag(int $milestone): string
    {
        return 'milestone_order_' . $milestone;
    }

    public static function tierTag(string $tier): string
    {
        return 'milestone_tier_' . $tier;
    }
}
