<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\RecencyPercentileAtLeast;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class RecencyPercentileAtLeastTest extends TestCase
{
    private RfmCalculator&\PHPUnit\Framework\MockObject\MockObject $rfmCalculator;
    private RecencyPercentileAtLeast $condition;

    protected function setUp(): void
    {
        $this->rfmCalculator = $this->createMock(RfmCalculator::class);
        $this->condition = new RecencyPercentileAtLeast($this->rfmCalculator);
    }

    /**
     * @param array<string, float> $ranks
     * @return array<int, array<string, float>>
     */
    private function ranksFor(int $customerId, array $ranks): array
    {
        return [$customerId => $ranks];
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenRecencyPercentileMeetsThreshold(): void
    {
        $this->rfmCalculator->method('getPercentileRanks')->willReturn($this->ranksFor(42, [
            'recency_percentile' => 95.0,
            'frequency_percentile' => 10.0,
            'monetary_percentile' => 10.0,
        ]));

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['percentile' => '80']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenRecencyPercentileIsBelowThreshold(): void
    {
        $this->rfmCalculator->method('getPercentileRanks')->willReturn($this->ranksFor(42, [
            'recency_percentile' => 79.9,
            'frequency_percentile' => 99.0,
            'monetary_percentile' => 99.0,
        ]));

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['percentile' => '80']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenCustomerIsNotInTheRanking(): void
    {
        $this->rfmCalculator->method('getPercentileRanks')->willReturn([]);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['percentile' => '80']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->rfmCalculator->expects(self::never())->method('getPercentileRanks');

        self::assertFalse($this->condition->isSatisfied([], ['percentile' => '80']));
    }

    public function testNotSatisfiedWhenPercentileIsMissing(): void
    {
        $this->rfmCalculator->expects(self::never())->method('getPercentileRanks');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }

    public function testNotSatisfiedWhenPercentileIsNotNumeric(): void
    {
        $this->rfmCalculator->expects(self::never())->method('getPercentileRanks');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['percentile' => 'top']));
    }
}
