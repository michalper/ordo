<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\ClvAtLeast;
use Ordo\Automation\Model\Clv\ClvCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ClvAtLeastTest extends TestCase
{
    private ClvCalculator&\PHPUnit\Framework\MockObject\MockObject $clvCalculator;
    private ClvAtLeast $condition;

    protected function setUp(): void
    {
        $this->clvCalculator = $this->createMock(ClvCalculator::class);
        $this->condition = new ClvAtLeast($this->clvCalculator);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenProjectedClvMeetsThreshold(): void
    {
        $this->clvCalculator->method('getProjectedClv')->willReturnMap([[42, 5000.0]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['amount' => '5000']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenProjectedClvIsBelowThreshold(): void
    {
        $this->clvCalculator->method('getProjectedClv')->willReturnMap([[42, 4999.99]]);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['amount' => '5000']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->clvCalculator->expects(self::never())->method('getProjectedClv');

        self::assertFalse($this->condition->isSatisfied([], ['amount' => '5000']));
    }

    public function testNotSatisfiedWhenAmountIsMissing(): void
    {
        $this->clvCalculator->expects(self::never())->method('getProjectedClv');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }
}
