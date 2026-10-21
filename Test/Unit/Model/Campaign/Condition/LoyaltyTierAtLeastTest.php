<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\LoyaltyTierAtLeast;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\LoyaltyTierCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class LoyaltyTierAtLeastTest extends TestCase
{
    private CustomerScoreManager&\PHPUnit\Framework\MockObject\MockObject $customerScoreManager;
    private LoyaltyTierCalculator&\PHPUnit\Framework\MockObject\MockObject $loyaltyTierCalculator;
    private LoyaltyTierAtLeast $condition;

    protected function setUp(): void
    {
        $this->customerScoreManager = $this->createMock(CustomerScoreManager::class);
        $this->loyaltyTierCalculator = $this->createMock(LoyaltyTierCalculator::class);
        $this->condition = new LoyaltyTierAtLeast($this->customerScoreManager, $this->loyaltyTierCalculator);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedWhenCustomerIdIsMissing(): void
    {
        $this->customerScoreManager->expects(self::never())->method('getScore');

        self::assertFalse($this->condition->isSatisfied([], ['tier' => 'gold']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedWhenCustomerIdIsZero(): void
    {
        $this->customerScoreManager->expects(self::never())->method('getScore');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 0], ['tier' => 'gold']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedWhenTierParamIsMissing(): void
    {
        $this->customerScoreManager->expects(self::never())->method('getScore');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedWhenTierParamIsEmptyString(): void
    {
        $this->customerScoreManager->expects(self::never())->method('getScore');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['tier' => '']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedWhenTierParamIsNotAString(): void
    {
        $this->customerScoreManager->expects(self::never())->method('getScore');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['tier' => 123]));
    }

    public function testDelegatesToLoyaltyTierCalculatorWithRealScore(): void
    {
        $this->customerScoreManager->expects(self::once())->method('getScore')->with(42)->willReturn(600);
        $this->loyaltyTierCalculator->expects(self::once())->method('isAtLeast')->with(600, 'gold')
            ->willReturn(true);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['tier' => 'gold']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenCalculatorSaysNotAtLeast(): void
    {
        $this->customerScoreManager->method('getScore')->willReturn(10);
        $this->loyaltyTierCalculator->method('isAtLeast')->willReturn(false);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['tier' => 'gold']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsUsesTheTierThresholdAgainstTheScoreTable(): void
    {
        $this->loyaltyTierCalculator->method('getTierRank')->willReturnMap([['gold', 2]]);
        $this->loyaltyTierCalculator->method('getMinimumScoreForTier')->willReturnMap([['gold', 500]]);
        $this->customerScoreManager->method('getCustomerIdsWithScoreAtLeast')->willReturnMap([[500, [4, 8]]]);

        self::assertSame([4, 8], $this->condition->resolveCustomerIds(['tier' => 'gold']));
    }

    /**
     * The Bronze floor covers every registered customer, including those without a score row
     * (implicit score 0) the score-table join could never return.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsForTheBronzeFloorCoversUnscoredCustomersToo(): void
    {
        $this->loyaltyTierCalculator->method('getTierRank')->willReturnMap([['bronze', 0]]);
        $this->loyaltyTierCalculator->method('getMinimumScoreForTier')->willReturnMap([['bronze', null]]);
        $this->customerScoreManager->method('getAllCustomerIds')->willReturn([1, 2, 3]);
        $this->customerScoreManager->expects(self::never())->method('getCustomerIdsWithScoreAtLeast');

        self::assertSame([1, 2, 3], $this->condition->resolveCustomerIds(['tier' => 'bronze']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsFailsClosedOnAnUnrecognizedTier(): void
    {
        $this->loyaltyTierCalculator->method('getTierRank')->willReturn(-1);
        $this->customerScoreManager->expects(self::never())->method('getCustomerIdsWithScoreAtLeast');
        $this->customerScoreManager->expects(self::never())->method('getAllCustomerIds');

        self::assertSame([], $this->condition->resolveCustomerIds(['tier' => 'platinum']));
    }
}
