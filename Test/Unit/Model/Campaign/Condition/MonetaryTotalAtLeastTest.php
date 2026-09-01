<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\MonetaryTotalAtLeast;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use PHPUnit\Framework\TestCase;

class MonetaryTotalAtLeastTest extends TestCase
{
    private RfmCalculator&\PHPUnit\Framework\MockObject\MockObject $rfmCalculator;
    private MonetaryTotalAtLeast $condition;

    protected function setUp(): void
    {
        $this->rfmCalculator = $this->createMock(RfmCalculator::class);
        $this->condition = new MonetaryTotalAtLeast($this->rfmCalculator);
    }

    public function testSatisfiedWhenMonetaryTotalMeetsThreshold(): void
    {
        $this->rfmCalculator->method('getMonetaryTotal')->with(42)->willReturn(500.0);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['amount' => '500']));
    }

    public function testNotSatisfiedWhenMonetaryTotalIsBelowThreshold(): void
    {
        $this->rfmCalculator->method('getMonetaryTotal')->with(42)->willReturn(499.99);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['amount' => '500']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->rfmCalculator->expects(self::never())->method('getMonetaryTotal');

        self::assertFalse($this->condition->isSatisfied([], ['amount' => '500']));
    }

    public function testNotSatisfiedWhenAmountIsMissing(): void
    {
        $this->rfmCalculator->expects(self::never())->method('getMonetaryTotal');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }
}
