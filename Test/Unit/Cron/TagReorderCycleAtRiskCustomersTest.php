<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\TagReorderCycleAtRiskCustomers;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\ReorderCycle\ReorderCycleDriftCalculator;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class TagReorderCycleAtRiskCustomersTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenReorderRemindersDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReorderReminderEnabled')->willReturn(false);

        $driftCalculator = $this->createMock(ReorderCycleDriftCalculator::class);
        $driftCalculator->expects(self::never())->method('getDriftRatiosForAllCustomers');

        $tagManager = $this->createMock(CustomerTagManager::class);

        (new TagReorderCycleAtRiskCustomers(
            $config,
            $driftCalculator,
            $tagManager,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteTagsNewlyAtRiskAndClearsRecovered(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReorderReminderEnabled')->willReturn(true);
        $config->method('getReorderAtRiskDriftRatio')->willReturn(0.75);

        $driftCalculator = $this->createStub(ReorderCycleDriftCalculator::class);
        $driftCalculator->method('getDriftRatiosForAllCustomers')->willReturn([5 => 0.9, 6 => 0.5]);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTagFromSet')->willReturnMap([
            [[5], TagReorderCycleAtRiskCustomers::TAG_REORDER_AT_RISK, []],
        ]);
        $tagManager->expects(self::once())->method('addTag')
            ->with(5, TagReorderCycleAtRiskCustomers::TAG_REORDER_AT_RISK);
        $tagManager->method('getCustomerIdsWithTag')->willReturnMap([
            [TagReorderCycleAtRiskCustomers::TAG_REORDER_AT_RISK, [9]],
        ]);
        $tagManager->expects(self::once())->method('removeTag')
            ->with(9, TagReorderCycleAtRiskCustomers::TAG_REORDER_AT_RISK);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')
            ->with(self::stringContains('tagged 1 customers as reorder-cycle-at-risk, cleared 1'));

        (new TagReorderCycleAtRiskCustomers(
            $config,
            $driftCalculator,
            $tagManager,
            $this->makeCronRunLogger($logger)
        ))->execute();
    }
}
