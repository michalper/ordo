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
        $driftCalculator->method('getDriftRatiosForAllCustomers')->willReturn([5 => 0.9, 6 => 0.5, 7 => 0.8]);

        // The actual tag/untag logic is CustomerTagManager::syncTagForSet()'s own responsibility
        // now (tested directly in CustomerTagManagerTest) - this test only verifies the cron
        // passes it the right qualifying set and reports its result.
        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->expects(self::once())->method('syncTagForSet')
            ->with([5, 7], TagReorderCycleAtRiskCustomers::TAG_REORDER_AT_RISK)
            ->willReturn(['tagged' => 1, 'untagged' => [9]]);

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
