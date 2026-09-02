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
        $connection->method('fetchAll')->willReturn([
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
        ]);

        $calculator = $this->makeCalculator($connection, $now);

        // N = 4. Frequencies across the base are [0, 1, 3, 5] and monetaries [0, 100, 300, 500],
        // so "count with metric <= mine / 4 * 100" gives 25/50/75/100. Recency days are
        // [5, 10, 30, INF]; "count with days >= mine" inverts the order so the most recent
        // customer still scores 100.
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
                    'recency_percentile' => 25.0,
                    'frequency_percentile' => 25.0,
                    'monetary_percentile' => 25.0,
                ],
            ],
            $calculator->getPercentileRanks()
        );
    }

    public function testGetPercentileRanksScoresZeroOrderCustomerAtTheBottom(): void
    {
        $now = 1700000000;
        $lastOrderAt = date('Y-m-d H:i:s', $now - 3 * 86400);

        // Nine customers who have ordered, one who hasn't — the zero-order customer is alone in
        // the bottom tenth of every metric, so 10.0 is as close to 0 as N = 10 can express.
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
        $connection->method('fetchAll')->willReturn($orderRows);

        $calculator = $this->makeCalculator($connection, $now);
        $ranks = $calculator->getPercentileRanks();

        self::assertSame(
            [
                'recency_percentile' => 10.0,
                'frequency_percentile' => 10.0,
                'monetary_percentile' => 10.0,
            ],
            $ranks[10]
        );
        // The nine who ordered all share the same last_order_at, so they tie at the top of
        // recency: every one of them has "days >= mine" true for all nine plus the never-ordered
        // customer.
        self::assertSame(100.0, $ranks[1]['recency_percentile']);
        self::assertSame(100.0, $ranks[9]['monetary_percentile']);
    }

    public function testGetPercentileRanksReturnsEmptyArrayWhenStoreHasNoCustomers(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn([]);

        $calculator = $this->makeCalculator($connection);

        self::assertSame([], $calculator->getPercentileRanks());
    }

    public function testGetPercentileRanksCachesWithinTheTtlWindow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->expects(self::once())->method('fetchCol')->willReturn(['1']);
        $connection->expects(self::once())->method('fetchAll')->willReturn([]);

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
        $connection->expects(self::exactly(2))->method('fetchAll')->willReturn([]);

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
}
