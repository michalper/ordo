<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;

/**
 * Params: {"amount": "500"}. Context must include "order_total" (float).
 */
class OrderTotalAtLeast implements ConditionInterface, BulkResolvableConditionInterface
{
    public function isSatisfied(array $context, array $params): bool
    {
        if (!isset($context['order_total'])) {
            return false;
        }

        $threshold = (float) ($params['amount'] ?? 0);
        return (float) $context['order_total'] >= $threshold;
    }

    /**
     * An order_total is only ever present in a single event's dispatch context (order_placed),
     * never a property of a standing customer - there's nothing to resolve a segment's "currently
     * matching" set against, so this always resolves to nobody. Implementing the interface here
     * (rather than leaving this type unregistered for Segment\SegmentMemberResolver) keeps that
     * knowledge with the condition itself, consistent with every other type - the resolver never
     * needs a hardcoded case for it.
     */
    public function resolveCustomerIds(array $params): array
    {
        return [];
    }
}
