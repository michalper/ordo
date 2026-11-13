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
        $churnRiskCalculator->method('getChurnRiskRatiosForAllCustomers')->willReturn([5 => 0.9, 6 => 0.5]);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTagFromSet')->willReturnMap([
            [[5], TagChurnRiskCustomers::TAG_CHURN_RISK, []],
        ]);
        $tagManager->expects(self::once())->method('addTag')
            ->with(5, TagChurnRiskCustomers::TAG_CHURN_RISK);
        $tagManager->method('getCustomerIdsWithTag')->willReturnMap([
            [TagChurnRiskCustomers::TAG_CHURN_RISK, [9]],
        ]);
        $tagManager->expects(self::once())->method('removeTag')
            ->with(9, TagChurnRiskCustomers::TAG_CHURN_RISK);

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
