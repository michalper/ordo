<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\ScoreAtLeast;
use Ordo\Automation\Model\CustomerScoreManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ScoreAtLeastTest extends TestCase
{
    private CustomerScoreManager&\PHPUnit\Framework\MockObject\MockObject $customerScoreManager;
    private ScoreAtLeast $condition;

    protected function setUp(): void
    {
        $this->customerScoreManager = $this->createMock(CustomerScoreManager::class);
        $this->condition = new ScoreAtLeast($this->customerScoreManager);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenScoreMeetsThreshold(): void
    {
        $this->customerScoreManager->method('getScore')->willReturnMap([[42, 50]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['threshold' => '50']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenScoreExceedsThreshold(): void
    {
        $this->customerScoreManager->method('getScore')->willReturnMap([[42, 51]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['threshold' => '50']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenScoreIsBelowThreshold(): void
    {
        $this->customerScoreManager->method('getScore')->willReturnMap([[42, 49]]);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['threshold' => '50']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->customerScoreManager->expects(self::never())->method('getScore');

        self::assertFalse($this->condition->isSatisfied([], ['threshold' => '50']));
    }

    public function testNotSatisfiedWhenThresholdIsMissing(): void
    {
        $this->customerScoreManager->expects(self::never())->method('getScore');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }

    public function testNotSatisfiedWhenThresholdIsNonNumeric(): void
    {
        $this->customerScoreManager->expects(self::never())->method('getScore');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['threshold' => 'lots']));
    }
}
