<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Segment;

use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\CustomerTagManager;
use Ordo\Automation\Model\Purchase\PurchasedProductResolver;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\Collection as SegmentConditionCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use Ordo\Automation\Model\SegmentCondition;
use Ordo\Automation\Model\SegmentFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SegmentMemberResolverTest extends TestCase
{
    private SegmentConditionCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $collectionFactory;
    private CustomerTagManager&\PHPUnit\Framework\MockObject\MockObject $customerTagManager;
    private CustomerScoreManager&\PHPUnit\Framework\MockObject\MockObject $customerScoreManager;
    private RfmCalculator&\PHPUnit\Framework\MockObject\MockObject $rfmCalculator;
    private SegmentFactory&\PHPUnit\Framework\MockObject\MockObject $segmentFactory;
    private SegmentResource&\PHPUnit\Framework\MockObject\MockObject $segmentResource;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private PurchasedProductResolver&\PHPUnit\Framework\MockObject\MockObject $purchasedProductResolver;
    private SegmentMemberResolver $resolver;
    private Segment $segmentStub;

    /** @var array<int, SegmentConditionCollection&\PHPUnit\Framework\MockObject\MockObject> */
    private array $collectionsBySegment = [];

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(SegmentConditionCollectionFactory::class);
        $this->customerTagManager = $this->createMock(CustomerTagManager::class);
        $this->customerScoreManager = $this->createMock(CustomerScoreManager::class);
        $this->rfmCalculator = $this->createMock(RfmCalculator::class);
        $this->segmentFactory = $this->createMock(SegmentFactory::class);
        $this->segmentResource = $this->createMock(SegmentResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->purchasedProductResolver = $this->createMock(PurchasedProductResolver::class);

        // willReturnCallback (not willReturn) so stubSegmentConditionLogic() can change what's
        // returned later in a test — PHPUnit stacks multiple ->method('create') stubs FIFO, so a
        // second plain willReturn() call would never actually override this one. Every existing
        // test in this file predates condition_logic and exercises the historical AND behavior,
        // so default every segment to 'all' unless a test overrides it.
        $this->segmentFactory->method('create')->willReturnCallback(fn () => $this->segmentStub);
        $this->stubSegmentConditionLogic('all');

        $this->resolver = new SegmentMemberResolver(
            $this->collectionFactory,
            $this->customerTagManager,
            $this->customerScoreManager,
            $this->rfmCalculator,
            $this->segmentFactory,
            $this->segmentResource,
            $this->logger,
            $this->purchasedProductResolver
        );
    }

    private function stubSegmentConditionLogic(string $logic): void
    {
        $segment = $this->createStub(Segment::class);
        $segment->method('getConditionLogic')->willReturn($logic);
        $this->segmentStub = $segment;
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
    public function testTagConditionReturnsMatchingCustomerIds(): void
    {
        $this->stubSegment(1, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->primeFactory();

        $this->customerTagManager->method('getCustomerIdsWithTag')->willReturnMap([['vip', [1, 2]]]);

        self::assertSame([1, 2], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testScoreAtLeastConditionReturnsMatchingCustomerIds(): void
    {
        $this->stubSegment(1, [['type' => 'score_at_least', 'params' => ['threshold' => '50']]]);
        $this->primeFactory();

        $this->customerScoreManager->method('getCustomerIdsWithScoreAtLeast')->willReturnMap([[50, [3]]]);

        self::assertSame([3], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecencyDaysAtMostFiltersByExactSameBoundaryAsRfmCalculator(): void
    {
        $this->stubSegment(1, [['type' => 'recency_days_at_most', 'params' => ['days' => '10']]]);
        $this->primeFactory();

        $this->rfmCalculator->method('getAggregatesForAllCustomers')->willReturn([
            1 => ['frequency' => 1, 'monetary' => 1.0, 'recency_days' => 10],
            2 => ['frequency' => 1, 'monetary' => 1.0, 'recency_days' => 11],
            3 => ['frequency' => 0, 'monetary' => 0.0, 'recency_days' => null],
        ]);

        self::assertSame([1], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOrderFrequencyAtLeastFiltersInclusively(): void
    {
        $this->stubSegment(1, [['type' => 'order_frequency_at_least', 'params' => ['count' => '3']]]);
        $this->primeFactory();

        $this->rfmCalculator->method('getAggregatesForAllCustomers')->willReturn([
            1 => ['frequency' => 3, 'monetary' => 0.0, 'recency_days' => null],
            2 => ['frequency' => 2, 'monetary' => 0.0, 'recency_days' => null],
        ]);

        self::assertSame([1], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMultipleRfmConditionsShareOneAggregateQuery(): void
    {
        $this->stubSegment(1, [
            ['type' => 'recency_days_at_most', 'params' => ['days' => '30']],
            ['type' => 'monetary_total_at_least', 'params' => ['amount' => '100']],
        ]);
        $this->primeFactory();

        $this->rfmCalculator->expects(self::once())->method('getAggregatesForAllCustomers')->willReturn([
            1 => ['frequency' => 0, 'monetary' => 100.0, 'recency_days' => 10],
            2 => ['frequency' => 0, 'monetary' => 100.0, 'recency_days' => 90],
        ]);

        self::assertSame([1], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMonetaryTotalAtLeastFiltersInclusively(): void
    {
        $this->stubSegment(1, [['type' => 'monetary_total_at_least', 'params' => ['amount' => '100']]]);
        $this->primeFactory();

        $this->rfmCalculator->method('getAggregatesForAllCustomers')->willReturn([
            1 => ['frequency' => 0, 'monetary' => 100.0, 'recency_days' => null],
            2 => ['frequency' => 0, 'monetary' => 99.99, 'recency_days' => null],
        ]);

        self::assertSame([1], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOrderFrequencyAtLeastZeroMatchesCustomersWithNoOrdersToo(): void
    {
        $this->stubSegment(1, [['type' => 'order_frequency_at_least', 'params' => ['count' => '0']]]);
        $this->primeFactory();

        // Customer 2 has never ordered, so it's absent from the aggregate map entirely — a
        // threshold of 0 must still match them (RfmCalculator::getFrequency() would return 0 for
        // them on the single-customer path, and 0 >= 0), so this must fall back to "everyone",
        // not just customers who happen to appear in the aggregate query's results.
        $this->rfmCalculator->method('getAggregatesForAllCustomers')->willReturn([
            1 => ['frequency' => 3, 'monetary' => 0.0, 'recency_days' => null],
        ]);
        $this->rfmCalculator->method('getAllCustomerIds')->willReturn([1, 2]);

        self::assertSame([1, 2], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMonetaryTotalAtLeastZeroMatchesCustomersWithNoOrdersToo(): void
    {
        $this->stubSegment(1, [['type' => 'monetary_total_at_least', 'params' => ['amount' => '0']]]);
        $this->primeFactory();

        $this->rfmCalculator->method('getAggregatesForAllCustomers')->willReturn([
            1 => ['frequency' => 0, 'monetary' => 50.0, 'recency_days' => null],
        ]);
        $this->rfmCalculator->method('getAllCustomerIds')->willReturn([1, 2]);

        self::assertSame([1, 2], $this->resolver->getMatchingCustomerIds(1));
    }

    /**
     * @return array<int, array{recency_percentile: float, frequency_percentile: float, monetary_percentile: float}>
     */
    private function percentileFixture(): array
    {
        return [
            1 => [
                'recency_percentile' => 100.0,
                'frequency_percentile' => 100.0,
                'monetary_percentile' => 80.0,
            ],
            2 => [
                'recency_percentile' => 80.0,
                'frequency_percentile' => 50.0,
                'monetary_percentile' => 100.0,
            ],
            3 => [
                'recency_percentile' => 25.0,
                'frequency_percentile' => 25.0,
                'monetary_percentile' => 25.0,
            ],
        ];
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMonetaryPercentileAtLeastFiltersInclusively(): void
    {
        $this->stubSegment(1, [['type' => 'monetary_percentile_at_least', 'params' => ['percentile' => '80']]]);
        $this->primeFactory();

        $this->rfmCalculator->method('getPercentileRanks')->willReturn($this->percentileFixture());

        self::assertSame([1, 2], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOrderFrequencyPercentileAtLeastFiltersInclusively(): void
    {
        $this->stubSegment(
            1,
            [['type' => 'order_frequency_percentile_at_least', 'params' => ['percentile' => '80']]]
        );
        $this->primeFactory();

        $this->rfmCalculator->method('getPercentileRanks')->willReturn($this->percentileFixture());

        self::assertSame([1], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecencyPercentileAtLeastFiltersInclusively(): void
    {
        $this->stubSegment(1, [['type' => 'recency_percentile_at_least', 'params' => ['percentile' => '80']]]);
        $this->primeFactory();

        $this->rfmCalculator->method('getPercentileRanks')->willReturn($this->percentileFixture());

        self::assertSame([1, 2], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPercentileConditionWithNonNumericThresholdMatchesNobody(): void
    {
        $this->stubSegment(1, [['type' => 'monetary_percentile_at_least', 'params' => ['percentile' => 'top']]]);
        $this->primeFactory();

        $this->rfmCalculator->expects(self::never())->method('getPercentileRanks');

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMultiplePercentileConditionsShareOneRankingComputation(): void
    {
        $this->stubSegment(1, [
            ['type' => 'monetary_percentile_at_least', 'params' => ['percentile' => '80']],
            ['type' => 'recency_percentile_at_least', 'params' => ['percentile' => '90']],
        ]);
        $this->primeFactory();

        $this->rfmCalculator->expects(self::once())
            ->method('getPercentileRanks')
            ->willReturn($this->percentileFixture());

        self::assertSame([1], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPurchasedSkuConditionReturnsMatchingCustomerIds(): void
    {
        $this->stubSegment(1, [['type' => 'purchased_sku', 'params' => ['sku' => '24-MB01']]]);
        $this->primeFactory();

        $this->purchasedProductResolver->method('getCustomerIdsWhoPurchasedSku')
            ->willReturnMap([['24-MB01', [5, 6]]]);

        self::assertSame([5, 6], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPurchasedSkuFailsClosedOnEmptySku(): void
    {
        $this->stubSegment(1, [['type' => 'purchased_sku', 'params' => ['sku' => '']]]);
        $this->primeFactory();

        $this->purchasedProductResolver->expects(self::never())->method('getCustomerIdsWhoPurchasedSku');

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPurchasedCategoryConditionReturnsMatchingCustomerIds(): void
    {
        $this->stubSegment(1, [['type' => 'purchased_category', 'params' => ['category_id' => '15']]]);
        $this->primeFactory();

        $this->purchasedProductResolver->method('getCustomerIdsWhoPurchasedInCategory')
            ->willReturnMap([[15, [7]]]);

        self::assertSame([7], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPurchasedCategoryFailsClosedOnNonNumericCategoryId(): void
    {
        $this->stubSegment(1, [['type' => 'purchased_category', 'params' => ['category_id' => 'not-a-number']]]);
        $this->primeFactory();

        $this->purchasedProductResolver->expects(self::never())->method('getCustomerIdsWhoPurchasedInCategory');

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInSegmentRecursesIntoTargetSegment(): void
    {
        $this->stubSegment(1, [['type' => 'in_segment', 'params' => ['segment_id' => '2']]]);
        $this->stubSegment(2, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->primeFactory();

        $this->customerTagManager->method('getCustomerIdsWithTag')->willReturnMap([['vip', [9]]]);

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
    public function testNotInSegmentExcludesTargetSegmentMembers(): void
    {
        $this->stubSegment(1, [['type' => 'not_in_segment', 'params' => ['segment_id' => '2']]]);
        $this->stubSegment(2, [['type' => 'tag', 'params' => ['tag' => 'vip']]]);
        $this->primeFactory();

        $this->customerTagManager->method('getCustomerIdsWithTag')->willReturnMap([['vip', [9]]]);
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
    public function testOrderTotalGteAlwaysMatchesNobody(): void
    {
        $this->stubSegment(1, [['type' => 'order_total_gte', 'params' => ['amount' => '100']]]);
        $this->primeFactory();

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testVisitorTagAlwaysMatchesNobody(): void
    {
        $this->stubSegment(1, [['type' => 'visitor_tag', 'params' => ['tag' => 'x']]]);
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

    #[AllowMockObjectsWithoutExpectations]
    public function testAndIntersectionAcrossMultipleConditions(): void
    {
        $this->stubSegment(1, [
            ['type' => 'tag', 'params' => ['tag' => 'vip']],
            ['type' => 'score_at_least', 'params' => ['threshold' => '50']],
        ]);
        $this->primeFactory();

        $this->customerTagManager->method('getCustomerIdsWithTag')->willReturn([1, 2, 3]);
        $this->customerScoreManager->method('getCustomerIdsWithScoreAtLeast')->willReturn([2, 3, 4]);

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

        $this->customerTagManager->method('getCustomerIdsWithTag')->willReturn([]);
        $this->customerScoreManager->expects(self::never())->method('getCustomerIdsWithScoreAtLeast');

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

        $this->customerTagManager->method('getCustomerIdsWithTag')->willReturn([1, 2]);
        $this->customerScoreManager->method('getCustomerIdsWithScoreAtLeast')->willReturn([3, 4]);
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
    public function testScoreAtLeastFailsClosedOnNonNumericThreshold(): void
    {
        $this->stubSegment(1, [['type' => 'score_at_least', 'params' => ['threshold' => 'not-a-number']]]);
        $this->primeFactory();

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRecencyDaysAtMostFailsClosedOnNonNumericDays(): void
    {
        $this->stubSegment(1, [['type' => 'recency_days_at_most', 'params' => ['days' => 'not-a-number']]]);
        $this->primeFactory();

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOrderFrequencyAtLeastFailsClosedOnNonNumericCount(): void
    {
        $this->stubSegment(1, [['type' => 'order_frequency_at_least', 'params' => ['count' => 'not-a-number']]]);
        $this->primeFactory();

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMonetaryTotalAtLeastFailsClosedOnNonNumericAmount(): void
    {
        $this->stubSegment(1, [['type' => 'monetary_total_at_least', 'params' => ['amount' => 'not-a-number']]]);
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
    public function testAnyLogicUnionsAcrossMultipleConditions(): void
    {
        $this->stubSegmentConditionLogic('any');
        $this->stubSegment(1, [
            ['type' => 'tag', 'params' => ['tag' => 'vip']],
            ['type' => 'score_at_least', 'params' => ['threshold' => '50']],
        ]);
        $this->primeFactory();

        $this->customerTagManager->method('getCustomerIdsWithTag')->willReturn([1, 2]);
        $this->customerScoreManager->method('getCustomerIdsWithScoreAtLeast')->willReturn([2, 3]);

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

        $this->customerTagManager->method('getCustomerIdsWithTag')->willReturn([1]);
        $this->logger->expects(self::once())->method('error');

        self::assertSame([1], $this->resolver->getMatchingCustomerIds(1));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNestedGroupIsResolvedWithItsOwnLogicAndIntersectedIntoTheAndTop(): void
    {
        // Top level AND: tag=vip AND (group, OR: score>=999 OR score>=1) - customer 2 has the
        // tag but only clears the group via its low OR branch; customer 3 has the tag but clears
        // neither branch of the group, so must be excluded from the final AND result.
        $this->stubSegment(1, [
            ['type' => 'tag', 'params' => ['tag' => 'vip']],
            [
                'type' => 'group',
                'params' => [
                    'logic' => 'any',
                    'conditions' => [
                        ['type' => 'score_at_least', 'params' => ['threshold' => '999']],
                        ['type' => 'score_at_least', 'params' => ['threshold' => '1']],
                    ],
                ],
            ],
        ]);
        $this->primeFactory();

        $this->customerTagManager->method('getCustomerIdsWithTag')->willReturn([2, 3]);
        $this->customerScoreManager->method('getCustomerIdsWithScoreAtLeast')->willReturnMap([
            [999, []],
            [1, [2]],
        ]);

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

        // params normalized to [] means "threshold" is absent - resolveScoreAtLeast() fails
        // closed on a missing threshold the same way it would for a hand-written row missing
        // the field entirely, never reaching the customer score manager at all.
        $this->customerScoreManager->expects(self::never())->method('getCustomerIdsWithScoreAtLeast');

        self::assertSame([], $this->resolver->getMatchingCustomerIds(1));
    }
}
