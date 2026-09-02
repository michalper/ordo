<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Rfm\RfmCalculator;

/**
 * The "F" in RFM, relative instead of absolute. Params: {"percentile": "80"} — satisfied when
 * the customer's order-frequency percentile across the whole customer base is at least this,
 * i.e. "top 20% most frequent buyers or better". See RfmCalculator::getPercentileRanks() for the
 * exact percentile definition. Fails closed for a customer that isn't in the ranking at all.
 */
class OrderFrequencyPercentileAtLeast implements ConditionInterface
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

        return $ranks[$customerId]['frequency_percentile'] >= (float) $percentile;
    }
}
