<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;

/**
 * Params: {"amount": "500"}. Context must include "order_total" (float).
 */
class OrderTotalAtLeast implements ConditionInterface
{
    public function isSatisfied(array $context, array $params): bool
    {
        if (!isset($context['order_total'])) {
            return false;
        }

        $threshold = (float) ($params['amount'] ?? 0);
        return (float) $context['order_total'] >= $threshold;
    }
}
