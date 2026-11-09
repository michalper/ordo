<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\LoyaltyPointsManager;

/**
 * Daily - a no-op when Helper\Config::getLoyaltyPointsExpiryDays() is 0 (never expire, the
 * default). See LoyaltyPointsManager::expireOldEarnedPoints() for the actual expiry logic.
 */
class ExpireLoyaltyPoints
{
    public function __construct(
        private readonly LoyaltyPointsManager $loyaltyPointsManager,
        private readonly Config $config
    ) {
    }

    public function execute(): void
    {
        $expiryDays = $this->config->getLoyaltyPointsExpiryDays();
        if ($expiryDays <= 0) {
            return;
        }

        $this->loyaltyPointsManager->expireOldEarnedPoints($expiryDays);
    }
}
