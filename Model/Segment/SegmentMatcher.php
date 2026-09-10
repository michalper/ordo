<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Segment;

use Ordo\Automation\Model\Condition\ConditionGroupEvaluator;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Model\SegmentFactory;

/**
 * Evaluates a saved segment's conditions against a customer, reusing the exact same
 * ConditionPool/fail-closed rules CampaignDispatcher uses for a campaign's own conditions (see
 * CampaignDispatcher::conditionsSatisfied) — a segment IS just a named, reusable set of campaign
 * conditions, so it should behave identically. Combines them with AND or OR depending on the
 * segment's own condition_logic (Model\Segment::getConditionLogic()) — historically always AND,
 * "any" (OR) is the newer option (see ordo_segment_form.xml's ALL/ANY toggle).
 *
 * Conditions can nest: a row whose type is the reserved 'group' pseudo-type holds, instead of a
 * real ConditionPool condition, its own {"logic": "all"|"any", "conditions": [...]} — arbitrarily
 * deep, since a nested group's own "conditions" entries can themselves be further groups. This
 * reuses the existing ordo_segment_condition table/params column as-is (no schema change) rather
 * than a parent/child group table: 'group' is deliberately NOT registered in ConditionPool, so it
 * can't be selected via the admin Type dropdown yet — this is a data-model capability ahead of
 * its own UI (see ROADMAP.md), reachable today only by writing the row directly (API/DB), not a
 * half-finished escape hatch back to hand-written JSON.
 *
 * A segment with zero conditions never matches anyone regardless of condition_logic — an empty
 * AND is vacuously true and an empty OR is vacuously false in boolean logic, but "matches every
 * customer" is almost certainly not what an admin who forgot to add conditions to a new segment
 * intended, so this deliberately fails closed instead either way. Same for an empty nested group.
 */
class SegmentMatcher
{
    public function __construct(
        private readonly SegmentConditionCollectionFactory $segmentConditionCollectionFactory,
        private readonly ConditionGroupEvaluator $conditionGroupEvaluator,
        private readonly SegmentFactory $segmentFactory,
        private readonly SegmentResource $segmentResource
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

        $segment = $this->segmentFactory->create();
        $this->segmentResource->load($segment, $segmentId);

        $specs = [];
        foreach ($conditions as $conditionRow) {
            $specs[] = [
                'type' => (string) $conditionRow->getData('type'),
                'params' => $this->asStringKeyedArray($conditionRow->getParams()),
            ];
        }

        return $this->conditionGroupEvaluator->evaluate(
            $specs,
            $segment->getConditionLogic(),
            $context,
            'segment condition'
        );
    }

    /**
     * Normalizes a decoded-JSON value (or a mixed-typed getParams() call - see the top-level
     * caller above) into a guaranteed array<string, mixed>, dropping any non-string key a
     * hand-written 'group' params blob could otherwise contain - condition params are
     * conceptually always a key-value map, never a list.
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
}
