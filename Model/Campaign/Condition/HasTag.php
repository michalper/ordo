<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\CustomerTagManager;

/**
 * Params: {"tag": "vip"}. Context must include "customer_id".
 */
class HasTag implements ConditionInterface
{
    public function __construct(
        private readonly CustomerTagManager $customerTagManager
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $tag = (string) ($params['tag'] ?? '');

        if ($customerId <= 0 || $tag === '') {
            return false;
        }

        return $this->customerTagManager->hasTag($customerId, $tag);
    }
}
