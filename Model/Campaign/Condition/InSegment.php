<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Segment\SegmentMatcher;

/**
 * Params: {"segment_id": "3"}. Context must include "customer_id". Lets a campaign react to
 * membership in a saved, reusable Segment instead of re-declaring the same conditions inline.
 */
class InSegment implements ConditionInterface
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

        return $this->segmentMatcher->isCustomerInSegment((int) $segmentId, $customerId);
    }
}
