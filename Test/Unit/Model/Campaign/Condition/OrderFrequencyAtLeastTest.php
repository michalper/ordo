<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\OrderFrequencyAtLeast;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class OrderFrequencyAtLeastTest extends TestCase
{
    private RfmCalculator&\PHPUnit\Framework\MockObject\MockObject $rfmCalculator;
    private OrderFrequencyAtLeast $condition;

    protected function setUp(): void
    {
        $this->rfmCalculator = $this->createMock(RfmCalculator::class);
        $this->condition = new OrderFrequencyAtLeast($this->rfmCalculator);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenFrequencyMeetsThreshold(): void
    {
        $this->rfmCalculator->method('getFrequency')->willReturnMap([[42, 3]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['count' => '3']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenFrequencyIsBelowThreshold(): void
    {
        $this->rfmCalculator->method('getFrequency')->willReturnMap([[42, 2]]);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['count' => '3']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->rfmCalculator->expects(self::never())->method('getFrequency');

        self::assertFalse($this->condition->isSatisfied([], ['count' => '3']));
    }

    public function testNotSatisfiedWhenCountIsMissing(): void
    {
        $this->rfmCalculator->expects(self::never())->method('getFrequency');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }
}
