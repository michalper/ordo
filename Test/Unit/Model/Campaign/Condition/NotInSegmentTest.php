<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\NotInSegment;
use Ordo\Automation\Model\Segment\SegmentMatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class NotInSegmentTest extends TestCase
{
    private SegmentMatcher&\PHPUnit\Framework\MockObject\MockObject $segmentMatcher;
    private NotInSegment $condition;

    protected function setUp(): void
    {
        $this->segmentMatcher = $this->createMock(SegmentMatcher::class);
        $this->condition = new NotInSegment($this->segmentMatcher);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenCustomerIsNotInTheSegment(): void
    {
        $this->segmentMatcher->method('isCustomerInSegment')->willReturnMap([[3, 42, [], false]]);

        self::assertTrue($this->condition->isSatisfied(['customer_id' => 42], ['segment_id' => '3']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenCustomerIsInTheSegment(): void
    {
        $this->segmentMatcher->method('isCustomerInSegment')->willReturnMap([[3, 42, [], true]]);

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

    /**
     * Regression test: a cycle (this segment, directly or via another already-visited segment,
     * excludes itself) must fail this condition closed (not satisfied) rather than blindly
     * negating SegmentMatcher::isCustomerInSegment()'s own fail-closed false into "satisfied for
     * everyone" - see this class's own docblock for the reasoning.
     */
    public function testNotSatisfiedWhenSegmentIsAlreadyBeingVisitedInThisChain(): void
    {
        $this->segmentMatcher->expects(self::never())->method('isCustomerInSegment');

        self::assertFalse($this->condition->isSatisfied(
            ['customer_id' => 42, '_in_segment_visited' => [3]],
            ['segment_id' => '3']
        ));
    }
}
