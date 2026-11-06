<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Segment;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Api\Campaign\BulkResolvableConditionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\Condition\GroupWalker;
use Ordo\Automation\Model\Condition\SetGroupCombineStrategy;
use Ordo\Automation\Model\ReorderCycle\ReorderCycleDriftCalculator;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\Collection as SegmentConditionCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Segment\Website\Collection as SegmentWebsiteCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Website\CollectionFactory as SegmentWebsiteCollectionFactory;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use Ordo\Automation\Model\SegmentCondition;
use Ordo\Automation\Model\SegmentFactory;
use Ordo\Automation\Model\SegmentWebsite;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers SegmentMemberResolver's own composition logic only - AND/OR combination, nested-group
 * recursion, in_segment/not_in_segment (the two leaf types it still resolves itself), website
 * scoping, batch-snapshot orchestration, and fail-closed handling of unknown/non-bulk-resolvable
 * pool types. Every other leaf type (score_at_least, recency_days_at_most, purchased_sku, ...)
 * now resolves entirely through its own ConditionPool-registered class - see that class's own
 * dedicated *Test.php for its resolveCustomerIds() business logic; here it's always a generic
 * stub condition (stubCondition()), standing in for "some ConditionPool-resolvable type", so
 * these tests stay about this resolver's own behavior, not duplicate coverage of a condition
 * class's internals.
 */
class SegmentMemberResolverTest extends TestCase
{
    private SegmentConditionCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $collectionFactory;
    private RfmCalculator&\PHPUnit\Framework\MockObject\MockObject $rfmCalculator;
    private SegmentFactory&\PHPUnit\Framework\MockObject\MockObject $segmentFactory;
    private SegmentResource&\PHPUnit\Framework\MockObject\MockObject $segmentResource;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private ReorderCycleDriftCalculator&\PHPUnit\Framework\MockObject\MockObject $reorderCycleDriftCalculator;
    private ConditionPool&\PHPUnit\Framework\MockObject\MockObject $conditionPool;
    private SegmentWebsiteCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $segmentWebsiteCollectionFactory;
    private ResourceConnection&\PHPUnit\Framework\MockObject\MockObject $resourceConnection;
    private SegmentMemberResolver $resolver;
    private Segment $segmentStub;

    /** @var array<string, \Ordo\Automation\Api\Campaign\ConditionInterface> */
    private array $conditionsByType = [];

    /** @var array<int, SegmentConditionCollection&\PHPUnit\Framework\MockObject\MockObject> */
    private array $collectionsBySegment = [];

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(SegmentConditionCollectionFactory::class);
        $this->rfmCalculator = $this->createMock(RfmCalculator::class);
        $this->segmentFactory = $this->createMock(SegmentFactory::class);
        $this->segmentResource = $this->createMock(SegmentResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->reorderCycleDriftCalculator = $this->createMock(ReorderCycleDriftCalculator::class);
        $this->conditionPool = $this->createMock(ConditionPool::class);
        $this->conditionPool->method('get')->willReturnCallback(
            fn (string $type) => $this->conditionsByType[$type] ?? null
        );
        $this->segmentWebsiteCollectionFactory = $this->createMock(SegmentWebsiteCollectionFactory::class);
        // No segment in any pre-existing test below is website-scoped unless a test explicitly
        // overrides this - the common case, keeps every test in this file unaffected.
        $this->segmentWebsiteCollectionFactory->method('create')->willReturn($this->makeWebsiteCollection([]));
        $this->resourceConnection = $this->createMock(ResourceConnection::class);

        // willReturnCallback (not willReturn) so stubSegmentConditionLogic() can change what's
        // returned later in a test — PHPUnit stacks multiple ->method('create') stubs FIFO, so a
        // second plain willReturn() call would never actually override this one. Every existing
        // test in this file predates condition_logic and exercises the historical AND behavior,
        // so default every segment to 'all' unless a test overrides it.
        $this->segmentFactory->method('create')->willReturnCallback(fn () => $this->segmentStub);
        $this->stubSegmentConditionLogic('all');

        $this->resolver = $this->makeResolver();
    }

    private function makeResolver(): SegmentMemberResolver
    {
        return new SegmentMemberResolver(
            $this->collectionFactory,
            $this->rfmCalculator,
            $this->segmentFactory,
            $this->segmentResource,
            $this->logger,
            new GroupWalker(),
            new SetGroupCombineStrategy(),
            $this->reorderCycleDriftCalculator,
            $this->conditionPool,
            $this->segmentWebsiteCollectionFactory,
            $this->resourceConnection
        );
    }

    /**
     * @param SegmentWebsite[] $rows
     */
    private function makeWebsiteCollection(array $rows): SegmentWebsiteCollection
    {
        $collection = $this->createStub(SegmentWebsiteCollection::class);
        $collection->method('addSegmentFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));
        return $collection;
    }

    private function makeWebsiteRow(int $websiteId): SegmentWebsite
    {
        $row = $this->createStub(SegmentWebsite::class);
        $row->method('getWebsiteId')->willReturn($websiteId);
        return $row;
    }

    private function stubSegmentConditionLogic(string $logic): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getConditionLogic')->willReturn($logic);
        $this->segmentStub = $segment;
    }

    /**
     * Registers a generic ConditionPool-resolvable stub for $type, standing in for whatever real
     * condition class would normally be registered there - these tests only exercise
     * SegmentMemberResolver's own composition/caching/AND-OR behavior, not any one condition's
     * own param-reading logic, so the stub doesn't need to validate params, just hand back a
     * fixed result.
     *
     * @param int[] $customerIds
     */
    private function stubCondition(string $type, array $customerIds): void
    {
        $this->conditionsByType[$type] = new class ($customerIds) implements
            ConditionInterface,
            BulkResolvableConditionInterface {
            public function __construct(private readonly array $customerIds)
            {
            }

            public function isSatisfied(array $context, array $params): bool
            {
                return false;
            }

            public function resolveCustomerIds(array $params): array
            {
                return $this->customerIds;
            }
        };
    }

    /**
     * @param int[] $customerIds
     */
    private function stubTagCondition(array $customerIds): void
    {
        $this->stubCondition('tag', $customerIds);
    }

    /**
     * @param array<int, array{type: string, params: array<string, mixed>}> $rows
     */
    private function stubSegment(int $segmentId, array $rows): void
    {
        $collection = $this->createMock(SegmentConditionCollection::class);
        $collection->method('addSegmentFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(count($rows));

        $modelRows = [];
        foreach ($rows as $row) {
            $conditionRow = $this->createMock(SegmentCondition::class);
            $conditionRow->method('getType')->willReturn($row['type']);
            $conditionRow->method('getParams')->willReturn($row['params']);
            $modelRows[] = $conditionRow;
        }
        $collection->method('getIterator')->willReturn(new \ArrayIterator($modelRows));

        $this->collectionsBySegment[$segmentId] = $collection;
    }

    protected function primeFactory(): void
    {
        $collectionsBySegment = &$this->collectionsBySegment;
        $callIndex = 0;
        $segmentIds = array_keys($this->collectionsBySegment);

        $this->collectionFactory->method('create')->willReturnCallback(
            function () use (&$callIndex, $segmentIds, &$collectionsBySegment) {
                $segmentId = $segmentIds[$callIndex] ?? array_key_last($collectionsBySegment);
                $callIndex++;
                return $collectionsBySegment[$segmentId];
            }
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsEmptyWhenNoConditions(): void
    {
        $this->stubSegment(1, []);
        $this->primeFactory();

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPoolResolvableConditionReturnsMatchingCustomerIds(): void
    {
        $this->stubSegment(1, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->primeFactory();

        $this->stubTagCondition([1, 2]);

        self::assertSame([1, 2], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testWebsiteScopedSegmentKeepsOnlyCustomersOnAMatchingWebsite(): void
    {
        $this->stubSegment(1, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->primeFactory();

        $this->stubTagCondition([1, 2, 3]);

        // A fresh mock, not a second ->method('create') on setUp()'s own - PHPUnit matches
        // configured behaviors in configuration order for an unlimited (any()) matcher, so a
        // second willReturn() on the same double never overrides the first.
        $this->segmentWebsiteCollectionFactory = $this->createMock(SegmentWebsiteCollectionFactory::class);
        $this->segmentWebsiteCollectionFactory->method('create')
            ->willReturn($this->makeWebsiteCollection([$this->makeWebsiteRow(5)]));
        $this->resolver = $this->makeResolver();

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        // Only customers 1 and 3 are really on website 5 - the DB join is the authority here,
        // not a PHP-side filter, so the stub result is what decides the assertion below.
        $connection->method('fetchCol')->willReturn(['1', '3']);

        $this->resourceConnection->method('getConnection')->willReturn($connection);
        $this->resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        self::assertSame([1, 3], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUnscopedSegmentIsUnaffectedByTheWebsiteFilter(): void
    {
        $this->stubSegment(1, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->primeFactory();

        $this->stubTagCondition([1, 2]);

        // No website rows configured (setUp()'s default) - the resource connection must never
        // even be touched, confirming the common/unscoped case pays no extra query.
        $this->resourceConnection->expects(self::never())->method('getConnection');

        self::assertSame([1, 2], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInSegmentRecursesIntoTargetSegment(): void
    {
        $this->stubSegment(1, [['type' => 'in_segment', 'params' => ['segment_id' => '2']]]);
        $this->stubSegment(2, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->primeFactory();

        $this->stubTagCondition([9]);

        self::assertSame([9], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInSegmentCycleDetectionFailsClosed(): void
    {
        $this->stubSegment(1, [['type' => 'in_segment', 'params' => ['segment_id' => '1']]]);
        $this->primeFactory();

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInSegmentFailsClosedOnNonNumericSegmentId(): void
    {
        $this->stubSegment(1, [['type' => 'in_segment', 'params' => ['segment_id' => 'not-a-number']]]);
        $this->primeFactory();

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotInSegmentExcludesTargetSegmentMembers(): void
    {
        $this->stubSegment(1, [['type' => 'not_in_segment', 'params' => ['segment_id' => '2']]]);
        $this->stubSegment(2, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->primeFactory();

        $this->stubTagCondition([9]);
        $this->rfmCalculator->method('getAllCustomerIds')->willReturn([5, 9, 20]);

        self::assertSame([5, 20], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotInSegmentCycleDetectionFailsClosed(): void
    {
        $this->stubSegment(1, [['type' => 'not_in_segment', 'params' => ['segment_id' => '1']]]);
        $this->primeFactory();

        $this->rfmCalculator->expects(self::never())->method('getAllCustomerIds');

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotInSegmentFailsClosedOnNonNumericSegmentId(): void
    {
        $this->stubSegment(1, [['type' => 'not_in_segment', 'params' => ['segment_id' => 'not-a-number']]]);
        $this->primeFactory();

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUnknownConditionTypeLogsAndFailsClosed(): void
    {
        $this->stubSegment(1, [['type' => 'this_type_does_not_exist', 'params' => []]]);
        $this->primeFactory();

        $this->logger->expects(self::once())->method('error');

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    /**
     * Regression test for the silent drift this delegation closed: clv_at_least,
     * loyalty_tier_at_least and nps_score_at_least were registered in the ConditionPool (so
     * per-customer SegmentMatcher evaluation worked) but hit this resolver's default arm and
     * matched nobody. A pool condition implementing BulkResolvableConditionInterface now
     * resolves through the pool without a dedicated switch arm.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testPoolRegisteredBulkResolvableConditionResolvesThroughThePool(): void
    {
        $this->stubSegment(1, [['type' => 'clv_at_least', 'params' => ['amount' => '5000']]]);
        $this->primeFactory();

        $bulkCondition = $this->createMock(BulkResolvableConditionInterface::class);
        $bulkCondition->expects(self::once())
            ->method('resolveCustomerIds')
            ->with(['amount' => '5000'])
            ->willReturn([7, 9]);

        $poolCondition = new class ($bulkCondition) implements ConditionInterface, BulkResolvableConditionInterface {
            public function __construct(private readonly BulkResolvableConditionInterface $inner)
            {
            }

            public function isSatisfied(array $context, array $params): bool
            {
                return false;
            }

            public function resolveCustomerIds(array $params): array
            {
                return $this->inner->resolveCustomerIds($params);
            }
        };

        $this->conditionsByType['clv_at_least'] = $poolCondition;
        $this->logger->expects(self::never())->method('error');

        self::assertSame([7, 9], $this->resolver->getMatchingCustomerIds(1));
    }

    /**
     * A pool-registered condition that does NOT opt into set-level resolution still fails
     * closed with a logged error — being dispatchable per customer isn't enough to claim set
     * membership silently. Also covers order_total_gte/visitor_tag's own real behavior
     * indirectly: both now implement BulkResolvableConditionInterface themselves and always
     * return [] (see OrderTotalAtLeastTest/VisitorHasTagTest) rather than relying on this
     * resolver to special-case them.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testPoolConditionWithoutBulkInterfaceStillFailsClosed(): void
    {
        $this->stubSegment(1, [['type' => 'pool_only_type', 'params' => []]]);
        $this->primeFactory();

        $this->conditionsByType['pool_only_type'] = $this->createStub(ConditionInterface::class);

        $this->logger->expects(self::once())->method('error');

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAndIntersectionAcrossMultipleConditions(): void
    {
        $this->stubSegment(1, [
            ['type' => 'tag', 'params' => ['tag' => 'vip']],
            ['type' => 'score_at_least', 'params' => ['threshold' => '50']],
        ]);
        $this->primeFactory();

        $this->stubTagCondition([1, 2, 3]);
        $this->stubCondition('score_at_least', [2, 3, 4]);

        self::assertSame([2, 3], array_values($this->resolver->getMatchingCustomerIds(1)));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testShortCircuitsOnFirstEmptyConditionWithoutResolvingTheRest(): void
    {
        $this->stubSegment(1, [
            ['type' => 'tag', 'params' => ['tag' => 'nonexistent']],
            ['type' => 'score_at_least', 'params' => ['threshold' => '50']],
        ]);
        $this->primeFactory();

        $this->stubTagCondition([]);

        $neverCalled = $this->createMock(BulkResolvableConditionInterface::class);
        $neverCalled->expects(self::never())->method('resolveCustomerIds');
        $this->conditionsByType['score_at_least'] = new class ($neverCalled) implements
            ConditionInterface,
            BulkResolvableConditionInterface {
            public function __construct(private readonly BulkResolvableConditionInterface $inner)
            {
            }

            public function isSatisfied(array $context, array $params): bool
            {
                return false;
            }

            public function resolveCustomerIds(array $params): array
            {
                return $this->inner->resolveCustomerIds($params);
            }
        };

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testShortCircuitsWhenIntersectionBecomesEmptyMidLoop(): void
    {
        // Unlike testShortCircuitsOnFirstEmptyConditionWithoutResolvingTheRest, both conditions
        // here resolve to non-empty sets individually — it's their intersection (no overlap)
        // that's empty, a distinct code path from a single condition resolving to [] outright.
        $this->stubSegment(1, [
            ['type' => 'tag', 'params' => ['tag' => 'vip']],
            ['type' => 'score_at_least', 'params' => ['threshold' => '50']],
            ['type' => 'this_type_does_not_exist', 'params' => []],
        ]);
        $this->primeFactory();

        $this->stubTagCondition([1, 2]);
        $this->stubCondition('score_at_least', [3, 4]);
        $this->logger->expects(self::never())->method('error');

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testTagConditionFailsClosedOnMissingOrEmptyTag(): void
    {
        $this->stubSegment(1, [['type' => 'tag', 'params' => []]]);
        $this->primeFactory();

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAnyLogicUnionsAcrossMultipleConditions(): void
    {
        $this->stubSegmentConditionLogic('any');
        $this->stubSegment(1, [
            ['type' => 'tag', 'params' => ['tag' => 'vip']],
            ['type' => 'score_at_least', 'params' => ['threshold' => '50']],
        ]);
        $this->primeFactory();

        $this->stubTagCondition([1, 2]);
        $this->stubCondition('score_at_least', [2, 3]);

        self::assertSame([1, 2, 3], array_values($this->resolver->getMatchingCustomerIds(1)));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAnyLogicDoesNotZeroOutOnAnUnresolvableCondition(): void
    {
        // Unlike AND, one condition resolving to nobody (or an unknown type) must not zero out
        // the whole union — it just contributes nothing to it.
        $this->stubSegmentConditionLogic('any');
        $this->stubSegment(1, [
            ['type' => 'tag', 'params' => ['tag' => 'vip']],
            ['type' => 'this_type_does_not_exist', 'params' => []],
        ]);
        $this->primeFactory();

        $this->stubTagCondition([1]);
        $this->logger->expects(self::once())->method('error');

        self::assertSame([1], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNestedGroupIsResolvedWithItsOwnLogicAndIntersectedIntoTheAndTop(): void
    {
        // Top level AND: tag=vip AND (group, OR: score_at_least OR high_score) - customer 2 has
        // the tag but only clears the group via its "high_score" OR branch; customer 3 has the
        // tag but clears neither branch of the group, so must be excluded from the final AND
        // result.
        $this->stubSegment(1, [
            ['type' => 'tag', 'params' => ['tag' => 'vip']],
            [
                'type' => 'group',
                'params' => [
                    'logic' => 'any',
                    'conditions' => [
                        ['type' => 'score_at_least', 'params' => ['threshold' => '999']],
                        ['type' => 'high_score', 'params' => ['threshold' => '1']],
                    ],
                ],
            ],
        ]);
        $this->primeFactory();

        $this->stubTagCondition([2, 3]);
        $this->stubCondition('score_at_least', []);
        $this->stubCondition('high_score', [2]);

        self::assertSame([2], array_values($this->resolver->getMatchingCustomerIds(1)));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEmptyNestedGroupResolvesToNobody(): void
    {
        $this->stubSegmentConditionLogic('any');
        $this->stubSegment(1, [
            ['type' => 'group', 'params' => ['logic' => 'all', 'conditions' => []]],
        ]);
        $this->primeFactory();

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNestedGroupWithOnlyMalformedItemsResolvesToNobody(): void
    {
        // Distinct from an entirely empty "conditions" array (testEmptyNestedGroupResolvesToNobody
        // above) - this group's list is non-empty, but every entry is malformed (a bare string,
        // and an item whose "type" isn't itself a string), so zero real specs survive filtering.
        $this->stubSegmentConditionLogic('any');
        $this->stubSegment(1, [
            [
                'type' => 'group',
                'params' => ['logic' => 'any', 'conditions' => ['not-an-array', ['type' => 42]]],
            ],
        ]);
        $this->primeFactory();

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNestedGroupItemsNonArrayParamsAreNormalizedToEmpty(): void
    {
        $this->stubSegmentConditionLogic('all');
        $this->stubSegment(1, [
            [
                'type' => 'group',
                'params' => [
                    'logic' => 'all',
                    'conditions' => [['type' => 'score_at_least', 'params' => 'not-an-array']],
                ],
            ],
        ]);
        $this->primeFactory();

        // params normalized to [] means whatever "threshold" the real condition would have read
        // is absent - asserted here as "never even called" via a condition that fails the test
        // if resolveCustomerIds() runs at all with non-empty params, same intent as before.
        $neverCalled = $this->createMock(BulkResolvableConditionInterface::class);
        $neverCalled->expects(self::once())->method('resolveCustomerIds')->with([])->willReturn([]);
        $this->conditionsByType['score_at_least'] = new class ($neverCalled) implements
            ConditionInterface,
            BulkResolvableConditionInterface {
            public function __construct(private readonly BulkResolvableConditionInterface $inner)
            {
            }

            public function isSatisfied(array $context, array $params): bool
            {
                return false;
            }

            public function resolveCustomerIds(array $params): array
            {
                return $this->inner->resolveCustomerIds($params);
            }
        };

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBeginBatchResetsBothCalculatorsSnapshotsOnce(): void
    {
        $this->rfmCalculator->expects(self::once())->method('resetSnapshotCache');
        $this->reorderCycleDriftCalculator->expects(self::once())->method('resetSnapshotCache');

        $this->resolver->beginBatch();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMultipleTopLevelResolvesWithinABatchDoNotResetAgain(): void
    {
        $this->stubSegment(1, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->stubSegment(2, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->primeFactory();
        $this->stubTagCondition([1]);

        // beginBatch() resets once and endBatch() resets once more (it always frees the
        // snapshot right away) - exactly 2 total. The real point: the two getMatchingCustomerIds()
        // calls IN BETWEEN must not reset again - that's the whole point of beginBatch(), one
        // snapshot across the batch - so this must stay at 2, not climb to 4.
        $this->rfmCalculator->expects(self::exactly(2))->method('resetSnapshotCache');

        $this->resolver->beginBatch();
        try {
            $this->resolver->getMatchingCustomerIds(1);
            $this->resolver->getMatchingCustomerIds(2);
        } finally {
            $this->resolver->endBatch();
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testTopLevelResolvesOutsideABatchEachResetTheSnapshot(): void
    {
        $this->stubSegment(1, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->stubSegment(2, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->primeFactory();
        $this->stubTagCondition([1]);

        $this->rfmCalculator->expects(self::exactly(2))->method('resetSnapshotCache');

        $this->resolver->getMatchingCustomerIds(1);
        $this->resolver->getMatchingCustomerIds(2);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEndBatchResetsAgainSoTheNextAdHocResolveDoesNotKeepTheBatchSnapshot(): void
    {
        $this->rfmCalculator->expects(self::exactly(2))->method('resetSnapshotCache');
        $this->reorderCycleDriftCalculator->expects(self::exactly(2))->method('resetSnapshotCache');

        $this->resolver->beginBatch();
        $this->resolver->endBatch();
    }
}
