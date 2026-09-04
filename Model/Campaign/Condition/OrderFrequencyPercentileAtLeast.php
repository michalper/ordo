<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

/**
 * The "F" in RFM, relative instead of absolute. Params: {"percentile": "80"} — satisfied when
 * the customer's order-frequency percentile across the whole customer base is at least this,
 * i.e. "top 20% most frequent buyers or better". See RfmCalculator::getPercentileRanks() for the
 * exact percentile definition. Fails closed for a customer that isn't in the ranking at all.
 */
class OrderFrequencyPercentileAtLeast extends AbstractPercentileAtLeast
{
    protected function getPercentileKey(): string
    {
        return 'frequency_percentile';
    }
}
