<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\TagChurnRiskCustomers;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ChurnRisk\ChurnRiskCalculator;
use Ordo\Automation\Model\CustomerTagManager;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class TagChurnRiskCustomersTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenChurnRiskDigestDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isChurnRiskDigestEnabled')->willReturn(false);

        $churnRiskCalculator = $this->createMock(ChurnRiskCalculator::class);
        $churnRiskCalculator->expects(self::never())->method('getChurnRiskRatiosForAllCustomers');

        $tagManager = $this->createMock(CustomerTagManager::class);

        (new TagChurnRiskCustomers(
            $config,
            $churnRiskCalculator,
            $tagManager,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteTagsNewlyAtRiskAndClearsRecovered(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isChurnRiskDigestEnabled')->willReturn(true);
        $config->method('getChurnRiskAtRiskRatio')->willReturn(0.75);

        $churnRiskCalculator = $this->createStub(ChurnRiskCalculator::class);
        $churnRiskCalculator->method('getChurnRiskRatiosForAllCustomers')->willReturn([5 => 0.9, 6 => 0.5, 7 => 0.8]);

        // The actual tag/untag logic is CustomerTagManager::syncTagForSet()'s own responsibility
        // now (tested directly in CustomerTagManagerTest) - this test only verifies the cron
        // passes it the right qualifying set and reports its result.
        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::once())->method('syncTagForSet')
            ->with([5, 7], TagChurnRiskCustomers::TAG_CHURN_RISK)
            ->willReturn(['tagged' => 1, 'untagged' => [9]]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')
            ->with(self::stringContains('tagged 1 customers as churn-risk, cleared 1'));

        (new TagChurnRiskCustomers(
            $config,
            $churnRiskCalculator,
            $tagManager,
            $this->makeCronRunLogger($logger)
        ))->execute();
    }
}
