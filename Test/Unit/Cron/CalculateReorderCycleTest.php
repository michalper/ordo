<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Cron\CalculateReorderCycle;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CalculateReorderCycleTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        return $select;
    }

    private function makeConfig(int $minOrders = 3): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getReorderMinOrders')->willReturn($minOrders);

        return $config;
    }

    private function makeResource(): ReorderCycleResource
    {
        $resource = $this->createStub(ReorderCycleResource::class);
        $resource->method('getMainTable')->willReturn('ordo_reorder_cycle');

        return $resource;
    }

    private function makeCron(
        AdapterInterface $connection,
        LoggerInterface $logger,
        int $minOrders = 3
    ): CalculateReorderCycle {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return new CalculateReorderCycle(
            $resourceConnection,
            $this->makeResource(),
            $this->makeCronRunLogger($logger),
            $this->makeConfig($minOrders)
        );
    }

    public function testExecuteSkipsCustomersBelowMinimumOrders(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-01 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-02-01 00:00:00'],
        ]);
        $connection->expects(self::never())->method('insertOnDuplicate');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 reorder cycles'));

        self::assertSame(0, $this->makeCron($connection, $logger)->execute());
    }

    /**
     * The threshold comes from the admin setting (Stores → Configuration → Ordo Automation →
     * Reorder, "Minimum orders to detect a pattern"), not a hardcoded constant — a 3-order
     * history that passes the default must be skipped when the merchant raises the bar to 4.
     */
    public function testExecuteRespectsTheConfiguredMinimumOrders(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-01 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-02-01 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-03-01 00:00:00'],
        ]);
        $connection->expects(self::never())->method('insertOnDuplicate');

        $logger = $this->createStub(LoggerInterface::class);

        self::assertSame(0, $this->makeCron($connection, $logger, 4)->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteCreatesNewCycleWhenPatternDetected(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-01 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-02-01 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-03-01 00:00:00'],
        ]);

        $connection->expects(self::once())->method('insertOnDuplicate')->with(
            'ordo_reorder_cycle',
            self::callback(
                fn (array $rows) => count($rows) === 1
                    && $rows[0]['customer_id'] === 1
                    && $rows[0]['sku'] === 'SKU-1'
            ),
            ['avg_interval_days', 'last_order_date', 'next_expected_date', 'orders_considered']
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 reorder cycles'));

        self::assertSame(1, $this->makeCron($connection, $logger)->execute());
    }

    /**
     * An odd number of intervals takes the middle element directly rather than averaging two -
     * the existing pattern-detected test above only ever produces an even count (2 intervals from
     * 3 orders), so the ternary's other branch needs its own dedicated case.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteUsesTheMiddleIntervalDirectlyForAnOddIntervalCount(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-01 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-11 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-21 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-31 00:00:00'],
        ]);

        $upserted = [];
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $rows) use (&$upserted): int {
                $upserted = array_merge($upserted, $rows);
                return count($rows);
            }
        );

        $logger = $this->createStub(LoggerInterface::class);

        self::assertSame(1, $this->makeCron($connection, $logger)->execute());
        // Three 10-day intervals - the middle one directly (10), not an average of two.
        self::assertSame(10, $upserted[0]['avg_interval_days']);
    }

    public function testExecuteSkipsSameDayRepeatPurchases(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-01 10:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-01 11:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-01 12:00:00'],
        ]);
        $connection->expects(self::never())->method('insertOnDuplicate');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 reorder cycles'));

        $this->makeCron($connection, $logger)->execute();
    }

    /**
     * Regression test for the plain-mean interval estimate being skewed by a single anomalous
     * gap: dates yield intervals [30, 30, 30, 300] (e.g. a customer pausing for months once).
     * A plain mean would land around 97 days; the median stays close to the normal 30-day
     * cadence, which is what the majority of the customer's actual reorders look like.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteUsesMedianIntervalResistantToOneAnomalousGap(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-01 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-31 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-03-02 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-04-01 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-12-27 00:00:00'],
        ]);

        $connection->expects(self::once())->method('insertOnDuplicate')->with(
            self::anything(),
            self::callback(fn (array $rows) => $rows[0]['avg_interval_days'] === 30),
            self::anything()
        );

        $logger = $this->createStub(LoggerInterface::class);

        $this->makeCron($connection, $logger)->execute();
    }

    /**
     * Regression test for the ROADMAP.md Tier 4 "unbounded full-table scan" gap: the query used
     * to have no lower bound on order age at all, re-scanning a store's entire order history on
     * every single cron run regardless of how old it was.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBoundsTheQueryToRecentOrderHistory(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $capturedCutoff = null;
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use ($select, &$capturedCutoff) {
                if ($condition === 'o.created_at >= ?') {
                    $capturedCutoff = $value;
                }
                return $select;
            }
        );

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);

        $logger = $this->createStub(LoggerInterface::class);

        $this->makeCron($connection, $logger)->execute();

        self::assertNotNull($capturedCutoff, 'Expected a created_at >= ? cutoff to be applied.');
        // Roughly 730 days ago (within a minute of tolerance for test execution time) - not an
        // exact match, since the cutoff is computed from the current time at call time.
        $expected = strtotime('-730 days');
        self::assertEqualsWithDelta($expected, strtotime((string) $capturedCutoff), 60);
    }
}
