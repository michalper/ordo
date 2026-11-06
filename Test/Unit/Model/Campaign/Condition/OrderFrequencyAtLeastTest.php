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

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsFiltersInclusively(): void
    {
        $this->rfmCalculator->method('getAggregatesForAllCustomers')->willReturn([
            1 => ['frequency' => 3, 'monetary' => 0.0, 'recency_days' => null],
            2 => ['frequency' => 2, 'monetary' => 0.0, 'recency_days' => null],
        ]);

        self::assertSame([1], $this->condition->resolveCustomerIds(['count' => '3']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveCustomerIdsZeroMatchesCustomersWithNoOrdersToo(): void
    {
        // Customer 2 has never ordered, so it's absent from the aggregate map entirely — a
        // threshold of 0 must still match them (getFrequency() would return 0 for them on the
        // single-customer path, and 0 >= 0), so this must fall back to "everyone", not just
        // customers who happen to appear in the aggregate query's results.
        $this->rfmCalculator->method('getAggregatesForAllCustomers')->willReturn([
            1 => ['frequency' => 3, 'monetary' => 0.0, 'recency_days' => null],
        ]);
        $this->rfmCalculator->method('getAllCustomerIds')->willReturn([1, 2]);

        self::assertSame([1, 2], $this->condition->resolveCustomerIds(['count' => '0']));
    }

    public function testResolveCustomerIdsFailsClosedOnNonNumericCount(): void
    {
        $this->rfmCalculator->expects(self::never())->method('getAggregatesForAllCustomers');

        self::assertSame([], $this->condition->resolveCustomerIds(['count' => 'not-a-number']));
    }
}
