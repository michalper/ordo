<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\ExpireLoyaltyPoints;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\LoyaltyPointsManager;
use PHPUnit\Framework\TestCase;

class ExpireLoyaltyPointsTest extends TestCase
{
    public function testExecuteDoesNothingWhenExpiryIsDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getLoyaltyPointsExpiryDays')->willReturn(0);

        $loyaltyPointsManager = $this->createMock(LoyaltyPointsManager::class);
        $loyaltyPointsManager->expects(self::never())->method('expireOldEarnedPoints');

        (new ExpireLoyaltyPoints($loyaltyPointsManager, $config))->execute();
    }

    public function testExecuteExpiresPointsOlderThanTheConfiguredDays(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getLoyaltyPointsExpiryDays')->willReturn(365);

        $loyaltyPointsManager = $this->createMock(LoyaltyPointsManager::class);
        $loyaltyPointsManager->expects(self::once())->method('expireOldEarnedPoints')->with(365);

        (new ExpireLoyaltyPoints($loyaltyPointsManager, $config))->execute();
    }
}
