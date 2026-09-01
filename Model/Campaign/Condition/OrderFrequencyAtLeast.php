<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Rfm\RfmCalculator;

/**
 * The "F" in RFM. Params: {"count": "3"} — satisfied when the customer has placed at least
 * this many non-canceled orders.
 */
class OrderFrequencyAtLeast implements ConditionInterface
{
    public function __construct(
        private readonly RfmCalculator $rfmCalculator
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $count = $params['count'] ?? null;

        if ($customerId <= 0 || $count === null || !is_numeric($count)) {
            return false;
        }

        return $this->rfmCalculator->getFrequency($customerId) >= (int) $count;
    }
}
