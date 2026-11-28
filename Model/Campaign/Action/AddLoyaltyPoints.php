<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\LoyaltyPointsManager;

/**
 * Params: {"points": "100"}. Context must include "customer_id". Not AddPoints - that class is
 * already generic/attachable to any trigger too, but it awards CustomerScoreManager's lead-scoring
 * score, a completely different "points" concept from the loyalty rewards balance
 * (Model\LoyaltyPointsManager/ordo_loyalty_points_ledger) this action awards instead - see
 * docs/adr/0089 for the mix-up in this module's own history that this action closes. A
 * non-numeric or non-positive value is a no-op, same reasoning AddPoints gives for its own
 * zero-points case - there's no meaningful "award zero (or negative) loyalty points" campaign
 * step, and this ledger has no penalty/clawback concept the way the lead-scoring one does.
 */
class AddLoyaltyPoints implements ActionInterface
{
    public function __construct(
        private readonly LoyaltyPointsManager $loyaltyPointsManager
    ) {
    }

    public function execute(array &$context, array $params): void
    {
        $customerId = (int) ($context['customer_id'] ?? 0);
        $points = (int) ($params['points'] ?? 0);

        if ($customerId <= 0 || $points <= 0) {
            return;
        }

        $this->loyaltyPointsManager->awardBonus($customerId, $points, 'Awarded by campaign action.');
    }

    public function getParamFields(): array
    {
        return [
            ['name' => 'points', 'label' => (string) __('Points')],
        ];
    }
}
