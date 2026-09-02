<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Segment;

use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Resolves a saved segment's conditions into the actual set of customer IDs currently matching
 * ALL of them — the set-level counterpart to SegmentMatcher's per-customer boolean check, needed
 * so bulk actions can be run against "everyone currently in this segment" instead of one customer
 * at a time. Mirrors SegmentMatcher's AND-semantics and fail-closed rules exactly:
 *  - zero conditions -> matches nobody (never "matches everyone")
 *  - any condition that can't be resolved zeroes out the whole segment, same as SegmentMatcher
 *    returning false the moment one condition fails
 *  - order_total_gte / visitor_tag are per-event-context conditions with no meaning for a
 *    standing set of customers; SegmentMatcher's own context (['customer_id' => $x]) already
 *    never satisfies them, so at the set level they match nobody too.
 */
class SegmentMemberResolver
{
    public function __construct(
        private readonly SegmentConditionCollectionFactory $segmentConditionCollectionFactory,
        private readonly CustomerTagManager $customerTagManager,
        private readonly CustomerScoreManager $customerScoreManager,
        private readonly RfmCalculator $rfmCalculator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int[] $visitedSegmentIds segment IDs already being resolved in this call chain,
     *  used to guard against in_segment cycles (A references B references A)
     * @return int[]
     */
    public function getMatchingCustomerIds(int $segmentId, array $visitedSegmentIds = []): array
    {
        $conditions = $this->segmentConditionCollectionFactory->create();
        $conditions->addSegmentFilter($segmentId);

        if ($conditions->getSize() === 0) {
            return [];
        }

        $visitedSegmentIds[] = $segmentId;

        $result = null;
        /** @var \Ordo\Automation\Model\SegmentCondition $conditionRow */
        foreach ($conditions as $conditionRow) {
            $type = $conditionRow->getType();
            $matchingIds = $this->resolveCondition($type, $conditionRow->getParams(), $visitedSegmentIds);

            if ($matchingIds === []) {
                return [];
            }

            $result = $result === null ? $matchingIds : array_intersect($result, $matchingIds);

            if ($result === []) {
                return [];
            }
        }

        return array_values($result ?? []);
    }

    /**
     * @param array<string, mixed> $params
     * @param int[] $visitedSegmentIds
     * @return int[]
     */
    private function resolveCondition(string $type, array $params, array $visitedSegmentIds): array
    {
        switch ($type) {
            case 'tag':
                return $this->resolveTag($params);
            case 'score_at_least':
                return $this->resolveScoreAtLeast($params);
            case 'recency_days_at_most':
                return $this->resolveRecencyDaysAtMost($params);
            case 'order_frequency_at_least':
                return $this->resolveOrderFrequencyAtLeast($params);
            case 'monetary_total_at_least':
                return $this->resolveMonetaryTotalAtLeast($params);
            case 'in_segment':
                return $this->resolveInSegment($params, $visitedSegmentIds);
            case 'order_total_gte':
            case 'visitor_tag':
                return [];
            default:
                $this->logger->error(sprintf('Ordo_Automation: unknown segment condition type "%s".', $type));
                return [];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return int[]
     */
    private function resolveTag(array $params): array
    {
        $tag = $params['tag'] ?? null;

        if (!is_string($tag) || $tag === '') {
            return [];
        }

        return $this->customerTagManager->getCustomerIdsWithTag($tag);
    }

    /**
     * @param array<string, mixed> $params
     * @return int[]
     */
    private function resolveScoreAtLeast(array $params): array
    {
        $threshold = $params['threshold'] ?? null;

        if ($threshold === null || !is_numeric($threshold)) {
            return [];
        }

        return $this->customerScoreManager->getCustomerIdsWithScoreAtLeast((int) $threshold);
    }

    /**
     * @param array<string, mixed> $params
     * @return int[]
     */
    private function resolveRecencyDaysAtMost(array $params): array
    {
        $days = $params['days'] ?? null;

        if ($days === null || !is_numeric($days)) {
            return [];
        }

        $threshold = (int) $days;
        $matching = [];

        foreach ($this->rfmCalculator->getAggregatesForAllCustomers() as $customerId => $aggregate) {
            if ($aggregate['recency_days'] !== null && $aggregate['recency_days'] <= $threshold) {
                $matching[] = $customerId;
            }
        }

        return $matching;
    }

    /**
     * @param array<string, mixed> $params
     * @return int[]
     */
    private function resolveOrderFrequencyAtLeast(array $params): array
    {
        $count = $params['count'] ?? null;

        if ($count === null || !is_numeric($count)) {
            return [];
        }

        $threshold = (int) $count;
        $matching = [];

        foreach ($this->rfmCalculator->getAggregatesForAllCustomers() as $customerId => $aggregate) {
            if ($aggregate['frequency'] >= $threshold) {
                $matching[] = $customerId;
            }
        }

        return $matching;
    }

    /**
     * @param array<string, mixed> $params
     * @return int[]
     */
    private function resolveMonetaryTotalAtLeast(array $params): array
    {
        $amount = $params['amount'] ?? null;

        if ($amount === null || !is_numeric($amount)) {
            return [];
        }

        $threshold = (float) $amount;
        $matching = [];

        foreach ($this->rfmCalculator->getAggregatesForAllCustomers() as $customerId => $aggregate) {
            if ($aggregate['monetary'] >= $threshold) {
                $matching[] = $customerId;
            }
        }

        return $matching;
    }

    /**
     * @param array<string, mixed> $params
     * @param int[] $visitedSegmentIds
     * @return int[]
     */
    private function resolveInSegment(array $params, array $visitedSegmentIds): array
    {
        $targetSegmentId = $params['segment_id'] ?? null;

        if ($targetSegmentId === null || !is_numeric($targetSegmentId)) {
            return [];
        }

        $targetSegmentId = (int) $targetSegmentId;

        if (in_array($targetSegmentId, $visitedSegmentIds, true)) {
            return [];
        }

        return $this->getMatchingCustomerIds($targetSegmentId, $visitedSegmentIds);
    }
}
