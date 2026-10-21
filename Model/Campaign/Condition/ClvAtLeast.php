<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Clv\ClvCalculator;

/**
 * The forward-looking counterpart to MonetaryTotalAtLeast — that condition matches on money
 * already spent, this one matches on ClvCalculator's projected future value. Params:
 * {"amount": "5000"} — satisfied when the customer's projected CLV is at least this amount.
 */
class ClvAtLeast implements ConditionInterface, BulkResolvableConditionInterface
{
    public function __construct(
        private readonly ClvCalculator $clvCalculator
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $amount = $params['amount'] ?? null;

        if ($customerId <= 0 || $amount === null || !is_numeric($amount)) {
            return false;
        }

        return $this->clvCalculator->getProjectedClv($customerId) >= (float) $amount;
    }

    public function resolveCustomerIds(array $params): array
    {
        $amount = $params['amount'] ?? null;

        if ($amount === null || !is_numeric($amount)) {
            return [];
        }

        $threshold = (float) $amount;
        $matching = [];

        // getClvScores() serves Cron\RecomputeClvScores's precomputed table when populated and
        // computes live otherwise, so this never runs one projection query per customer.
        foreach ($this->clvCalculator->getClvScores() as $customerId => $clv) {
            if ($clv >= $threshold) {
                $matching[] = $customerId;
            }
        }

        return $matching;
    }
}
