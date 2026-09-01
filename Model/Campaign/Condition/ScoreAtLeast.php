<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\CustomerScoreManager;

/**
 * Params: {"threshold": "50"}. Context must include "customer_id". A missing/non-numeric
 * threshold fails closed (never satisfied), same as every other condition here.
 */
class ScoreAtLeast implements ConditionInterface
{
    public function __construct(
        private readonly CustomerScoreManager $customerScoreManager
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $threshold = $params['threshold'] ?? null;

        if ($customerId <= 0 || $threshold === null || !is_numeric($threshold)) {
            return false;
        }

        return $this->customerScoreManager->getScore($customerId) >= (int) $threshold;
    }
}
