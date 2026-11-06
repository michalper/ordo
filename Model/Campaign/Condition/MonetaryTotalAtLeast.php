<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Rfm\RfmCalculator;

/**
 * The "M" in RFM. Params: {"amount": "500"} — satisfied when the sum of the customer's
 * non-canceled order totals is at least this amount.
 */
class MonetaryTotalAtLeast implements ConditionInterface, BulkResolvableConditionInterface
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

    /**
     * Same reasoning as OrderFrequencyAtLeast::resolveCustomerIds(): a zero-order customer isn't
     * in getAggregatesForAllCustomers() at all, but still satisfies "monetary >= 0" under the
     * single-customer path (getMonetaryTotal() returns 0.0 for them).
     */
    public function resolveCustomerIds(array $params): array
    {
        $amount = $params['amount'] ?? null;

        if ($amount === null || !is_numeric($amount)) {
            return [];
        }

        $threshold = (float) $amount;

        if ($threshold <= 0.0) {
            return $this->rfmCalculator->getAllCustomerIds();
        }

        $matching = [];

        foreach ($this->rfmCalculator->getAggregatesForAllCustomers() as $customerId => $aggregate) {
            if ($aggregate['monetary'] >= $threshold) {
                $matching[] = $customerId;
            }
        }

        return $matching;
    }

    public function getParamFields(): array
    {
        return [
            ['name' => 'amount', 'label' => (string) __('Minimum lifetime total')],
        ];
    }
}
