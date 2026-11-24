<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Rfm\Grid\Column;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Ordo\Automation\Block\Adminhtml\Rfm\Grid\Column\MatchingSegments;
use Ordo\Automation\Model\ResourceModel\Segment\Collection as SegmentCollection;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class MatchingSegmentsTest extends TestCase
{
    private SegmentCollectionFactory $segmentCollectionFactory;
    private SegmentMemberResolver $segmentMemberResolver;
    private SegmentCollection $segmentCollection;
    private CacheInterface $cache;

    protected function setUp(): void
    {
        $this->segmentCollection = $this->createStub(SegmentCollection::class);
        $this->segmentCollection->method('addFieldToFilter')->willReturnSelf();

        $this->segmentCollectionFactory = $this->createStub(SegmentCollectionFactory::class);
        $this->segmentCollectionFactory->method('create')->willReturn($this->segmentCollection);

        $this->segmentMemberResolver = $this->createStub(SegmentMemberResolver::class);

        // A permanent cache miss by default, so every existing test below still exercises the
        // real resolver path unchanged - the dedicated cache tests further down configure their
        // own hit/save behavior instead.
        $this->cache = $this->createStub(CacheInterface::class);
        $this->cache->method('load')->willReturn(false);
    }

    private function makeColumn(array $data = []): MatchingSegments
    {
        return new MatchingSegments(
            $this->createStub(ContextInterface::class),
            $this->createStub(UiComponentFactory::class),
            $this->segmentCollectionFactory,
            $this->segmentMemberResolver,
            $this->cache,
            new JsonSerializer(),
            [],
            $data
        );
    }

    private function stubSegment(int $entityId, string $name): Segment
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn($entityId);
        $segment->method('getName')->willReturn($name);

        return $segment;
    }

    public function testPrepareDataSourceReturnsUnchangedWhenThereAreNoItems(): void
    {
        $column = $this->makeColumn();

        $result = $column->prepareDataSource(['data' => ['items' => []]]);

        self::assertSame(['data' => ['items' => []]], $result);
    }

    public function testPrepareDataSourceReturnsUnchangedWhenDataIsMissing(): void
    {
        $column = $this->makeColumn();

        $result = $column->prepareDataSource([]);

        self::assertSame([], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceFillsInMatchingSegmentNamesPerCustomer(): void
    {
        $vip = $this->stubSegment(1, 'VIP Customers');
        $bigSpender = $this->stubSegment(2, 'Big Spenders');

        $this->segmentCollection->method('getIterator')->willReturn(new \ArrayIterator([$vip, $bigSpender]));

        $this->segmentMemberResolver->method('getMatchingCustomerIds')->willReturnMap([
            [1, [10, 11]],
            [2, [11]],
        ]);

        $column = $this->makeColumn();

        $result = $column->prepareDataSource([
            'data' => [
                'items' => [
                    ['entity_id' => 10],
                    ['entity_id' => 11],
                    ['entity_id' => 99],
                ],
            ],
        ]);

        $items = $result['data']['items'];
        self::assertSame('VIP Customers', $items[0]['matching_segments']);
        self::assertSame('VIP Customers, Big Spenders', $items[1]['matching_segments']);
        self::assertSame('', $items[2]['matching_segments']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceUsesTheConfiguredFieldName(): void
    {
        $this->segmentCollection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $column = $this->makeColumn(['name' => 'my_custom_field']);

        $result = $column->prepareDataSource([
            'data' => ['items' => [['entity_id' => 10]]],
        ]);

        self::assertArrayHasKey('my_custom_field', $result['data']['items'][0]);
    }

    public function testPrepareDataSourceSkipsSegmentsWithNoEntityId(): void
    {
        $unsavedSegment = $this->createStub(Segment::class);
        $unsavedSegment->method('getEntityId')->willReturn(null);

        $this->segmentCollection->method('getIterator')->willReturn(new \ArrayIterator([$unsavedSegment]));

        $resolver = $this->createMock(SegmentMemberResolver::class);
        $resolver->expects(self::never())->method('getMatchingCustomerIds');
        $this->segmentMemberResolver = $resolver;

        $column = $this->makeColumn();

        $result = $column->prepareDataSource([
            'data' => ['items' => [['entity_id' => 10]]],
        ]);

        self::assertSame('', $result['data']['items'][0]['matching_segments']);
    }

    public function testPrepareDataSourceResolvesAllSegmentsInsideOneResolverBatch(): void
    {
        $this->segmentCollection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->stubSegment(1, 'VIP Customers'),
            $this->stubSegment(2, 'Big Spenders'),
        ]));

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
        $this->segmentMemberResolver = $resolver;

        $column = $this->makeColumn();
        $column->prepareDataSource(['data' => ['items' => [['entity_id' => 10]]]]);

        // One RFM snapshot per page load — every per-segment resolve between begin and end.
        self::assertSame(['beginBatch', 'resolve:1', 'resolve:2', 'endBatch'], $sequence);
    }

    /**
     * Regression for a full-repo /simplify finding: buildSegmentNamesByCustomerId() used to
     * re-resolve every enabled segment against the entire customer base on every grid
     * page/sort/filter action. A cache hit must skip the resolver entirely.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceUsesTheCachedResultWithoutResolvingAgain(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->expects(self::once())->method('load')->with('ordo_rfm_matching_segments')
            ->willReturn(json_encode(['10' => ['Cached Segment']]));
        $this->cache->expects(self::never())->method('save');

        $resolver = $this->createMock(SegmentMemberResolver::class);
        $resolver->expects(self::never())->method('beginBatch');
        $resolver->expects(self::never())->method('getMatchingCustomerIds');
        $this->segmentMemberResolver = $resolver;

        $segmentCollectionFactory = $this->createMock(SegmentCollectionFactory::class);
        $segmentCollectionFactory->expects(self::never())->method('create');
        $this->segmentCollectionFactory = $segmentCollectionFactory;

        $column = $this->makeColumn();

        $result = $column->prepareDataSource([
            'data' => ['items' => [['entity_id' => 10]]],
        ]);

        self::assertSame('Cached Segment', $result['data']['items'][0]['matching_segments']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPrepareDataSourceSavesTheResolvedResultToTheCacheOnAMiss(): void
    {
        $this->segmentCollection->method('getIterator')->willReturn(
            new \ArrayIterator([$this->stubSegment(1, 'VIP Customers')])
        );
        $this->segmentMemberResolver->method('getMatchingCustomerIds')->willReturn([10]);

        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturn(false);
        $this->cache->expects(self::once())->method('save')
            ->with(json_encode([10 => ['VIP Customers']]), 'ordo_rfm_matching_segments', [], 60);

        $column = $this->makeColumn();
        $column->prepareDataSource(['data' => ['items' => [['entity_id' => 10]]]]);
    }

    public function testPrepareDataSourceSkipsNonArrayItemsWithoutError(): void
    {
        $this->segmentCollection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $column = $this->makeColumn();

        $result = $column->prepareDataSource([
            'data' => ['items' => ['not-an-array']],
        ]);

        self::assertSame(['not-an-array'], $result['data']['items']);
    }
}
