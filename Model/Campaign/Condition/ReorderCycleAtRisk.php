<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Condition;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\ReorderCycle\ReorderCycleDriftCalculator;

/**
 * Params: {"ratio_at_least": "0.8"} — satisfied when the customer's worst reorder-cycle drift
 * ratio (ReorderCycleDriftCalculator: elapsed days since last order, divided by their own
 * historically detected average interval) is at least this. A ratio below 1.0 fires earlier than
 * Cron\SendReorderReminders' own "already due" reminder (which effectively fires around ratio
 * 1.0, adjusted by lead_days) — the whole point of this condition being a standing segment signal
 * an admin can act on before a reminder would even qualify to send, not a duplicate of it. A
 * customer with no detected reorder cycle at all has nothing to drift from, so this fails closed
 * for them.
 */
class ReorderCycleAtRisk implements ConditionInterface
{
    public function __construct(
        private readonly ReorderCycleDriftCalculator $driftCalculator
    ) {
    }

    public function isSatisfied(array $context, array $params): bool
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $ratioAtLeast = $params['ratio_at_least'] ?? null;

        if ($customerId <= 0 || $ratioAtLeast === null || !is_numeric($ratioAtLeast)) {
            return false;
        }

        $ratio = $this->driftCalculator->getDriftRatioForCustomer($customerId);

        return $ratio !== null && $ratio >= (float) $ratioAtLeast;
    }
}
