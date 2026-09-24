<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\ReorderCycleAtRisk;
use Ordo\Automation\Model\ReorderCycle\ReorderCycleDriftCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ReorderCycleAtRiskTest extends TestCase
{
    private ReorderCycleDriftCalculator&\PHPUnit\Framework\MockObject\MockObject $driftCalculator;
    private ReorderCycleAtRisk $condition;

    protected function setUp(): void
    {
        $this->driftCalculator = $this->createMock(ReorderCycleDriftCalculator::class);
        $this->condition = new ReorderCycleAtRisk($this->driftCalculator);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenDriftRatioMeetsTheThreshold(): void
    {
        $this->driftCalculator->method('getDriftRatioForCustomer')->willReturnMap([[42, 0.8]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['ratio_at_least' => '0.75']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenDriftRatioEqualsTheThreshold(): void
    {
        $this->driftCalculator->method('getDriftRatioForCustomer')->willReturnMap([[42, 0.75]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['ratio_at_least' => '0.75']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenDriftRatioIsBelowTheThreshold(): void
    {
        $this->driftCalculator->method('getDriftRatioForCustomer')->willReturnMap([[42, 0.5]]);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['ratio_at_least' => '0.75']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenCustomerHasNoReorderCycle(): void
    {
        $this->driftCalculator->method('getDriftRatioForCustomer')->willReturnMap([[42, null]]);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['ratio_at_least' => '0.75']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->driftCalculator->expects(self::never())->method('getDriftRatioForCustomer');

        self::assertFalse($this->condition->isSatisfied([], ['ratio_at_least' => '0.75']));
    }

    public function testNotSatisfiedWhenRatioAtLeastIsMissing(): void
    {
        $this->driftCalculator->expects(self::never())->method('getDriftRatioForCustomer');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }
}
