<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Rfm\RfmCalculator;

/**
 * The "F" in RFM. Params: {"count": "3"} — satisfied when the customer has placed at least
 * this many non-canceled orders.
 */
class OrderFrequencyAtLeast implements ConditionInterface, BulkResolvableConditionInterface
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

    /**
     * A zero-order customer never appears in getAggregatesForAllCustomers() (it's a GROUP BY
     * over sales_order), but still satisfies "frequency >= 0" under the single-customer path
     * (getFrequency() returns 0 for them) — so threshold <= 0 matches everyone, not just
     * customers who happen to have placed an order.
     */
    public function resolveCustomerIds(array $params): array
    {
        $count = $params['count'] ?? null;

        if ($count === null || !is_numeric($count)) {
            return [];
        }

        $threshold = (int) $count;

        if ($threshold <= 0) {
            return $this->rfmCalculator->getAllCustomerIds();
        }

        $matching = [];

        foreach ($this->rfmCalculator->getAggregatesForAllCustomers() as $customerId => $aggregate) {
            if ($aggregate['frequency'] >= $threshold) {
                $matching[] = $customerId;
            }
        }

        return $matching;
    }
}
