<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Rfm\RfmCalculator;

/**
 * The "M" in RFM. Params: {"amount": "500"} — satisfied when the sum of the customer's
 * non-canceled order totals is at least this amount.
 */
class MonetaryTotalAtLeast implements ConditionInterface
{
    public function __construct(
        private readonly RfmCalculator $rfmCalculator
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $amount = $params['amount'] ?? null;

        if ($customerId <= 0 || $amount === null || !is_numeric($amount)) {
            return false;
        }

        return $this->rfmCalculator->getMonetaryTotal($customerId) >= (float) $amount;
    }
}
