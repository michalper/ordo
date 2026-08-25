<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\CustomerTagManager;

/**
 * Params: {"tag": "vip"}. Context must include "customer_id".
 */
class AddTag implements ActionInterface
{
    public function __construct(
        private readonly CustomerTagManager $customerTagManager
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $tag = (string) ($params['tag'] ?? '');

        if ($customerId <= 0 || $tag === '') {
            return;
        }

        $this->customerTagManager->addTag($customerId, $tag);
    }
}
