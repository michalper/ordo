<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Segment;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\Condition\ConditionGroupEvaluator;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\Collection as SegmentConditionCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentMatcher;
use Ordo\Automation\Model\SegmentCondition;
use Ordo\Automation\Model\SegmentFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SegmentMatcherTest extends TestCase
{
    private SegmentConditionCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $collectionFactory;
    private SegmentConditionCollection&\PHPUnit\Framework\MockObject\MockObject $collection;
    private ConditionPool&\PHPUnit\Framework\MockObject\MockObject $conditionPool;
    private SegmentFactory&\PHPUnit\Framework\MockObject\MockObject $segmentFactory;
    private SegmentResource&\PHPUnit\Framework\MockObject\MockObject $segmentResource;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private SegmentMatcher $matcher;
    private Segment $segmentStub;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(SegmentConditionCollectionFactory::class);
        $this->collection = $this->createMock(SegmentConditionCollection::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);
        $this->collection->method('addSegmentFilter')->willReturnSelf();

        $this->conditionPool = $this->createMock(ConditionPool::class);
        $this->segmentFactory = $this->createMock(SegmentFactory::class);
        $this->segmentResource = $this->createMock(SegmentResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // willReturnCallback (not willReturn) so stubSegmentConditionLogic() can change what's
        // returned later in a test — PHPUnit stacks multiple ->method('create') stubs FIFO, so a
        // second plain willReturn() call would never actually override this one.
        $this->segmentFactory->method('create')->willReturnCallback(fn () => $this->segmentStub);
        $this->stubSegmentConditionLogic('all');

        $this->matcher = new SegmentMatcher(
            $this->collectionFactory,
            new ConditionGroupEvaluator($this->conditionPool, $this->logger),
            $this->segmentFactory,
            $this->segmentResource
        );
    }

    private function stubSegmentConditionLogic(string $logic): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getConditionLogic')->willReturn($logic);
        $this->segmentStub = $segment;
    }

    private function makeConditionRow(string $type, array $params): SegmentCondition
    {
        $row = $this->createMock(SegmentCondition::class);
        $row->method('getData')->willReturnMap([['type', $type]]);
        $row->method('getParams')->willReturn($params);

        return $row;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotInSegmentWhenSegmentHasNoConditions(): void
    {
        $this->collection->method('getSize')->willReturn(0);
        $this->collection->expects(self::never())->method('getIterator');

        self::assertFalse($this->matcher->isCustomerInSegment(3, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInSegmentWhenAllConditionsAreSatisfied(): void
    {
        $row = $this->makeConditionRow('tag', ['tag' => 'vip']);
        $this->collection->method('getSize')->willReturn(1);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$row]));

        $condition = $this->createMock(ConditionInterface::class);
        $condition->method('isSatisfied')
            ->willReturnMap([
                [['customer_id' => 42, '_in_segment_visited' => [3]], ['tag' => 'vip'], true],
            ]);
        $this->conditionPool->method('get')->willReturnMap([['tag', $condition]]);

        self::assertTrue($this->matcher->isCustomerInSegment(3, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotInSegmentWhenAnyConditionFails(): void
    {
        $row = $this->makeConditionRow('tag', ['tag' => 'vip']);
        $this->collection->method('getSize')->willReturn(1);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$row]));

        $condition = $this->createMock(ConditionInterface::class);
        $condition->method('isSatisfied')->willReturn(false);
        $this->conditionPool->method('get')->willReturnMap([['tag', $condition]]);

        self::assertFalse($this->matcher->isCustomerInSegment(3, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedOnUnknownConditionType(): void
    {
        $row = $this->makeConditionRow('this_type_does_not_exist', []);
        $this->collection->method('getSize')->willReturn(1);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$row]));

        $this->conditionPool->method('get')->willReturn(null);
        $this->logger->expects(self::once())->method('error');

        self::assertFalse($this->matcher->isCustomerInSegment(3, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFailsClosedWhenSegmentIsAlreadyBeingVisited(): void
    {
        // Simulates the recursive call InSegment::isSatisfied makes when a segment's own
        // in_segment condition points back at a segment already in the call chain (segment 3
        // referencing itself, directly or via a longer cycle) — must short-circuit before even
        // querying its conditions, not recurse until the stack overflows.
        $this->collection->expects(self::never())->method('getSize');
        $this->collection->expects(self::never())->method('getIterator');

        self::assertFalse($this->matcher->isCustomerInSegment(3, 42, [3]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAnyLogicMatchesWhenOnlyOneConditionIsSatisfied(): void
    {
        $this->stubSegmentConditionLogic('any');

        $satisfiedRow = $this->makeConditionRow('tag', ['tag' => 'vip']);
        $unsatisfiedRow = $this->makeConditionRow('score_at_least', ['threshold' => 100]);
        $this->collection->method('getSize')->willReturn(2);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$unsatisfiedRow, $satisfiedRow]));

        $failing = $this->createStub(ConditionInterface::class);
        $failing->method('isSatisfied')->willReturn(false);
        $passing = $this->createStub(ConditionInterface::class);
        $passing->method('isSatisfied')->willReturn(true);
        $this->conditionPool->method('get')->willReturnMap([
            ['score_at_least', $failing],
            ['tag', $passing],
        ]);

        self::assertTrue($this->matcher->isCustomerInSegment(3, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAnyLogicFailsWhenNoConditionIsSatisfied(): void
    {
        $this->stubSegmentConditionLogic('any');

        $row = $this->makeConditionRow('tag', ['tag' => 'vip']);
        $this->collection->method('getSize')->willReturn(1);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$row]));

        $condition = $this->createStub(ConditionInterface::class);
        $condition->method('isSatisfied')->willReturn(false);
        $this->conditionPool->method('get')->willReturnMap([['tag', $condition]]);

        self::assertFalse($this->matcher->isCustomerInSegment(3, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNestedGroupIsEvaluatedWithItsOwnLogic(): void
    {
        // Top level is AND: tag=vip AND (group, OR: score_at_least=999 OR score_at_least=1) -
        // the group only passes because of its OWN internal OR, not the top-level AND.
        $this->stubSegmentConditionLogic('all');

        $tagRow = $this->makeConditionRow('tag', ['tag' => 'vip']);
        $groupRow = $this->makeConditionRow('group', [
            'logic' => 'any',
            'conditions' => [
                ['type' => 'score_at_least', 'params' => ['threshold' => 999]],
                ['type' => 'score_at_least', 'params' => ['threshold' => 1]],
            ],
        ]);
        $this->collection->method('getSize')->willReturn(2);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$tagRow, $groupRow]));

        $tagCondition = $this->createStub(ConditionInterface::class);
        $tagCondition->method('isSatisfied')->willReturn(true);

        $scoreCondition = $this->createMock(ConditionInterface::class);
        $scoreCondition->method('isSatisfied')->willReturnMap([
            [['customer_id' => 42, '_in_segment_visited' => [3]], ['threshold' => 999], false],
            [['customer_id' => 42, '_in_segment_visited' => [3]], ['threshold' => 1], true],
        ]);

        $this->conditionPool->method('get')->willReturnMap([
            ['tag', $tagCondition],
            ['score_at_least', $scoreCondition],
        ]);

        self::assertTrue($this->matcher->isCustomerInSegment(3, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNestedGroupFailingFailsTheWholeAndEvenIfOtherConditionsPass(): void
    {
        $this->stubSegmentConditionLogic('all');

        $tagRow = $this->makeConditionRow('tag', ['tag' => 'vip']);
        $groupRow = $this->makeConditionRow('group', [
            'logic' => 'any',
            'conditions' => [
                ['type' => 'score_at_least', 'params' => ['threshold' => 999]],
            ],
        ]);
        $this->collection->method('getSize')->willReturn(2);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$tagRow, $groupRow]));

        $tagCondition = $this->createStub(ConditionInterface::class);
        $tagCondition->method('isSatisfied')->willReturn(true);
        $scoreCondition = $this->createStub(ConditionInterface::class);
        $scoreCondition->method('isSatisfied')->willReturn(false);

        $this->conditionPool->method('get')->willReturnMap([
            ['tag', $tagCondition],
            ['score_at_least', $scoreCondition],
        ]);

        self::assertFalse($this->matcher->isCustomerInSegment(3, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEmptyNestedGroupFailsClosed(): void
    {
        $this->stubSegmentConditionLogic('any');

        $groupRow = $this->makeConditionRow('group', ['logic' => 'all', 'conditions' => []]);
        $this->collection->method('getSize')->willReturn(1);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$groupRow]));

        self::assertFalse($this->matcher->isCustomerInSegment(3, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNestedGroupWithOnlyMalformedItemsFailsClosed(): void
    {
        // Distinct from an entirely empty "conditions" array (testEmptyNestedGroupFailsClosed
        // above) - this group's list is non-empty, but every entry is malformed (a bare string,
        // and an item whose "type" isn't itself a string), so zero real specs survive filtering.
        $this->stubSegmentConditionLogic('any');

        $groupRow = $this->makeConditionRow('group', [
            'logic' => 'any',
            'conditions' => ['not-an-array', ['type' => 42]],
        ]);
        $this->collection->method('getSize')->willReturn(1);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$groupRow]));

        self::assertFalse($this->matcher->isCustomerInSegment(3, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNestedGroupItemsNonArrayParamsAreNormalizedToEmpty(): void
    {
        $this->stubSegmentConditionLogic('all');

        $groupRow = $this->makeConditionRow('group', [
            'logic' => 'all',
            'conditions' => [['type' => 'score_at_least', 'params' => 'not-an-array']],
        ]);
        $this->collection->method('getSize')->willReturn(1);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$groupRow]));

        $scoreCondition = $this->createMock(ConditionInterface::class);
        $scoreCondition->expects(self::once())->method('isSatisfied')
            ->with(['customer_id' => 42, '_in_segment_visited' => [3]], [])
            ->willReturn(true);

        $this->conditionPool->method('get')->willReturnMap([['score_at_least', $scoreCondition]]);

        self::assertTrue($this->matcher->isCustomerInSegment(3, 42));
    }
}
