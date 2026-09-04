<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\RecencyDaysAtMost;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class RecencyDaysAtMostTest extends TestCase
{
    private RfmCalculator&\PHPUnit\Framework\MockObject\MockObject $rfmCalculator;
    private RecencyDaysAtMost $condition;

    protected function setUp(): void
    {
        $this->rfmCalculator = $this->createMock(RfmCalculator::class);
        $this->condition = new RecencyDaysAtMost($this->rfmCalculator);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenRecencyIsWithinTheLimit(): void
    {
        $this->rfmCalculator->method('getRecencyDays')->willReturnMap([[42, 10]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['days' => '30']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenRecencyEqualsTheLimit(): void
    {
        $this->rfmCalculator->method('getRecencyDays')->willReturnMap([[42, 30]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['days' => '30']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenRecencyExceedsTheLimit(): void
    {
        $this->rfmCalculator->method('getRecencyDays')->willReturnMap([[42, 31]]);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['days' => '30']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenCustomerHasNoOrders(): void
    {
        $this->rfmCalculator->method('getRecencyDays')->willReturnMap([[42, null]]);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['days' => '30']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->rfmCalculator->expects(self::never())->method('getRecencyDays');

        self::assertFalse($this->condition->isSatisfied([], ['days' => '30']));
    }

    public function testNotSatisfiedWhenDaysIsMissing(): void
    {
        $this->rfmCalculator->expects(self::never())->method('getRecencyDays');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }
}
