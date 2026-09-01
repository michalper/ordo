<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Segment;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\Collection as SegmentConditionCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Model\Segment\SegmentMatcher;
use Ordo\Automation\Model\SegmentCondition;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class SegmentMatcherTest extends TestCase
{
    private SegmentConditionCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $collectionFactory;
    private SegmentConditionCollection&\PHPUnit\Framework\MockObject\MockObject $collection;
    private ConditionPool&\PHPUnit\Framework\MockObject\MockObject $conditionPool;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private SegmentMatcher $matcher;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(SegmentConditionCollectionFactory::class);
        $this->collection = $this->createMock(SegmentConditionCollection::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);
        $this->collection->method('addSegmentFilter')->willReturnSelf();

        $this->conditionPool = $this->createMock(ConditionPool::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->matcher = new SegmentMatcher($this->collectionFactory, $this->conditionPool, $this->logger);
    }

    private function makeConditionRow(string $type, array $params): SegmentCondition
    {
        $row = $this->createMock(SegmentCondition::class);
        $row->method('getData')->with('type')->willReturn($type);
        $row->method('getParams')->willReturn($params);

        return $row;
    }

    public function testNotInSegmentWhenSegmentHasNoConditions(): void
    {
        $this->collection->method('getSize')->willReturn(0);
        $this->collection->expects(self::never())->method('getIterator');

        self::assertFalse($this->matcher->isCustomerInSegment(3, 42));
    }

    public function testInSegmentWhenAllConditionsAreSatisfied(): void
    {
        $row = $this->makeConditionRow('tag', ['tag' => 'vip']);
        $this->collection->method('getSize')->willReturn(1);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$row]));

        $condition = $this->createMock(ConditionInterface::class);
        $condition->method('isSatisfied')->with(['customer_id' => 42], ['tag' => 'vip'])->willReturn(true);
        $this->conditionPool->method('get')->with('tag')->willReturn($condition);

        self::assertTrue($this->matcher->isCustomerInSegment(3, 42));
    }

    public function testNotInSegmentWhenAnyConditionFails(): void
    {
        $row = $this->makeConditionRow('tag', ['tag' => 'vip']);
        $this->collection->method('getSize')->willReturn(1);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$row]));

        $condition = $this->createMock(ConditionInterface::class);
        $condition->method('isSatisfied')->willReturn(false);
        $this->conditionPool->method('get')->with('tag')->willReturn($condition);

        self::assertFalse($this->matcher->isCustomerInSegment(3, 42));
    }

    public function testFailsClosedOnUnknownConditionType(): void
    {
        $row = $this->makeConditionRow('this_type_does_not_exist', []);
        $this->collection->method('getSize')->willReturn(1);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([$row]));

        $this->conditionPool->method('get')->willReturn(null);
        $this->logger->expects(self::once())->method('error');

        self::assertFalse($this->matcher->isCustomerInSegment(3, 42));
    }
}
