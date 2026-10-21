<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Campaign;

/**
 * Optional set-level counterpart to ConditionInterface::isSatisfied(): a condition implementing
 * this can also resolve "every customer currently satisfying these params" in one pass, which
 * lets Model\Segment\SegmentMemberResolver support the type without growing its own hardcoded
 * switch arm — the resolver asks the ConditionPool, the same registry CampaignDispatcher and
 * SegmentMatcher already use. A pool condition that does NOT implement this is simply not
 * resolvable at the set level and fails closed there (matches nobody, with a logged error),
 * exactly as before.
 */
interface BulkResolvableConditionInterface
{
    /**
     * Customer IDs currently satisfying the condition under the given params. Invalid/missing
     * params resolve to an empty set (fail closed), mirroring isSatisfied() returning false.
     *
     * @param array<string, mixed> $params
     * @return int[]
     */
    public function resolveCustomerIds(array $params): array;
}
