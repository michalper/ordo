<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Rfm\RfmCalculator;

/**
 * The "R" in RFM. Params: {"days": "30"} — satisfied when the customer's most recent
 * non-canceled order was at most this many days ago. A customer with no orders at all has no
 * recency to compare, so this fails closed for them (never satisfied) rather than treating
 * "never ordered" as infinitely recent or infinitely stale.
 */
class RecencyDaysAtMost implements ConditionInterface
{
    public function __construct(
        private readonly RfmCalculator $rfmCalculator
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $days = $params['days'] ?? null;

        if ($customerId <= 0 || $days === null || !is_numeric($days)) {
            return false;
        }

        $recencyDays = $this->rfmCalculator->getRecencyDays($customerId);

        return $recencyDays !== null && $recencyDays <= (int) $days;
    }
}
