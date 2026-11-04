<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\CustomerTagManager;

/**
 * Params: {"tags": ["vip", "loyal"]}. Context must include "customer_id". Adds every tag in the
 * list - no match_mode here, that's a condition-only concept (what you check for), not something
 * an action (what you do) needs.
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
        $tags = $params['tags'] ?? [];

        if ($customerId <= 0 || !is_array($tags)) {
            return;
        }

        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $this->customerTagManager->addTag($customerId, $tag);
            }
        }
    }
}
