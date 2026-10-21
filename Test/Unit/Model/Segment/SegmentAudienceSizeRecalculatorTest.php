<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Segment;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Collection as SegmentCollection;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Segment\SegmentAudienceSizeHistory as SegmentAudienceSizeHistoryResource;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentAudienceSizeHistory;
use Ordo\Automation\Model\Segment\SegmentAudienceSizeHistoryFactory;
use Ordo\Automation\Model\Segment\SegmentAudienceSizeRecalculator;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class SegmentAudienceSizeRecalculatorTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testRecalculateAllStoresCountAndTimestampPerSegmentAndReturnsTotal(): void
    {
        $segmentOne = $this->createMock(Segment::class);
        $segmentOne->method('getEntityId')->willReturn(1);
        $segmentOne->expects(self::exactly(2))->method('setData')->with(
            self::logicalOr('estimated_audience_size', 'audience_size_computed_at'),
            self::anything()
        );

        $segmentTwo = $this->createMock(Segment::class);
        $segmentTwo->method('getEntityId')->willReturn(2);
        $segmentTwo->expects(self::exactly(2))->method('setData')->with(
            self::logicalOr('estimated_audience_size', 'audience_size_computed_at'),
            self::anything()
        );

        $collection = $this->createStub(SegmentCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$segmentOne, $segmentTwo]));

        $collectionFactory = $this->createStub(SegmentCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $segmentResource = $this->createMock(SegmentResource::class);
        $segmentResource->expects(self::exactly(2))->method('save');

        $segmentMemberResolver = $this->createStub(SegmentMemberResolver::class);
        $segmentMemberResolver->method('getMatchingCustomerIds')->willReturnMap([
            [1, [10, 11, 12]],
            [2, [20]],
        ]);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-01-01 00:00:00');

        $historyFactory = $this->createStub(SegmentAudienceSizeHistoryFactory::class);
        $historyFactory->method('create')->willReturn($this->createStub(SegmentAudienceSizeHistory::class));
        $historyResource = $this->createMock(SegmentAudienceSizeHistoryResource::class);
        $historyResource->expects(self::exactly(2))->method('save');

        $recalculator = new SegmentAudienceSizeRecalculator(
            $collectionFactory,
            $segmentResource,
            $segmentMemberResolver,
            $dateTime,
            $historyFactory,
            $historyResource
        );

        self::assertSame(2, $recalculator->recalculateAll());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecalculateAllAppendsAHistoryRowWithTheMatchingSizeAndTimestampPerSegment(): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(7);

        $collection = $this->createStub(SegmentCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$segment]));
        $collectionFactory = $this->createStub(SegmentCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $segmentMemberResolver = $this->createStub(SegmentMemberResolver::class);
        $segmentMemberResolver->method('getMatchingCustomerIds')->willReturn([1, 2, 3]);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-01-01 00:00:00');

        $historyEntry = $this->createMock(SegmentAudienceSizeHistory::class);
        $historyEntry->expects(self::once())->method('setSegmentId')->with(7);
        $historyEntry->expects(self::once())->method('setAudienceSize')->with(3);
        $historyEntry->expects(self::once())->method('setComputedAt')->with('2026-01-01 00:00:00');
        $historyFactory = $this->createStub(SegmentAudienceSizeHistoryFactory::class);
        $historyFactory->method('create')->willReturn($historyEntry);

        $historyResource = $this->createMock(SegmentAudienceSizeHistoryResource::class);
        $historyResource->expects(self::once())->method('save')->with($historyEntry);

        $recalculator = new SegmentAudienceSizeRecalculator(
            $collectionFactory,
            $this->createStub(SegmentResource::class),
            $segmentMemberResolver,
            $dateTime,
            $historyFactory,
            $historyResource
        );

        $recalculator->recalculateAll();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecalculateAllResolvesEverySegmentInsideOneResolverBatch(): void
    {
        $segmentOne = $this->createStub(Segment::class);
        $segmentOne->method('getEntityId')->willReturn(1);
        $segmentTwo = $this->createStub(Segment::class);
        $segmentTwo->method('getEntityId')->willReturn(2);

        $collection = $this->createStub(SegmentCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$segmentOne, $segmentTwo]));
        $collectionFactory = $this->createStub(SegmentCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $sequence = [];
        $resolver = $this->createMock(SegmentMemberResolver::class);
        $resolver->expects(self::once())->method('beginBatch')->willReturnCallback(
            function () use (&$sequence): void {
                $sequence[] = 'beginBatch';
            }
        );
        $resolver->expects(self::once())->method('endBatch')->willReturnCallback(
            function () use (&$sequence): void {
                $sequence[] = 'endBatch';
            }
        );
        $resolver->method('getMatchingCustomerIds')->willReturnCallback(
            function (int $segmentId) use (&$sequence): array {
                $sequence[] = 'resolve:' . $segmentId;
                return [];
            }
        );

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-01-01 00:00:00');
        $historyFactory = $this->createStub(SegmentAudienceSizeHistoryFactory::class);
        $historyFactory->method('create')->willReturn($this->createStub(SegmentAudienceSizeHistory::class));

        $recalculator = new SegmentAudienceSizeRecalculator(
            $collectionFactory,
            $this->createStub(SegmentResource::class),
            $resolver,
            $dateTime,
            $historyFactory,
            $this->createStub(SegmentAudienceSizeHistoryResource::class)
        );

        $recalculator->recalculateAll();

        // Every resolve inside one batch: this is what keeps N RFM segments at one whole-base
        // aggregate scan per cron tick instead of N.
        self::assertSame(['beginBatch', 'resolve:1', 'resolve:2', 'endBatch'], $sequence);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecalculateAllEndsTheBatchEvenWhenAResolveThrows(): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(1);

        $collection = $this->createStub(SegmentCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$segment]));
        $collectionFactory = $this->createStub(SegmentCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $resolver = $this->createMock(SegmentMemberResolver::class);
        $resolver->expects(self::once())->method('beginBatch');
        // Without this, a thrown resolve would leave the resolver stuck in batch mode for the
        // rest of the process's life — serving a stale snapshot to every later resolve.
        $resolver->expects(self::once())->method('endBatch');
        $resolver->method('getMatchingCustomerIds')->willThrowException(new \RuntimeException('boom'));

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-01-01 00:00:00');
        $historyFactory = $this->createStub(SegmentAudienceSizeHistoryFactory::class);
        $historyFactory->method('create')->willReturn($this->createStub(SegmentAudienceSizeHistory::class));

        $recalculator = new SegmentAudienceSizeRecalculator(
            $collectionFactory,
            $this->createStub(SegmentResource::class),
            $resolver,
            $dateTime,
            $historyFactory,
            $this->createStub(SegmentAudienceSizeHistoryResource::class)
        );

        $this->expectException(\RuntimeException::class);
        $recalculator->recalculateAll();
    }
}
