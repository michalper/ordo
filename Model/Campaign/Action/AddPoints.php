<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\CustomerScoreManager;

/**
 * Params: {"points": "10"}. Context must include "customer_id". Points can be negative (a
 * penalty rule), but a non-numeric or zero value is a no-op — there's no meaningful "add zero
 * points" campaign step, and silently accepting garbage input would just corrupt the score.
 */
class AddPoints implements ActionInterface
{
    public function __construct(
        private readonly CustomerScoreManager $customerScoreManager
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $points = (int) ($params['points'] ?? 0);

        if ($customerId <= 0 || $points === 0) {
            return;
        }

        $this->customerScoreManager->addPoints($customerId, $points);
    }
}
