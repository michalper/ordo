<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Segment;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\Condition\GroupWalker;
use Ordo\Automation\Model\Condition\SetGroupCombineStrategy;
use Ordo\Automation\Model\ReorderCycle\ReorderCycleDriftCalculator;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Segment\Website\CollectionFactory as SegmentWebsiteCollectionFactory;
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
 *    {"logic": ..., "conditions": [...]} the same way SegmentMatcher does.
 *  - order_total_gte / visitor_tag are per-event-context conditions with no meaning for a
 *    standing set of customers; their own OrderTotalAtLeast::resolveCustomerIds()/
 *    VisitorHasTag::resolveCustomerIds() always return [] to say so.
 *
 * The AND/OR/nested-group tree-walk itself (previously this class's own resolveList()/
 * resolveGroup()/resolveOne(), duplicated near-verbatim from Model\Condition\
 * ConditionGroupEvaluator's equivalent per-customer boolean walk) now lives in the shared
 * Model\Condition\GroupWalker, driven here by Model\Condition\SetGroupCombineStrategy - this
 * class supplies only what's genuinely specific to the set domain: the leaf resolver below
 * (resolveCondition()) and that one combine strategy.
 *
 * Every leaf condition type resolves through its own ConditionPool-registered class
 * (BulkResolvableConditionInterface::resolveCustomerIds()) - adding a new condition type never
 * needs this class touched. in_segment/not_in_segment are the one deliberate exception: they need
 * $visitedSegmentIds threaded through for cycle detection (A references B references A), which
 * BulkResolvableConditionInterface's plain `resolveCustomerIds(array $params)` signature has no
 * room for without widening it for every other implementer's sake - so they stay as explicit
 * switch arms calling back into this class's own getMatchingCustomerIds().
 */
class SegmentMemberResolver
{
    /**
     * While true, top-level getMatchingCustomerIds() calls stop resetting the RFM/drift-ratio
     * snapshots below, so a caller resolving many segments in one pass (the audience-size cron,
     * the RFM grid's matching-segments column) pays for the whole-base scans once, not once per
     * segment. See beginBatch().
     */
    private bool $batchSnapshotActive = false;

    public function __construct(
        private readonly SegmentConditionCollectionFactory $segmentConditionCollectionFactory,
        private readonly RfmCalculator $rfmCalculator,
        private readonly SegmentFactory $segmentFactory,
        private readonly SegmentResource $segmentResource,
        private readonly LoggerInterface $logger,
        private readonly GroupWalker $groupWalker,
        private readonly SetGroupCombineStrategy $combineStrategy,
        private readonly ReorderCycleDriftCalculator $reorderCycleDriftCalculator,
        private readonly ConditionPool $conditionPool,
        private readonly SegmentWebsiteCollectionFactory $segmentWebsiteCollectionFactory,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Keeps one RFM/drift snapshot across every getMatchingCustomerIds() call until endBatch() —
     * for callers resolving all segments in one pass, where per-segment freshness is pointless
     * (the underlying sales_order data won't meaningfully change mid-pass) and re-running the
     * whole-base GROUP BY per segment is the dominant cost. Ad-hoc single resolves outside a
     * batch keep the existing reset-per-top-level-call behavior. Always pair with endBatch()
     * (try/finally) so a thrown resolve can't leave a long-lived process serving a stale snapshot.
     *
     * The snapshots themselves live on RfmCalculator/ReorderCycleDriftCalculator, not here - once
     * the RFM/drift segment conditions (Campaign\Condition\RecencyDaysAtMost and siblings) became
     * independently resolvable via ConditionPool instead of hardcoded resolver methods on this
     * class, they started calling those calculators directly too, so the cache has to live where
     * every caller can reach it, not just this class's own now-removed wrapper cache.
     */
    public function beginBatch(): void
    {
        $this->batchSnapshotActive = true;
        $this->resetSnapshotCaches();
    }

    public function endBatch(): void
    {
        $this->batchSnapshotActive = false;
        // Also free the snapshot right away — in a long-lived process the next top-level resolve
        // would reset it anyway, but holding a whole-customer-base map until then is pure waste.
        $this->resetSnapshotCaches();
    }

    private function resetSnapshotCaches(): void
    {
        $this->rfmCalculator->resetSnapshotCache();
        $this->reorderCycleDriftCalculator->resetSnapshotCache();
    }

    /**
     * @param int[] $visitedSegmentIds segment IDs already being resolved in this call chain,
     *  used to guard against in_segment cycles (A references B references A)
     * @return int[]
     */
    public function getMatchingCustomerIds(int $segmentId, array $visitedSegmentIds = []): array
    {
        if ($visitedSegmentIds === [] && !$this->batchSnapshotActive) {
            // Fresh top-level call (not a recursive in_segment hop) — start a new aggregates
            // snapshot for this resolve. A recursive call keeps reusing the same one, and a
            // batch (beginBatch()) keeps one snapshot across top-level calls too.
            $this->resetSnapshotCaches();
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

        $result = $this->groupWalker->walk(
            $specs,
            $segment->getConditionLogic(),
            fn (array $spec): array => $this->resolveCondition($spec['type'], $spec['params'], $visitedSegmentIds),
            $this->combineStrategy
        );

        /** @var int[] $result */
        return $this->filterByWebsite($segmentId, $result);
    }

    /**
     * Drops any resolved customer id not on one of this segment's own configured websites - a
     * segment with NO website rows (the common case) is unscoped and this is a no-op, see
     * docs/adr/0014. Applied to every top-level AND recursive (in_segment/not_in_segment) call,
     * so a nested segment's own website scope applies to its own member set exactly the same way
     * its own conditions do - resolveInSegment()/resolveNotInSegment() both go through
     * getMatchingCustomerIds() again, so this never needs calling from more than one place.
     *
     * @param int[] $customerIds
     * @return int[]
     */
    private function filterByWebsite(int $segmentId, array $customerIds): array
    {
        if ($customerIds === []) {
            return $customerIds;
        }

        $websiteIds = [];
        $websiteRows = $this->segmentWebsiteCollectionFactory->create();
        $websiteRows->addSegmentFilter($segmentId);
        foreach ($websiteRows as $row) {
            /** @var \Ordo\Automation\Model\SegmentWebsite $row */
            $websiteIds[] = $row->getWebsiteId();
        }

        if ($websiteIds === []) {
            return $customerIds;
        }

        $connection = $this->resourceConnection->getConnection();
        $customerTable = $this->resourceConnection->getTableName('customer_entity');

        $matching = $connection->fetchCol(
            $connection->select()
                ->from($customerTable, 'entity_id')
                ->where('entity_id IN (?)', $customerIds)
                ->where('website_id IN (?)', $websiteIds)
        );

        return array_map('intval', $matching);
    }

    /**
     * @param array<string, mixed> $params
     * @param int[] $visitedSegmentIds
     * @return int[]
     */
    private function resolveCondition(string $type, array $params, array $visitedSegmentIds): array
    {
        switch ($type) {
            case 'in_segment':
                return $this->resolveInSegment($params, $visitedSegmentIds);
            case 'not_in_segment':
                return $this->resolveNotInSegment($params, $visitedSegmentIds);
            default:
                // Every other type resolves entirely through its own ConditionPool-registered
                // class - the same registry CampaignDispatcher and SegmentMatcher read, so a new
                // condition type never needs this switch touched. This arm exists because not
                // every registered type is resolvable at the set level (e.g. order_total_gte,
                // visitor_tag - see this class's own docblock); those still explicitly implement
                // BulkResolvableConditionInterface, just always returning [].
                $condition = $this->conditionPool->get($type);
                if ($condition instanceof BulkResolvableConditionInterface) {
                    return $condition->resolveCustomerIds($params);
                }

                $this->logger->error(sprintf(
                    'Ordo_Automation: segment condition type "%s" is unknown or not resolvable at the set level.',
                    $type
                ));
                return [];
        }
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
     * universe (RfmCalculator::getAllCustomerIds(), the same universe Campaign\Condition\
     * OrderFrequencyAtLeast/MonetaryTotalAtLeast already use for their own "threshold <= 0 matches
     * everyone" case) minus whoever getMatchingCustomerIds() resolves for the target segment - an
     * O(n+m) flipped-lookup diff, not array_diff(), for the same reason TagInactiveCustomers's own
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
}
