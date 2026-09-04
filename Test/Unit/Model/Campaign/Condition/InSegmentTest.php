<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\InSegment;
use Ordo\Automation\Model\Segment\SegmentMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class InSegmentTest extends TestCase
{
    private SegmentMatcher&\PHPUnit\Framework\MockObject\MockObject $segmentMatcher;
    private InSegment $condition;

    protected function setUp(): void
    {
        $this->segmentMatcher = $this->createMock(SegmentMatcher::class);
        $this->condition = new InSegment($this->segmentMatcher);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenCustomerIsInTheSegment(): void
    {
        $this->segmentMatcher->method('isCustomerInSegment')->willReturnMap([[3, 42, true]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['segment_id' => '3']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenCustomerIsNotInTheSegment(): void
    {
        $this->segmentMatcher->method('isCustomerInSegment')->willReturnMap([[3, 42, false]]);

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['segment_id' => '3']));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->segmentMatcher->expects(self::never())->method('isCustomerInSegment');

        self::assertFalse($this->condition->isSatisfied([], ['segment_id' => '3']));
    }

    public function testNotSatisfiedWhenSegmentIdIsMissing(): void
    {
        $this->segmentMatcher->expects(self::never())->method('isCustomerInSegment');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], []));
    }
}
