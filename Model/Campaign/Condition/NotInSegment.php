<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Segment\SegmentMatcher;

/**
 * Params: {"segment_id": "3"}. Context must include "customer_id". The exclusion counterpart to
 * InSegment - lets a campaign/segment target "everyone except members of segment X" (e.g. VIPs
 * excluding anyone already in the win-back segment) without a schema change, closing the
 * ROADMAP.md "segment exclusion operator" item.
 *
 * Deliberately does NOT just negate SegmentMatcher::isCustomerInSegment()'s own return value -
 * that method fails closed (returns false, "not in") on a cycle, and blindly negating false would
 * flip to "satisfied" for every customer, the opposite of this module's fail-closed convention
 * elsewhere (an unresolvable condition should never cause a segment/campaign to over-include).
 * So the cycle check is duplicated here and checked first, failing this condition closed too.
 */
class NotInSegment implements ConditionInterface
{
    public function __construct(
        private readonly SegmentMatcher $segmentMatcher
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $segmentId = $params['segment_id'] ?? null;

        if ($customerId <= 0 || $segmentId === null || !is_numeric($segmentId)) {
            return false;
        }

        $segmentId = (int) $segmentId;

        $rawVisitedSegmentIds = $context['_in_segment_visited'] ?? [];
        $visitedSegmentIds = is_array($rawVisitedSegmentIds) ? array_map('intval', $rawVisitedSegmentIds) : [];

        if (in_array($segmentId, $visitedSegmentIds, true)) {
            return false;
        }

        return !$this->segmentMatcher->isCustomerInSegment($segmentId, $customerId, $visitedSegmentIds);
    }
}
