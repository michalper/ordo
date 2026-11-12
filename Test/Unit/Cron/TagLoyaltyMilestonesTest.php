<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\TagLoyaltyMilestones;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\LoyaltyTierCalculator;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TagLoyaltyMilestonesTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoyaltyMilestonesEnabled')->willReturn(false);

        $rfmCalculator = $this->createMock(RfmCalculator::class);
        $rfmCalculator->expects(self::never())->method('getAggregatesForAllCustomers');

        $scoreManager = $this->createMock(CustomerScoreManager::class);
        $scoreManager->expects(self::never())->method('getCustomerIdsWithScoreAtLeast');

        (new TagLoyaltyMilestones(
            $config,
            $rfmCalculator,
            $scoreManager,
            $this->createStub(LoyaltyTierCalculator::class),
            $this->createMock(CustomerTagManager::class),
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        ))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteTagsEveryOrderCountMilestoneReached(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoyaltyMilestonesEnabled')->willReturn(true);

        // Customer 1 has placed 12 orders - qualifies for 1, 5, and 10, not 25/50.
        // Customer 2 has placed 3 orders - qualifies for 1 only.
        $rfmCalculator = $this->createStub(RfmCalculator::class);
        $rfmCalculator->method('getAggregatesForAllCustomers')->willReturn([
            1 => ['frequency' => 12, 'monetary' => 0.0, 'recency_days' => 1],
            2 => ['frequency' => 3, 'monetary' => 0.0, 'recency_days' => 1],
        ]);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTagFromSet')->willReturnMap([
            [[1, 2], 'milestone_order_1', [2]],
            [[1], 'milestone_order_5', []],
            [[1], 'milestone_order_10', []],
        ]);

        $addedTags = [];
        $tagManager->method('addTag')->willReturnCallback(
            function (int $customerId, string $tag) use (&$addedTags): void {
                $addedTags[] = [$customerId, $tag];
            }
        );

        $scoreManager = $this->createStub(CustomerScoreManager::class);
        $scoreManager->method('getCustomerIdsWithScoreAtLeast')->willReturn([]);

        $loyaltyTierCalculator = $this->createStub(LoyaltyTierCalculator::class);
        $loyaltyTierCalculator->method('getMinimumScoreForTier')->willReturn(100);

        (new TagLoyaltyMilestones(
            $config,
            $rfmCalculator,
            $scoreManager,
            $loyaltyTierCalculator,
            $tagManager,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        ))->execute();

        self::assertContains([1, 'milestone_order_1'], $addedTags);
        self::assertContains([1, 'milestone_order_5'], $addedTags);
        self::assertContains([1, 'milestone_order_10'], $addedTags);
        self::assertNotContains([2, 'milestone_order_1'], $addedTags);
        self::assertCount(3, $addedTags);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteTagsBothTiersForAGoldCustomer(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoyaltyMilestonesEnabled')->willReturn(true);

        $rfmCalculator = $this->createStub(RfmCalculator::class);
        $rfmCalculator->method('getAggregatesForAllCustomers')->willReturn([]);

        $loyaltyTierCalculator = $this->createStub(LoyaltyTierCalculator::class);
        $loyaltyTierCalculator->method('getMinimumScoreForTier')->willReturnMap([
            [LoyaltyTierCalculator::SILVER, 50],
            [LoyaltyTierCalculator::GOLD, 200],
        ]);

        $scoreManager = $this->createStub(CustomerScoreManager::class);
        $scoreManager->method('getCustomerIdsWithScoreAtLeast')->willReturnMap([
            [50, [7]],
            [200, [7]],
        ]);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTag')->willReturn([]);

        $addedTags = [];
        $tagManager->method('addTag')->willReturnCallback(
            function (int $customerId, string $tag) use (&$addedTags): void {
                $addedTags[] = [$customerId, $tag];
            }
        );

        (new TagLoyaltyMilestones(
            $config,
            $rfmCalculator,
            $scoreManager,
            $loyaltyTierCalculator,
            $tagManager,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        ))->execute();

        self::assertContains([7, 'milestone_tier_silver'], $addedTags);
        self::assertContains([7, 'milestone_tier_gold'], $addedTags);
        self::assertCount(2, $addedTags);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNotReTagAlreadyEarnedMilestones(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isLoyaltyMilestonesEnabled')->willReturn(true);

        $rfmCalculator = $this->createStub(RfmCalculator::class);
        $rfmCalculator->method('getAggregatesForAllCustomers')->willReturn([
            3 => ['frequency' => 1, 'monetary' => 0.0, 'recency_days' => 1],
        ]);

        $tagManager = $this->createMock(CustomerTagManager::class);
        $tagManager->method('getCustomerIdsWithTagFromSet')->willReturn([3]);
        $tagManager->expects(self::never())->method('addTag');

        $scoreManager = $this->createStub(CustomerScoreManager::class);
        $scoreManager->method('getCustomerIdsWithScoreAtLeast')->willReturn([]);

        $loyaltyTierCalculator = $this->createStub(LoyaltyTierCalculator::class);
        $loyaltyTierCalculator->method('getMinimumScoreForTier')->willReturn(100);

        (new TagLoyaltyMilestones(
            $config,
            $rfmCalculator,
            $scoreManager,
            $loyaltyTierCalculator,
            $tagManager,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class))
        ))->execute();
    }
}
