<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Rfm\RfmCalculator;

/**
 * The "R" in RFM, relative instead of absolute. Params: {"percentile": "80"} — satisfied when
 * the customer's recency percentile across the whole customer base is at least this, i.e. "among
 * the 20% most recently active customers or better". Higher percentile always means better
 * (more recent), even though the underlying metric — days since last order — is better when
 * lower; see RfmCalculator::getPercentileRanks() for the exact definition. A customer with no
 * orders ranks at or near percentile 0, so they never satisfy a meaningful threshold, and a
 * customer that isn't in the ranking at all fails closed.
 */
class RecencyPercentileAtLeast implements ConditionInterface
{
    public function __construct(
        private readonly RfmCalculator $rfmCalculator
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $percentile = $params['percentile'] ?? null;

        if ($customerId <= 0 || $percentile === null || !is_numeric($percentile)) {
            return false;
        }

        $ranks = $this->rfmCalculator->getPercentileRanks();

        if (!isset($ranks[$customerId])) {
            return false;
        }

        return $ranks[$customerId]['recency_percentile'] >= (float) $percentile;
    }
}
