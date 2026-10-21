<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\LoyaltyTierCalculator;
use PHPUnit\Framework\TestCase;

class LoyaltyTierCalculatorTest extends TestCase
{
    private function makeCalculator(int $silverThreshold = 100, int $goldThreshold = 500): array
    {
        $config = $this->createStub(Config::class);
        $config->method('getLoyaltySilverThreshold')->willReturn($silverThreshold);
        $config->method('getLoyaltyGoldThreshold')->willReturn($goldThreshold);

        $customerScoreManager = $this->createStub(CustomerScoreManager::class);

        return [new LoyaltyTierCalculator($config, $customerScoreManager), $customerScoreManager];
    }

    public function testGetTierForScoreBelowSilverThresholdIsBronze(): void
    {
        [$calculator] = $this->makeCalculator();
        self::assertSame(LoyaltyTierCalculator::BRONZE, $calculator->getTierForScore(0));
        self::assertSame(LoyaltyTierCalculator::BRONZE, $calculator->getTierForScore(99));
    }

    public function testGetTierForScoreAtSilverThresholdIsSilver(): void
    {
        [$calculator] = $this->makeCalculator();
        self::assertSame(LoyaltyTierCalculator::SILVER, $calculator->getTierForScore(100));
        self::assertSame(LoyaltyTierCalculator::SILVER, $calculator->getTierForScore(499));
    }

    public function testGetTierForScoreAtGoldThresholdIsGold(): void
    {
        [$calculator] = $this->makeCalculator();
        self::assertSame(LoyaltyTierCalculator::GOLD, $calculator->getTierForScore(500));
        self::assertSame(LoyaltyTierCalculator::GOLD, $calculator->getTierForScore(10000));
    }

    public function testGetMinimumScoreForTierReturnsEachConfiguredThreshold(): void
    {
        [$calculator] = $this->makeCalculator(silverThreshold: 100, goldThreshold: 500);
        self::assertSame(500, $calculator->getMinimumScoreForTier(LoyaltyTierCalculator::GOLD));
        self::assertSame(100, $calculator->getMinimumScoreForTier(LoyaltyTierCalculator::SILVER));
    }

    public function testGetMinimumScoreForTierReturnsNullForTheBronzeFloorAndUnrecognizedTiers(): void
    {
        [$calculator] = $this->makeCalculator();
        self::assertNull($calculator->getMinimumScoreForTier(LoyaltyTierCalculator::BRONZE));
        self::assertNull($calculator->getMinimumScoreForTier('platinum'));
    }

    public function testGetTierRankOrdersBronzeSilverGoldAscending(): void
    {
        [$calculator] = $this->makeCalculator();
        self::assertSame(0, $calculator->getTierRank(LoyaltyTierCalculator::BRONZE));
        self::assertSame(1, $calculator->getTierRank(LoyaltyTierCalculator::SILVER));
        self::assertSame(2, $calculator->getTierRank(LoyaltyTierCalculator::GOLD));
    }

    public function testGetTierRankReturnsMinusOneForUnrecognizedTier(): void
    {
        [$calculator] = $this->makeCalculator();
        self::assertSame(-1, $calculator->getTierRank('platinum'));
    }

    public function testIsAtLeastTrueWhenCustomerTierMeetsMinimum(): void
    {
        [$calculator] = $this->makeCalculator();
        self::assertTrue($calculator->isAtLeast(500, LoyaltyTierCalculator::GOLD));
        self::assertTrue($calculator->isAtLeast(500, LoyaltyTierCalculator::SILVER));
        self::assertTrue($calculator->isAtLeast(500, LoyaltyTierCalculator::BRONZE));
    }

    public function testIsAtLeastFalseWhenCustomerTierBelowMinimum(): void
    {
        [$calculator] = $this->makeCalculator();
        self::assertFalse($calculator->isAtLeast(50, LoyaltyTierCalculator::SILVER));
        self::assertFalse($calculator->isAtLeast(200, LoyaltyTierCalculator::GOLD));
    }

    /**
     * An unrecognized minimum tier must fail closed - never accidentally satisfied just
     * because -1 happens to compare oddly against a real rank.
     */
    public function testIsAtLeastFailsClosedForUnrecognizedMinimumTier(): void
    {
        [$calculator] = $this->makeCalculator();
        self::assertFalse($calculator->isAtLeast(999999, 'platinum'));
    }

    public function testGetAllTiersReturnsAscendingOrder(): void
    {
        [$calculator] = $this->makeCalculator();
        self::assertSame(
            [LoyaltyTierCalculator::BRONZE, LoyaltyTierCalculator::SILVER, LoyaltyTierCalculator::GOLD],
            $calculator->getAllTiers()
        );
    }

    public function testGetTierLabelReturnsHumanReadableNames(): void
    {
        [$calculator] = $this->makeCalculator();
        self::assertSame('Bronze', $calculator->getTierLabel(LoyaltyTierCalculator::BRONZE));
        self::assertSame('Silver', $calculator->getTierLabel(LoyaltyTierCalculator::SILVER));
        self::assertSame('Gold', $calculator->getTierLabel(LoyaltyTierCalculator::GOLD));
        self::assertSame('Platinum', $calculator->getTierLabel('platinum'));
    }

    public function testGetTierDistributionDerivesBronzeAndSilverFromTotalsAndGold(): void
    {
        [$calculator, $customerScoreManager] = $this->makeCalculator();

        $customerScoreManager->method('countCustomersWithScoreAtLeast')->willReturnMap([
            [500, 3],
            [100, 10],
        ]);
        $customerScoreManager->method('countAllCustomers')->willReturn(50);

        self::assertSame(
            [
                LoyaltyTierCalculator::BRONZE => 40,
                LoyaltyTierCalculator::SILVER => 7,
                LoyaltyTierCalculator::GOLD => 3,
            ],
            $calculator->getTierDistribution()
        );
    }
}
