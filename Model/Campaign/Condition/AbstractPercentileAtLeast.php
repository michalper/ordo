<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Rfm\RfmCalculator;

/**
 * Shared "is this customer's RFM percentile for one metric at least N" shape — extracted after
 * SonarCloud flagged Recency/OrderFrequency/MonetaryPercentileAtLeast as duplicated code; the
 * three condition classes were identical except for which key of
 * RfmCalculator::getPercentileRanks() each one reads. getPercentileKey() is the only thing a
 * concrete condition needs to supply.
 */
abstract class AbstractPercentileAtLeast implements ConditionInterface, BulkResolvableConditionInterface
{
    public function __construct(
        private readonly RfmCalculator $rfmCalculator
    ) {
    }

    /**
     * The `ranks[$customerId]` key this condition reads — one of 'recency_percentile',
     * 'frequency_percentile', 'monetary_percentile' (see RfmCalculator::getPercentileRanks()).
     */
    abstract protected function getPercentileKey(): string;

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

        return $ranks[$customerId][$this->getPercentileKey()] >= (float) $percentile;
    }

    /**
     * Percentile 100 is always the best end of every metric (see RfmCalculator::
     * getPercentileRanks()), so ">= threshold" reads the same way for all three concrete subclasses.
     */
    public function resolveCustomerIds(array $params): array
    {
        $percentile = $params['percentile'] ?? null;

        if ($percentile === null || !is_numeric($percentile)) {
            return [];
        }

        $threshold = (float) $percentile;
        $key = $this->getPercentileKey();
        $matching = [];

        foreach ($this->rfmCalculator->getPercentileRanks() as $customerId => $ranks) {
            if ($ranks[$key] >= $threshold) {
                $matching[] = $customerId;
            }
        }

        return $matching;
    }
}
