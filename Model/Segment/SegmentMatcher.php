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

    /**
     * @param int[] $visitedSegmentIds segment IDs already being evaluated in this call chain —
     *  threaded through via the '_in_segment_visited' context key so a nested in_segment
     *  condition (Model\Campaign\Condition\InSegment) can guard against cycles (segment A
     *  references B references A) the same way Model\Segment\SegmentMemberResolver does for its
     *  set-level resolve. Without this, a cyclic segment graph recurses until the call stack
     *  overflows instead of failing closed.
     */
    public function isCustomerInSegment(int $segmentId, int $customerId, array $visitedSegmentIds = []): bool
    {
        if (in_array($segmentId, $visitedSegmentIds, true)) {
            return false;
        }

        $visitedSegmentIds[] = $segmentId;

        $conditions = $this->segmentConditionCollectionFactory->create();
        $conditions->addSegmentFilter($segmentId);

        if ($conditions->getSize() === 0) {
            return false;
        }

        $context = ['customer_id' => $customerId, '_in_segment_visited' => $visitedSegmentIds];

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
