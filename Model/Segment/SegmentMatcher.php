<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Segment;

use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Evaluates a saved segment's conditions against a customer, reusing the exact same
 * ConditionPool/AND-semantics/fail-closed rules CampaignDispatcher uses for a campaign's own
 * conditions (see CampaignDispatcher::allConditionsSatisfied) — a segment IS just a named,
 * reusable set of campaign conditions, so it should behave identically.
 *
 * A segment with zero conditions never matches anyone — an empty AND is vacuously true in
 * boolean logic, but "matches every customer" is almost certainly not what an admin who forgot
 * to add conditions to a new segment intended, so this deliberately fails closed instead.
 */
class SegmentMatcher
{
    public function __construct(
        private readonly SegmentConditionCollectionFactory $segmentConditionCollectionFactory,
        private readonly ConditionPool $conditionPool,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isCustomerInSegment(int $segmentId, int $customerId): bool
    {
        $conditions = $this->segmentConditionCollectionFactory->create();
        $conditions->addSegmentFilter($segmentId);

        if ($conditions->getSize() === 0) {
            return false;
        }

        $context = ['customer_id' => $customerId];

        foreach ($conditions as $conditionRow) {
            $type = (string) $conditionRow->getData('type');
            $condition = $this->conditionPool->get($type);

            if ($condition === null) {
                $this->logger->error(sprintf('Ordo_Automation: unknown segment condition type "%s".', $type));
                return false;
            }

            if (!$condition->isSatisfied($context, $conditionRow->getParams())) {
                return false;
            }
        }

        return true;
    }
}
