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

        (new CalculateReorderCycle($resourceConnection, $reorderCycleFactory, $reorderCycleResource, $logger))->execute();
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

        (new CalculateReorderCycle($resourceConnection, $reorderCycleFactory, $reorderCycleResource, $logger))->execute();
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

        (new CalculateReorderCycle($resourceConnection, $reorderCycleFactory, $reorderCycleResource, $logger))->execute();
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

        (new CalculateReorderCycle($resourceConnection, $reorderCycleFactory, $reorderCycleResource, $logger))->execute();
    }
}
