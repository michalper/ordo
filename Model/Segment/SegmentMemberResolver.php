<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Segment;

use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\Event\EventOccurredResolver;
use Ordo\Automation\Model\Purchase\PurchasedProductResolver;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use Ordo\Automation\Model\SegmentFactory;
use Psr\Log\LoggerInterface;

/**
 * Resolves a saved segment's conditions into the actual set of customer IDs currently matching
 * them — the set-level counterpart to SegmentMatcher's per-customer boolean check, needed so bulk
 * actions can be run against "everyone currently in this segment" instead of one customer at a
 * time. Mirrors SegmentMatcher's semantics, fail-closed rules, and nested-group support exactly:
 *  - zero conditions -> matches nobody (never "matches everyone"), regardless of condition_logic
 *  - under AND (condition_logic 'all', the historical default): any condition that can't be
 *    resolved zeroes out the whole segment/group, same as SegmentMatcher returning false the
 *    moment one condition fails; combined via array_intersect.
 *  - under OR (condition_logic 'any'): an unresolvable condition simply contributes nobody to
 *    the union rather than zeroing out the segment/group — same as SegmentMatcher treating an
 *    unsatisfied condition as "keep checking the rest" under OR, not "fail the whole thing".
 *  - a row whose type is the reserved 'group' pseudo-type recurses into its own
 *    {"logic": ..., "conditions": [...]} the same way SegmentMatcher::evaluateGroup() does —
 *    see that class's docblock for why this needs no schema change and isn't reachable via the
 *    admin UI yet.
 *  - order_total_gte / visitor_tag are per-event-context conditions with no meaning for a
 *    standing set of customers; SegmentMatcher's own context (['customer_id' => $x]) already
 *    never satisfies them, so at the set level they match nobody too.
 */
class SegmentMemberResolver
{
    /**
     * Cached per top-level getMatchingCustomerIds() call (reset at the start of each one) so a
     * segment with more than one RFM condition (e.g. recency_days_at_most AND
     * monetary_total_at_least) doesn't run RfmCalculator's grouped aggregate query once per
     * condition — every RFM condition in the same resolve reads the same snapshot.
     *
     * @var array<int, array{frequency: int, monetary: float, recency_days: int|null}>|null
     */
    private ?array $aggregatesCache = null;

    /**
     * Same per-resolve caching as $aggregatesCache, for the percentile ranking — a segment
     * combining e.g. monetary_percentile_at_least AND recency_percentile_at_least computes the
     * ranking once, not once per condition.
     *
     * @var array<int, array{recency_percentile: float, frequency_percentile: float, monetary_percentile: float}>|null
     */
    private ?array $percentileRanksCache = null;

    public function __construct(
        private readonly SegmentConditionCollectionFactory $segmentConditionCollectionFactory,
        private readonly CustomerTagManager $customerTagManager,
        private readonly CustomerScoreManager $customerScoreManager,
        private readonly RfmCalculator $rfmCalculator,
        private readonly SegmentFactory $segmentFactory,
        private readonly SegmentResource $segmentResource,
        private readonly LoggerInterface $logger,
        private readonly PurchasedProductResolver $purchasedProductResolver,
        private readonly EventOccurredResolver $eventOccurredResolver
    ) {
    }

    /**
     * @param int[] $visitedSegmentIds segment IDs already being resolved in this call chain,
     *  used to guard against in_segment cycles (A references B references A)
     * @return int[]
     */
    public function getMatchingCustomerIds(int $segmentId, array $visitedSegmentIds = []): array
    {
        if ($visitedSegmentIds === []) {
            // Fresh top-level call (not a recursive in_segment hop) — start a new aggregates
            // snapshot for this resolve. A recursive call keeps reusing the same one.
            $this->aggregatesCache = null;
            $this->percentileRanksCache = null;
        }

        $conditions = $this->segmentConditionCollectionFactory->create();
        $conditions->addSegmentFilter($segmentId);

        if ($conditions->getSize() === 0) {
            return [];
        }

        $visitedSegmentIds[] = $segmentId;

        $segment = $this->segmentFactory->create();
        $this->segmentResource->load($segment, $segmentId);

        $specs = [];
        /** @var \Ordo\Automation\Model\SegmentCondition $conditionRow */
        foreach ($conditions as $conditionRow) {
            $specs[] = ['type' => $conditionRow->getType(), 'params' => $conditionRow->getParams()];
        }

        return $this->resolveList($specs, $segment->getConditionLogic(), $visitedSegmentIds);
    }

    /**
     * @param array<int, array{type: string, params: array<string, mixed>}> $specs
     * @param int[] $visitedSegmentIds
     * @return int[]
     */
    private function resolveList(array $specs, string $logic, array $visitedSegmentIds): array
    {
        if ($logic === 'any') {
            $result = [];
            foreach ($specs as $spec) {
                $result += array_flip($this->resolveOne($spec, $visitedSegmentIds));
            }
            return array_keys($result);
        }

        $result = null;
        foreach ($specs as $spec) {
            $matchingIds = $this->resolveOne($spec, $visitedSegmentIds);

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
     * @param array{type: string, params: array<string, mixed>} $spec
     * @param int[] $visitedSegmentIds
     * @return int[]
     */
    private function resolveOne(array $spec, array $visitedSegmentIds): array
    {
        if ($spec['type'] === 'group') {
            return $this->resolveGroup($spec['params'], $visitedSegmentIds);
        }

        return $this->resolveCondition($spec['type'], $spec['params'], $visitedSegmentIds);
    }

    /**
     * @param array<string, mixed> $groupParams
     * @param int[] $visitedSegmentIds
     * @return int[]
     */
    private function resolveGroup(array $groupParams, array $visitedSegmentIds): array
    {
        $nestedLogic = ($groupParams['logic'] ?? 'all') === 'any' ? 'any' : 'all';
        $nested = $groupParams['conditions'] ?? null;

        if (!is_array($nested) || $nested === []) {
            return [];
        }

        $specs = [];
        foreach ($nested as $item) {
            if (!is_array($item) || !isset($item['type']) || !is_string($item['type'])) {
                continue;
            }
            $specs[] = ['type' => $item['type'], 'params' => $this->asStringKeyedArray($item['params'] ?? [])];
        }

        if ($specs === []) {
            return [];
        }

        return $this->resolveList($specs, $nestedLogic, $visitedSegmentIds);
    }

    /**
     * Same normalization as Model\Segment\SegmentMatcher::asStringKeyedArray() - a decoded-JSON
     * 'group' params blob's nested "conditions" entries aren't guaranteed to be string-keyed
     * maps the way a real SegmentCondition row's getParams() already is.
     *
     * @return array<string, mixed>
     */
    private function asStringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
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
            case 'recency_percentile_at_least':
                return $this->resolvePercentileAtLeast($params, 'recency_percentile');
            case 'order_frequency_percentile_at_least':
                return $this->resolvePercentileAtLeast($params, 'frequency_percentile');
            case 'monetary_percentile_at_least':
                return $this->resolvePercentileAtLeast($params, 'monetary_percentile');
            case 'in_segment':
                return $this->resolveInSegment($params, $visitedSegmentIds);
            case 'not_in_segment':
                return $this->resolveNotInSegment($params, $visitedSegmentIds);
            case 'purchased_sku':
                return $this->resolvePurchasedSku($params);
            case 'purchased_category':
                return $this->resolvePurchasedCategory($params);
            case 'event_occurred':
                return $this->resolveEventOccurred($params);
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
    private function resolvePurchasedSku(array $params): array
    {
        $sku = trim((string) ($params['sku'] ?? ''));

        if ($sku === '') {
            return [];
        }

        return $this->purchasedProductResolver->getCustomerIdsWhoPurchasedSku($sku);
    }

    /**
     * @param array<string, mixed> $params
     * @return int[]
     */
    private function resolvePurchasedCategory(array $params): array
    {
        $categoryId = $params['category_id'] ?? null;

        if (!is_numeric($categoryId) || (int) $categoryId <= 0) {
            return [];
        }

        return $this->purchasedProductResolver->getCustomerIdsWhoPurchasedInCategory((int) $categoryId);
    }

    /**
     * @param array<string, mixed> $params
     * @return int[]
     */
    private function resolveEventOccurred(array $params): array
    {
        $eventType = trim((string) ($params['event_type'] ?? ''));
        if ($eventType === '') {
            return [];
        }

        $eventKey = trim((string) ($params['event_key'] ?? ''));
        $withinDays = (int) ($params['within_days'] ?? 0);

        return $this->eventOccurredResolver->getCustomerIdsWithEvent(
            $eventType,
            $eventKey !== '' ? $eventKey : null,
            $withinDays
        );
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

        foreach ($this->getAggregates() as $customerId => $aggregate) {
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

        // A zero-order customer never appears in getAggregates() (it's a GROUP BY over
        // sales_order), but still satisfies "frequency >= 0" under the single-customer path
        // (RfmCalculator::getFrequency() returns 0 for them) — so threshold <= 0 matches
        // everyone, not just customers who happen to have placed an order.
        if ($threshold <= 0) {
            return $this->rfmCalculator->getAllCustomerIds();
        }

        $matching = [];

        foreach ($this->getAggregates() as $customerId => $aggregate) {
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

        // Same reasoning as resolveOrderFrequencyAtLeast(): a zero-order customer isn't in
        // getAggregates() at all, but still satisfies "monetary >= 0" under the single-customer
        // path (RfmCalculator::getMonetaryTotal() returns 0.0 for them).
        if ($threshold <= 0.0) {
            return $this->rfmCalculator->getAllCustomerIds();
        }

        $matching = [];

        foreach ($this->getAggregates() as $customerId => $aggregate) {
            if ($aggregate['monetary'] >= $threshold) {
                $matching[] = $customerId;
            }
        }

        return $matching;
    }

    /**
     * The three percentile RFM conditions differ only in which metric of the shared percentile
     * ranking they read, so they share one resolver keyed by that metric rather than three
     * near-identical copies. Percentile 100 is the best end of every metric (see
     * RfmCalculator::getPercentileRanks()), so ">= threshold" reads the same way for all three.
     *
     * @param array<string, mixed> $params
     * @param 'recency_percentile'|'frequency_percentile'|'monetary_percentile' $metric
     * @return int[]
     */
    private function resolvePercentileAtLeast(array $params, string $metric): array
    {
        $percentile = $params['percentile'] ?? null;

        if ($percentile === null || !is_numeric($percentile)) {
            return [];
        }

        $threshold = (float) $percentile;
        $matching = [];

        foreach ($this->getPercentileRanks() as $customerId => $ranks) {
            if ($ranks[$metric] >= $threshold) {
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

    /**
     * The exclusion counterpart to resolveInSegment() - closes the ROADMAP.md "segment exclusion
     * operator" item ("customers in A but NOT in B"). Computed as the full registered-customer
     * universe (RfmCalculator::getAllCustomerIds(), the same universe resolveOrderFrequencyAtLeast()/
     * resolveMonetaryTotalAtLeast() already use for their own "threshold <= 0 matches everyone"
     * case) minus whoever getMatchingCustomerIds() resolves for the target segment - an O(n+m)
     * flipped-lookup diff, not array_diff(), for the same reason TagInactiveCustomers's own
     * untag pass was fixed to avoid an O(n*m) in_array() scan.
     *
     * @param array<string, mixed> $params
     * @param int[] $visitedSegmentIds
     * @return int[]
     */
    private function resolveNotInSegment(array $params, array $visitedSegmentIds): array
    {
        $targetSegmentId = $params['segment_id'] ?? null;

        if ($targetSegmentId === null || !is_numeric($targetSegmentId)) {
            return [];
        }

        $targetSegmentId = (int) $targetSegmentId;

        if (in_array($targetSegmentId, $visitedSegmentIds, true)) {
            // Cycle (this segment excludes itself, directly or via another segment already
            // being resolved) - fail closed the same way resolveInSegment() does, rather than
            // silently treating an unresolvable exclusion as "excludes nobody" (which would
            // match everyone here, the opposite of this module's fail-closed convention).
            return [];
        }

        $excluded = array_flip($this->getMatchingCustomerIds($targetSegmentId, $visitedSegmentIds));

        return array_values(array_filter(
            $this->rfmCalculator->getAllCustomerIds(),
            static fn (int $customerId): bool => !isset($excluded[$customerId])
        ));
    }

    /**
     * @return array<int, array{frequency: int, monetary: float, recency_days: int|null}>
     */
    private function getAggregates(): array
    {
        $this->aggregatesCache ??= $this->rfmCalculator->getAggregatesForAllCustomers();

        return $this->aggregatesCache;
    }

    /**
     * @return array<int, array{recency_percentile: float, frequency_percentile: float, monetary_percentile: float}>
     */
    private function getPercentileRanks(): array
    {
        $this->percentileRanksCache ??= $this->rfmCalculator->getPercentileRanks();

        return $this->percentileRanksCache;
    }
}
