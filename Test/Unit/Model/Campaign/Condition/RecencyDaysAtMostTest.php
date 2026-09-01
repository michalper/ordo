<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\RecencyDaysAtMost;
use Ordo\Automation\Model\Rfm\RfmCalculator;
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

    public function testSatisfiedWhenRecencyIsWithinTheLimit(): void
    {
        $this->rfmCalculator->method('getRecencyDays')->with(42)->willReturn(10);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['days' => '30']));
    }

    public function testSatisfiedWhenRecencyEqualsTheLimit(): void
    {
        $this->rfmCalculator->method('getRecencyDays')->with(42)->willReturn(30);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['days' => '30']));
    }

    public function testNotSatisfiedWhenRecencyExceedsTheLimit(): void
    {
        $this->rfmCalculator->method('getRecencyDays')->with(42)->willReturn(31);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['days' => '30']));
    }

    public function testNotSatisfiedWhenCustomerHasNoOrders(): void
    {
        $this->rfmCalculator->method('getRecencyDays')->with(42)->willReturn(null);

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
