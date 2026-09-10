<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Cron\CalculateReorderCycle;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ReorderCycleFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CalculateReorderCycleTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        return $select;
    }

    public function testExecuteSkipsCustomersBelowMinimumOrders(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-01 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-02-01 00:00:00'],
        ]);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $reorderCycleFactory = $this->createMock(ReorderCycleFactory::class);
        $reorderCycleFactory->expects(self::never())->method('create');

        $reorderCycleResource = $this->createStub(ReorderCycleResource::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 reorder cycles'));

$result = (new CalculateReorderCycle($resourceConnection, $reorderCycleFactory, $reorderCycleResource, new CronRunLogger($logger)))->execute();

        self::assertSame(0, $result);
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
        $connection->method('fetchOne')->willReturn(false);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $model = $this->createMock(ReorderCycle::class);
        $model->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['customer_id'] === 1 && $data['sku'] === 'SKU-1'
        ));

        $reorderCycleFactory = $this->createMock(ReorderCycleFactory::class);
        $reorderCycleFactory->method('create')->willReturn($model);

        $reorderCycleResource = $this->createMock(ReorderCycleResource::class);
        $reorderCycleResource->method('getConnection')->willReturn($connection);
        $reorderCycleResource->method('getMainTable')->willReturn('ordo_reorder_cycle');
        $reorderCycleResource->expects(self::once())->method('save')->with($model);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 reorder cycles'));

$result = (new CalculateReorderCycle($resourceConnection, $reorderCycleFactory, $reorderCycleResource, new CronRunLogger($logger)))->execute();

        self::assertSame(1, $result);
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
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $reorderCycleFactory = $this->createMock(ReorderCycleFactory::class);
        $reorderCycleFactory->expects(self::never())->method('create');

        $reorderCycleResource = $this->createMock(ReorderCycleResource::class);
        $reorderCycleResource->expects(self::never())->method('save');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 reorder cycles'));

        (new CalculateReorderCycle($resourceConnection, $reorderCycleFactory, $reorderCycleResource, new CronRunLogger($logger)))->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteUpdatesExistingCycle(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-01-01 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-02-01 00:00:00'],
            ['customer_id' => 1, 'sku' => 'SKU-1', 'created_at' => '2026-03-01 00:00:00'],
        ]);
        $connection->method('fetchOne')->willReturn('9');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $model = $this->createMock(ReorderCycle::class);
        $model->method('load')->willReturnSelf();
        $model->expects(self::once())->method('setData');

        $reorderCycleFactory = $this->createMock(ReorderCycleFactory::class);
        $reorderCycleFactory->method('create')->willReturn($model);

        $reorderCycleResource = $this->createMock(ReorderCycleResource::class);
        $reorderCycleResource->method('getConnection')->willReturn($connection);
        $reorderCycleResource->method('getMainTable')->willReturn('ordo_reorder_cycle');
        $reorderCycleResource->expects(self::once())->method('save')->with($model);

        $logger = $this->createStub(LoggerInterface::class);

        (new CalculateReorderCycle($resourceConnection, $reorderCycleFactory, $reorderCycleResource, new CronRunLogger($logger)))->execute();
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
        $connection->method('fetchOne')->willReturn(false);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $model = $this->createMock(ReorderCycle::class);
        $model->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['avg_interval_days'] === 30
        ));

        $reorderCycleFactory = $this->createMock(ReorderCycleFactory::class);
        $reorderCycleFactory->method('create')->willReturn($model);

        $reorderCycleResource = $this->createMock(ReorderCycleResource::class);
        $reorderCycleResource->method('getConnection')->willReturn($connection);
        $reorderCycleResource->method('getMainTable')->willReturn('ordo_reorder_cycle');
        $reorderCycleResource->expects(self::once())->method('save')->with($model);

        $logger = $this->createStub(LoggerInterface::class);

        (new CalculateReorderCycle($resourceConnection, $reorderCycleFactory, $reorderCycleResource, new CronRunLogger($logger)))->execute();
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

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $reorderCycleFactory = $this->createStub(ReorderCycleFactory::class);
        $reorderCycleResource = $this->createStub(ReorderCycleResource::class);
        $logger = $this->createStub(LoggerInterface::class);

        (new CalculateReorderCycle($resourceConnection, $reorderCycleFactory, $reorderCycleResource, new CronRunLogger($logger)))->execute();

        self::assertNotNull($capturedCutoff, 'Expected a created_at >= ? cutoff to be applied.');
        // Roughly 730 days ago (within a minute of tolerance for test execution time) - not an
        // exact match, since the cutoff is computed from the current time at call time.
        $expected = strtotime('-730 days');
        self::assertEqualsWithDelta($expected, strtotime((string) $capturedCutoff), 60);
    }
}
