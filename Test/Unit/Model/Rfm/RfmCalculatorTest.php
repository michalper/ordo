<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Rfm;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use PHPUnit\Framework\TestCase;

class RfmCalculatorTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    private function makeCalculator(AdapterInterface $connection, int $now = 1700000000): RfmCalculator
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($now);

        return new RfmCalculator($resourceConnection, $dateTime);
    }

    public function testGetRecencyDaysComputesDaysSinceLastOrder(): void
    {
        $now = 1700000000;
        $tenDaysAgo = date('Y-m-d H:i:s', $now - 10 * 86400);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn($tenDaysAgo);

        $calculator = $this->makeCalculator($connection, $now);

        self::assertSame(10, $calculator->getRecencyDays(42));
    }

    public function testGetRecencyDaysReturnsNullWhenCustomerHasNoOrders(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(false);

        $calculator = $this->makeCalculator($connection);

        self::assertNull($calculator->getRecencyDays(42));
    }

    public function testGetFrequencyReturnsOrderCount(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn('5');

        $calculator = $this->makeCalculator($connection);

        self::assertSame(5, $calculator->getFrequency(42));
    }

    public function testGetMonetaryTotalReturnsSumOfGrandTotal(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn('249.90');

        $calculator = $this->makeCalculator($connection);

        self::assertSame(249.90, $calculator->getMonetaryTotal(42));
    }

    public function testGetMonetaryTotalReturnsZeroWhenNoOrders(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(false);

        $calculator = $this->makeCalculator($connection);

        self::assertSame(0.0, $calculator->getMonetaryTotal(42));
    }

    public function testGetAggregatesForAllCustomersComputesRecencyFromLastOrderAt(): void
    {
        $now = 1700000000;
        $tenDaysAgo = date('Y-m-d H:i:s', $now - 10 * 86400);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'customer_id' => '42',
                'frequency' => '3',
                'monetary' => '249.90',
                'last_order_at' => $tenDaysAgo,
            ],
        ]);

        $calculator = $this->makeCalculator($connection, $now);

        self::assertSame(
            [42 => ['frequency' => 3, 'monetary' => 249.90, 'recency_days' => 10]],
            $calculator->getAggregatesForAllCustomers()
        );
    }

    public function testGetAggregatesForAllCustomersReturnsNullRecencyWhenNoLastOrderAt(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'customer_id' => '42',
                'frequency' => '0',
                'monetary' => '0',
                'last_order_at' => null,
            ],
        ]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame(
            [42 => ['frequency' => 0, 'monetary' => 0.0, 'recency_days' => null]],
            $calculator->getAggregatesForAllCustomers()
        );
    }

    public function testGetAggregatesForAllCustomersReturnsEmptyArrayWhenNoOrders(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame([], $calculator->getAggregatesForAllCustomers());
    }

    public function testGetAllCustomerIdsReturnsEveryCustomerEntityId(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['3', '7', '11']);

        $calculator = $this->makeCalculator($connection);

        self::assertSame([3, 7, 11], $calculator->getAllCustomerIds());
    }

    public function testGetPercentileRanksComputesRankAcrossWholeCustomerBase(): void
    {
        $now = 1700000000;

        // Four customers, three of whom have ordered — hand-computed expectations below.
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['1', '2', '3', '4']);
        // First fetchAll is getPercentileRanks()'s own "read the precomputed table" check — []
        // means nothing's been computed yet, forcing the live fallback this test is actually
        // exercising. Every fetchAll call after that hits computePercentileRanks()'s aggregate
        // query, so the real fixture rows just need to be returned from then on.
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [],
            [
                [
                    'customer_id' => '1',
                    'frequency' => '1',
                    'monetary' => '100',
                    'last_order_at' => date('Y-m-d H:i:s', $now - 30 * 86400),
                ],
                [
                    'customer_id' => '2',
                    'frequency' => '3',
                    'monetary' => '300',
                    'last_order_at' => date('Y-m-d H:i:s', $now - 10 * 86400),
                ],
                [
                    'customer_id' => '3',
                    'frequency' => '5',
                    'monetary' => '500',
                    'last_order_at' => date('Y-m-d H:i:s', $now - 5 * 86400),
                ],
                // Customer 4 has no orders at all, so no aggregate row.
            ]
        );

        $calculator = $this->makeCalculator($connection, $now);

        // N = 4. Frequencies across the base are [0, 1, 3, 5] and monetaries [0, 100, 300, 500]
        // (customer 4's zero orders still contribute a 0 to these sorted arrays), so "count with
        // metric <= mine / 4 * 100" gives 25/50/75/100 for customers 4/1/2/3 respectively; same
        // for recency's inverted "count with days >= mine". Customer 4 themselves, though, is
        // always exactly percentile 0 on all three axes regardless of what that formula would say
        // for them - see computePercentileRanks()'s own docblock on why a zero-order customer's
        // OWN percentile bypasses the formula entirely, while still counting as a data point for
        // everyone else's.
        self::assertSame(
            [
                1 => [
                    'recency_percentile' => 50.0,
                    'frequency_percentile' => 50.0,
                    'monetary_percentile' => 50.0,
                ],
                2 => [
                    'recency_percentile' => 75.0,
                    'frequency_percentile' => 75.0,
                    'monetary_percentile' => 75.0,
                ],
                3 => [
                    'recency_percentile' => 100.0,
                    'frequency_percentile' => 100.0,
                    'monetary_percentile' => 100.0,
                ],
                4 => [
                    'recency_percentile' => 0.0,
                    'frequency_percentile' => 0.0,
                    'monetary_percentile' => 0.0,
                ],
            ],
            $calculator->getPercentileRanks()
        );
    }

    public function testGetPercentileRanksScoresZeroOrderCustomerAtTheBottom(): void
    {
        $now = 1700000000;
        $lastOrderAt = date('Y-m-d H:i:s', $now - 3 * 86400);

        // Nine customers who have ordered, one who hasn't — the zero-order customer is always
        // exactly percentile 0 on every metric, regardless of N.
        $orderRows = [];
        for ($customerId = 1; $customerId <= 9; $customerId++) {
            $orderRows[] = [
                'customer_id' => (string) $customerId,
                'frequency' => (string) $customerId,
                'monetary' => (string) ($customerId * 100),
                'last_order_at' => $lastOrderAt,
            ];
        }

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(array_map('strval', range(1, 10)));
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls([], $orderRows);

        $calculator = $this->makeCalculator($connection, $now);
        $ranks = $calculator->getPercentileRanks();

        self::assertSame(
            [
                'recency_percentile' => 0.0,
                'frequency_percentile' => 0.0,
                'monetary_percentile' => 0.0,
            ],
            $ranks[10]
        );
        // The nine who ordered all share the same last_order_at, so they tie at the top of
        // recency: every one of them has "days >= mine" true for all nine plus the never-ordered
        // customer.
        self::assertSame(100.0, $ranks[1]['recency_percentile']);
        self::assertSame(100.0, $ranks[9]['monetary_percentile']);
    }

    /**
     * Regression test for a real bug a code audit found: on a degenerate dataset (here, a single
     * customer who has never ordered - a brand-new/empty store), the count-based percentile
     * formula would previously count that customer as "<= itself"/">= itself" and score them at
     * percentile 100 (RFM "555", the BEST possible score) on every axis - the exact opposite of
     * "a zero-order customer is never a top spender/most recent/most frequent."
     */
    public function testGetPercentileRanksScoresTheOnlyCustomerAtZeroWhenTheyHaveNeverOrdered(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['1']);
        // No aggregate rows at all - the one customer in the store has never ordered.
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls([], []);

        $calculator = $this->makeCalculator($connection);
        $ranks = $calculator->getPercentileRanks();

        self::assertSame(
            [
                'recency_percentile' => 0.0,
                'frequency_percentile' => 0.0,
                'monetary_percentile' => 0.0,
            ],
            $ranks[1]
        );
    }

    public function testGetPercentileRanksReturnsEmptyArrayWhenStoreHasNoCustomers(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('fetchCol')->willReturn([]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame([], $calculator->getPercentileRanks());
    }

    public function testGetPercentileRanksCachesWithinTheTtlWindow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->expects(self::once())->method('fetchCol')->willReturn(['1']);
        // Twice per getPercentileRanks() call: once to check the (empty) precomputed table,
        // once inside the live-fallback aggregate query it forces.
        $connection->expects(self::exactly(2))->method('fetchAll')->willReturn([]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        // 1700000000, then 30s later — still inside the 60s TTL, so the second call must reuse
        // the cached result instead of hitting fetchCol()/fetchAll() again.
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnOnConsecutiveCalls(1700000000, 1700000030);

        $calculator = new RfmCalculator($resourceConnection, $dateTime);

        $first = $calculator->getPercentileRanks();
        $second = $calculator->getPercentileRanks();

        self::assertSame($first, $second);
    }

    public function testGetPercentileRanksRecomputesAfterTheTtlExpires(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->expects(self::exactly(2))->method('fetchCol')->willReturn(['1']);
        // Two getPercentileRanks() calls, each hitting fetchAll() twice (stored-table check +
        // live-fallback aggregate) since the TTL forces a fresh computation both times.
        $connection->expects(self::exactly(4))->method('fetchAll')->willReturn([]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        // 1700000000, then 61s later — past the 60s TTL, so the second call must recompute.
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnOnConsecutiveCalls(1700000000, 1700000061);

        $calculator = new RfmCalculator($resourceConnection, $dateTime);

        $calculator->getPercentileRanks();
        $calculator->getPercentileRanks();
    }

    public function testGetPercentileRanksReadsThePrecomputedTableWhenPopulated(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        // Only the stored-table read should ever run here — a populated table means
        // computePercentileRanks()'s aggregate query/getAllCustomerIds() must never fire.
        $connection->expects(self::once())->method('fetchAll')->willReturn([
            [
                'customer_id' => '1',
                'recency_percentile' => '80.5',
                'frequency_percentile' => '60.0',
                'monetary_percentile' => '40.25',
            ],
        ]);
        $connection->expects(self::never())->method('fetchCol');

        $calculator = $this->makeCalculator($connection);

        self::assertSame(
            [1 => ['recency_percentile' => 80.5, 'frequency_percentile' => 60.0, 'monetary_percentile' => 40.25]],
            $calculator->getPercentileRanks()
        );
    }

    /**
     * Regression test for a real correctness bug a code audit found: this stored/cached read path
     * used to select straight from ordo_customer_rfm_score with no join to customer_entity, so a
     * customer deleted after the last Cron\RecomputeRfmScores run would still incorrectly count
     * as a percentile-condition segment match (Segment\SegmentMemberResolver::
     * resolvePercentileAtLeast() iterates this map directly) until the next recompute.
     */
    public function testGetPercentileRanksJoinsCustomerEntityOnTheStoredTableRead(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects(self::once())->method('join')
            ->with(['c' => 'customer_entity'], 's.customer_id = c.entity_id', [])
            ->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            [
                'customer_id' => '1',
                'recency_percentile' => '80.5',
                'frequency_percentile' => '60.0',
                'monetary_percentile' => '40.25',
            ],
        ]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame(
            [1 => ['recency_percentile' => 80.5, 'frequency_percentile' => 60.0, 'monetary_percentile' => 40.25]],
            $calculator->getPercentileRanks()
        );
    }

    public function testRecomputeAndStoreScoresReplacesTheTableWithFreshQuintilesAndPercentiles(): void
    {
        $now = 1700000000;
        $lastOrderAt = date('Y-m-d H:i:s', $now - 5 * 86400);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['1', '2', '3', '4']);
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => '1', 'frequency' => '1', 'monetary' => '100', 'last_order_at' => $lastOrderAt],
            ['customer_id' => '2', 'frequency' => '3', 'monetary' => '300', 'last_order_at' => $lastOrderAt],
            ['customer_id' => '3', 'frequency' => '5', 'monetary' => '500', 'last_order_at' => $lastOrderAt],
        ]);

        $connection->expects(self::once())->method('delete')->with('ordo_customer_rfm_score');
        // 4 rows in one chunk (well under the 500-row batch size) — one insertMultiple() call.
        $connection->expects(self::once())->method('insertMultiple')->with(
            'ordo_customer_rfm_score',
            self::callback(function (array $rows): bool {
                self::assertCount(4, $rows);
                $byCustomer = [];
                foreach ($rows as $row) {
                    $byCustomer[$row['customer_id']] = $row;
                }
                // Same percentiles as testGetPercentileRanksComputesRankAcrossWholeCustomerBase
                // (0/50/75/100 - customer 4 has zero orders, always exactly percentile 0), so the
                // quintile buckets are 1/3/4/5.
                self::assertSame(1, $byCustomer[4]['recency_quintile']);
                self::assertSame(3, $byCustomer[1]['frequency_quintile']);
                self::assertSame(5, $byCustomer[3]['monetary_quintile']);
                self::assertSame(75.0, $byCustomer[2]['monetary_percentile']);

                return true;
            })
        );

        $calculator = $this->makeCalculator($connection, $now);
        $calculator->recomputeAndStoreScores();
    }

    public function testRecomputeAndStoreScoresClearsTheTableWithoutInsertingWhenStoreHasNoCustomers(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn([]);

        $connection->expects(self::once())->method('delete')->with('ordo_customer_rfm_score');
        $connection->expects(self::never())->method('insertMultiple');

        $calculator = $this->makeCalculator($connection);
        $calculator->recomputeAndStoreScores();
    }

    public function testGetRfmScoreLabelReturnsRfmDigitsInRecencyFrequencyMonetaryOrder(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'customer_id' => '1',
                'recency_percentile' => '100.0',
                'frequency_percentile' => '60.0',
                'monetary_percentile' => '20.0',
            ],
        ]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame('531', $calculator->getRfmScoreLabel(1));
    }

    public function testGetRfmScoreLabelReturnsNullForACustomerWithNoRank(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('fetchCol')->willReturn([]);

        $calculator = $this->makeCalculator($connection);

        self::assertNull($calculator->getRfmScoreLabel(999));
    }

    /**
     * Four boundary cases for the shared "days since last order" formula
     * (getRecencyDays()/getAggregatesForAllCustomers() both use it) - each one targets a specific
     * off-by-one/rounding mistake a careless edit could introduce, not just "some plausible day
     * count" that would coincidentally match a broken formula too:
     *  - 86399 seconds (1s under a full day) must floor to 0, not 1 - catches an 86400->86399
     *    divisor typo, which would only show up at this exact boundary.
     *  - An order timestamp in the future (clock skew / a customer's very last order landing
     *    after "now" by a second) must clamp to 0, not go negative.
     *  - An order a few hours old (same calendar day) must floor to 0, not round up to 1.
     *  - 1.5 days old must floor to 1, not ceil/round to 2 - the docblock's own "coarsest unit
     *    that divides evenly" promise depends on this being a floor, not a round.
     */
    public function testGetRecencyDaysBoundaries(): void
    {
        $now = 1700000000;

        $cases = [
            'just under one day' => [$now - 86399, 0],
            'order timestamp in the future' => [$now + 3600, 0],
            'same-day order' => [$now - 3600, 0],
            'one and a half days' => [$now - 129600, 1],
        ];

        foreach ($cases as $label => [$orderTimestamp, $expectedDays]) {
            $connection = $this->createStub(AdapterInterface::class);
            $connection->method('select')->willReturn($this->makeSelect());
            $connection->method('fetchOne')->willReturn(date('Y-m-d H:i:s', $orderTimestamp));

            $calculator = $this->makeCalculator($connection, $now);

            self::assertSame($expectedDays, $calculator->getRecencyDays(42), $label);
        }
    }

    /**
     * Same boundary cases as testGetRecencyDaysBoundaries(), through
     * getAggregatesForAllCustomers()'s own copy of the identical formula - the two must never
     * drift apart (that's this class's own docblock promise), so every mutant that formula test
     * catches needs an equivalent guard here too.
     */
    public function testGetAggregatesForAllCustomersRecencyBoundaries(): void
    {
        $now = 1700000000;

        $rows = [
            ['customer_id' => '1', 'frequency' => '1', 'monetary' => '10', 'last_order_at' => date('Y-m-d H:i:s', $now - 86399)],
            ['customer_id' => '2', 'frequency' => '1', 'monetary' => '10', 'last_order_at' => date('Y-m-d H:i:s', $now + 3600)],
            ['customer_id' => '3', 'frequency' => '1', 'monetary' => '10', 'last_order_at' => date('Y-m-d H:i:s', $now - 3600)],
            ['customer_id' => '4', 'frequency' => '1', 'monetary' => '10', 'last_order_at' => date('Y-m-d H:i:s', $now - 129600)],
        ];

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn($rows);

        $calculator = $this->makeCalculator($connection, $now);
        $aggregates = $calculator->getAggregatesForAllCustomers();

        self::assertSame(0, $aggregates[1]['recency_days'], 'just under one day');
        self::assertSame(0, $aggregates[2]['recency_days'], 'order timestamp in the future');
        self::assertSame(0, $aggregates[3]['recency_days'], 'same-day order');
        self::assertSame(1, $aggregates[4]['recency_days'], 'one and a half days');
    }

    /**
     * customer_id comes back from the DB as a string - a non-canonical one (leading space) is
     * deliberately used here rather than a clean "5", because PHP auto-normalizes a clean numeric
     * string used as an array key to an int on its own, which would make the explicit (int) cast
     * look redundant to a test using a clean digit string even though removing it is a real bug
     * for any less-clean value the DB driver could hand back.
     */
    public function testGetAggregatesForAllCustomersCastsCustomerIdKeysToInt(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => ' 5', 'frequency' => '1', 'monetary' => '10', 'last_order_at' => null],
        ]);

        $calculator = $this->makeCalculator($connection);
        $aggregates = $calculator->getAggregatesForAllCustomers();

        self::assertArrayHasKey(5, $aggregates);
        self::assertSame([5], array_keys($aggregates));
    }

    /**
     * Same int-cast guard as testGetAggregatesForAllCustomersCastsCustomerIdKeysToInt(), for the
     * precomputed-table read path.
     */
    public function testGetPercentileRanksCastsCustomerIdKeysToIntWhenReadingTheStoredTable(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'customer_id' => ' 5',
                'recency_percentile' => '10.0',
                'frequency_percentile' => '10.0',
                'monetary_percentile' => '10.0',
            ],
        ]);

        $calculator = $this->makeCalculator($connection);
        $ranks = $calculator->getPercentileRanks();

        self::assertArrayHasKey(5, $ranks);
        self::assertSame([5], array_keys($ranks));
    }

    /**
     * getAggregatesForAllCustomers() must select customer_id itself, not just the three
     * aggregates - without it there's no key to group results by at all.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGetAggregatesForAllCustomersSelectsCustomerIdColumn(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->expects(self::once())->method('from')->with(
            'sales_order',
            self::identicalTo([
                'customer_id' => 'customer_id',
                'frequency' => 'COUNT(*)',
                'monetary' => 'SUM(grand_total)',
                'last_order_at' => 'MAX(created_at)',
            ])
        )->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);

        $calculator = $this->makeCalculator($connection);
        $calculator->getAggregatesForAllCustomers();
    }

    /**
     * The stored-percentile-table read must select customer_id (there'd be no key to index the
     * result by otherwise) from the correctly-aliased table ('s') the join condition
     * ('s.customer_id = c.entity_id') depends on.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGetPercentileRanksSelectsFromTheAliasedTableWithCustomerIdColumn(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('join')->willReturnSelf();
        $select->expects(self::once())->method('from')->with(
            self::identicalTo(['s' => 'ordo_customer_rfm_score']),
            self::identicalTo([
                'customer_id',
                'recency_percentile',
                'frequency_percentile',
                'monetary_percentile',
            ])
        )->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        // A non-empty result, so getPercentileRanks() takes the stored-table path and never
        // falls back to the live computePercentileRanks() (which would call ->from() again on
        // this same mock, for an unrelated query, and violate the expects(once()) above).
        $connection->method('fetchAll')->willReturn([
            [
                'customer_id' => '1',
                'recency_percentile' => '10.0',
                'frequency_percentile' => '10.0',
                'monetary_percentile' => '10.0',
            ],
        ]);

        $calculator = $this->makeCalculator($connection);
        $calculator->getPercentileRanks();
    }

    /**
     * Both cache fields only ever get set together (right after a fresh computation), so a plain
     * "call twice" test can't observe the difference between the real `&&` and a mutated `||` -
     * this uses Reflection to force the one state that CAN tell them apart: the cache array
     * populated but its timestamp still null. Under `&&` (correct), that's not a valid cache hit,
     * so it must recompute; under `||`, it would incorrectly serve the sentinel cached value.
     */
    /**
     * The first attempt at this test (setting only percentileRanksCache, leaving
     * percentileRanksCachedAt null) turned out NOT to distinguish `&&` from `||` after all: with
     * cachedAt null, `$now - null` is `$now` (PHP coerces null to 0), which is always >= the TTL
     * regardless of which boolean operator joins the two null-checks - both the real `&&` and the
     * `||` mutant fall through to a live recompute in that state, so the mutant's own CI run
     * (correctly) still showed this line escaping. The state that actually tells them apart is
     * the other way around: cachedAt set (and within the TTL window) while cache itself is null -
     * under `&&` that still isn't a valid hit (recomputes, fine); under `||` the first disjunct
     * alone makes the whole condition true and the method would incorrectly `return
     * $this->percentileRanksCache` - literally null instead of an array.
     */
    public function testGetPercentileRanksRequiresBothCacheFieldsSetNotEither(): void
    {
        $now = 1700000000;

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('fetchCol')->willReturn(['1']);

        $calculator = $this->makeCalculator($connection, $now);

        $cachedAtProperty = new \ReflectionProperty($calculator, 'percentileRanksCachedAt');
        $cachedAtProperty->setAccessible(true);
        $cachedAtProperty->setValue($calculator, $now);
        // percentileRanksCache is deliberately left null/unset.

        self::assertIsArray($calculator->getPercentileRanks());
    }

    /**
     * Exactly at the TTL boundary (elapsed === PERCENTILE_CACHE_TTL_SECONDS) the cache must be
     * treated as expired (strict `<`), not still valid (`<=`) - a customer segment computed from
     * a 60-second-stale cache one tick too late is a real (if minor) staleness bug.
     */
    public function testGetPercentileRanksTreatsTheCacheAsExpiredExactlyAtTheTtlBoundary(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);
        // Twice, one full recompute per call - if the cache were (incorrectly) still considered
        // valid at exactly the boundary, this would only fire once.
        $connection->expects(self::exactly(2))->method('fetchCol')->willReturn([]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnOnConsecutiveCalls(1700000000, 1700000060);

        $calculator = new RfmCalculator($resourceConnection, $dateTime);

        $calculator->getPercentileRanks();
        $calculator->getPercentileRanks();
    }

    /**
     * array_chunk($rows, 500) - a store with just over one chunk's worth of customers must
     * produce two insertMultiple() calls (500 + 1), not one (a 501-sized chunk, the
     * IncrementInteger mutant) or two lopsided ones (499 + 2, the DecrementInteger mutant).
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testRecomputeAndStoreScoresChunksInsertsAtExactlyFiveHundredRows(): void
    {
        $now = 1700000000;
        $lastOrderAt = date('Y-m-d H:i:s', $now - 5 * 86400);

        $customerCount = 501;
        $ids = range(1, $customerCount);
        $rows = array_map(
            static fn (int $id): array => [
                'customer_id' => (string) $id,
                'frequency' => '1',
                'monetary' => (string) $id,
                'last_order_at' => $lastOrderAt,
            ],
            $ids
        );

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(array_map('strval', $ids));
        $connection->method('fetchAll')->willReturn($rows);
        $connection->method('delete');

        $chunkSizes = [];
        $connection->expects(self::exactly(2))->method('insertMultiple')->willReturnCallback(
            function (string $table, array $chunk) use (&$chunkSizes): void {
                $chunkSizes[] = count($chunk);
            }
        );

        $calculator = $this->makeCalculator($connection, $now);
        $calculator->recomputeAndStoreScores();

        self::assertSame([500, 1], $chunkSizes);
    }

    /**
     * quintileFromPercentile()'s two divide-by-20 boundary mistakes, both only visible on a
     * non-round-number percentile:
     *  - 21.0 -> ceil(21/20) = ceil(1.05) = quintile 2; PHP's round(1.05) is 1 (rounds to
     *    nearest, and 1.05 isn't a .5 tie), so a ceil->round mutant would report quintile 1.
     *  - 61.0 -> ceil(61/20) = ceil(3.05) = quintile 4; a /20->/21 divisor mutant gives
     *    ceil(61/21) = ceil(2.90...) = quintile 3.
     * (The third quintile mutant, min(5,...)->min(6,...), is not tested here: a percentile can
     * never exceed 100.0 by construction - see computePercentileRanks() - so ceil(100/20) never
     * reaches 6 and that branch is unreachable, an equivalent mutant.)
     */
    public function testGetRfmScoreLabelQuintileBoundaries(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'customer_id' => '1',
                'recency_percentile' => '21.0',
                'frequency_percentile' => '61.0',
                'monetary_percentile' => '50.0',
            ],
        ]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame('243', $calculator->getRfmScoreLabel(1));
    }

    /**
     * Regression guard for the OneZeroFloat mutant on the zero-order customer's monetary filler
     * (0.0 -> 1.0): a real customer whose one order has grand_total = 0 (e.g. a 100%-off coupon)
     * legitimately ties with a zero-order customer's monetary filler value. Under the correct
     * 0.0 filler, both tie at the bottom, so the paying-nothing customer still ranks at the very
     * top (100th percentile - "least bad", everyone else spent >= them... this file's own
     * count-based percentile is a >=-style rank for monetary too). Under a 1.0 filler, the
     * zero-order phantom would no longer tie, quietly changing the paying-nothing customer's
     * percentile - a real (if obscure) ranking bug this test exists specifically to catch.
     *
     * NOT covered here, deliberately - the equivalent-mutant sibling of this one: the FREQUENCY
     * filler's own Increment/Decrement mutants (0 -> 1 or -1) and the CastFloat mutants on all
     * three fillers (frequency/monetary/recency). Frequency's real minimum for any customer WHO
     * HAS ORDERED is 1 (COUNT(*) over a GROUP BY can't return a zero row), so a filler of -1, 0,
     * or 1 for the zero-order phantom is always <= every real ordered customer's frequency either
     * way - no comparison outcome in countAtMost() can ever depend on which of those three the
     * filler happens to be, unlike the monetary case above where a genuine $0 order creates a
     * real tie. The CastFloat mutants (dropping the (float) cast) are equivalent for the same
     * reason PHP's <=/< operators already compare int and float operands numerically - stripping
     * a redundant cast changes no comparison's outcome.
     */
    public function testGetPercentileRanksMonetaryFillerForZeroOrderCustomersIsZeroNotOne(): void
    {
        $now = 1700000000;

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        // Two customers total; only customer 1 has ever ordered (a single order, grand_total 0).
        $connection->method('fetchCol')->willReturn(['1', '2']);
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [],
            [
                [
                    'customer_id' => '1',
                    'frequency' => '1',
                    'monetary' => '0',
                    'last_order_at' => date('Y-m-d H:i:s', $now - 86400),
                ],
            ]
        );

        $calculator = $this->makeCalculator($connection, $now);
        $ranks = $calculator->getPercentileRanks();

        self::assertSame(100.0, $ranks[1]['monetary_percentile']);
    }

    /**
     * countAtMost()/countAtLeast() are the two private binary-search helpers computePercentileRanks()
     * relies on for its O(N log N) sort-then-count approach - a bug in either only shows up on a
     * dataset large/varied enough to exercise several distinct mid-points and duplicate ties, not
     * on the 3-4 customer fixtures the tests above use. Twelve customers, on purpose with repeated
     * frequency values (ties) and gaps in the monetary values (misses), spans enough distinct
     * binary-search paths to catch an off-by-one at any single comparison.
     *
     * KNOWN GAP (tracked, not silently accepted): mutation testing against this exact dataset
     * still shows countAtMost()/countAtLeast()'s own low/high/mid arithmetic (e.g. `<=` vs `<` on
     * the loop guard, `intdiv($low + $high, 2)` vs a wrong divisor/operand) escaping - a 12-entry
     * dataset apparently isn't enough to force every mid-point through every mutated branch
     * without also risking an infinite loop on some of them (a few of these mutants degrade to a
     * loop that only advances under specific comparison outcomes). Killing these needs either a
     * much larger crafted dataset or a dedicated reflection-based unit test that calls
     * countAtMost()/countAtLeast() directly instead of through the full percentile pipeline -
     * left as follow-up rather than blocking this round on it.
     *
     * Separately, three UnwrapArrayValues mutants (removing the array_values() calls just above
     * the sort() calls in computePercentileRanks()) are genuinely equivalent, not a coverage gap:
     * PHP's sort() always reindexes its array to sequential 0-based int keys as a side effect,
     * discarding whatever keys it started with - so whether array_values() ran first or not, the
     * array sort() hands back (and everything downstream reads) is byte-identical either way.
     */
    public function testGetPercentileRanksAcrossALargerDatasetWithTiesAndGaps(): void
    {
        $now = 1700000000;

        // frequency: 1,1,2,2,2,3,4,5,5,6,7,8 (ties at 1/2/5) — monetary has gaps (no customer
        // spent exactly 250 or 600) so countAtMost() must handle both "value present" and
        // "value between two sorted entries" mid-point cases.
        $fixture = [
            1 => ['frequency' => 1, 'monetary' => 50],
            2 => ['frequency' => 1, 'monetary' => 100],
            3 => ['frequency' => 2, 'monetary' => 150],
            4 => ['frequency' => 2, 'monetary' => 200],
            5 => ['frequency' => 2, 'monetary' => 300],
            6 => ['frequency' => 3, 'monetary' => 400],
            7 => ['frequency' => 4, 'monetary' => 500],
            8 => ['frequency' => 5, 'monetary' => 700],
            9 => ['frequency' => 5, 'monetary' => 800],
            10 => ['frequency' => 6, 'monetary' => 900],
            11 => ['frequency' => 7, 'monetary' => 1000],
            12 => ['frequency' => 8, 'monetary' => 1100],
        ];

        $rows = [];
        foreach ($fixture as $customerId => $values) {
            $rows[] = [
                'customer_id' => (string) $customerId,
                'frequency' => (string) $values['frequency'],
                'monetary' => (string) $values['monetary'],
                'last_order_at' => date('Y-m-d H:i:s', $now - $customerId * 86400),
            ];
        }

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(array_map('strval', array_keys($fixture)));
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls([], $rows);

        $calculator = $this->makeCalculator($connection, $now);
        $ranks = $calculator->getPercentileRanks();

        // frequency=1 (customers 1,2): 2 of 12 <= 1 -> 2/12*100
        self::assertEqualsWithDelta(16.666666666667, $ranks[1]['frequency_percentile'], 0.0001);
        // frequency=2 (customers 3,4,5): 5 of 12 <= 2 -> 5/12*100
        self::assertEqualsWithDelta(41.666666666667, $ranks[4]['frequency_percentile'], 0.0001);
        // frequency=5 (customers 8,9), the tie at the upper-middle of the range: 9 of 12 <= 5
        self::assertEqualsWithDelta(75.0, $ranks[9]['frequency_percentile'], 0.0001);
        // frequency=8 (customer 12), the very last element: all 12 <= 8
        self::assertSame(100.0, $ranks[12]['frequency_percentile']);
        // monetary=150 (customer 3), sitting between two sorted neighbors (100 and 200), not on
        // a duplicate: 3 of 12 <= 150
        self::assertEqualsWithDelta(25.0, $ranks[3]['monetary_percentile'], 0.0001);
        // recency: customer 1 ordered most recently (1 day ago) of the whole set, so every one
        // of the 12 has "days >= mine" -> 100th percentile.
        self::assertSame(100.0, $ranks[1]['recency_percentile']);
        // customer 12 ordered longest ago (12 days) - only themselves has "days >= mine".
        self::assertEqualsWithDelta(8.333333333333, $ranks[12]['recency_percentile'], 0.0001);
    }

    /**
     * Regression test for the ROADMAP.md Tier 4 "unbounded full-table scan" gap: getAllCustomerIds()
     * used to run a single unbounded SELECT over the whole customer_entity table. It now pages
     * through in SCAN_PAGE_SIZE (5,000) chunks, offsetting each call and stopping once a page
     * comes back with fewer than a full page's worth of rows - proven here with a first page of
     * exactly 5,000 ids (continues) followed by a second, partial page (stops).
     */
    #[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
    public function testGetAllCustomerIdsPagesThroughMultiplePagesWhenTheCustomerBaseExceedsOnePage(): void
    {
        $firstPage = array_map('strval', range(1, 5000));
        $secondPage = ['5001', '5002'];

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects(self::exactly(2))->method('fetchCol')
            ->willReturnOnConsecutiveCalls($firstPage, $secondPage);

        $calculator = $this->makeCalculator($connection);

        $ids = $calculator->getAllCustomerIds();

        self::assertCount(5002, $ids);
        self::assertSame(1, $ids[0]);
        self::assertSame(5002, $ids[5001]);
    }

    /**
     * Same regression coverage as above, for getAggregatesForAllCustomers()'s pagination.
     */
    #[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
    public function testGetAggregatesForAllCustomersPagesThroughMultiplePagesWhenOrdersExceedOnePage(): void
    {
        $makeRow = fn (int $customerId): array => [
            'customer_id' => (string) $customerId,
            'frequency' => '1',
            'monetary' => '10.00',
            'last_order_at' => null,
        ];
        $firstPage = array_map($makeRow, range(1, 5000));
        $secondPage = [$makeRow(5001)];

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects(self::exactly(2))->method('fetchAll')
            ->willReturnOnConsecutiveCalls($firstPage, $secondPage);

        $calculator = $this->makeCalculator($connection);

        $aggregates = $calculator->getAggregatesForAllCustomers();

        self::assertCount(5001, $aggregates);
        self::assertArrayHasKey(1, $aggregates);
        self::assertArrayHasKey(5001, $aggregates);
    }
}
