<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ReorderCycle;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\ReorderCycle\ReorderCycleDriftCalculator;
use PHPUnit\Framework\TestCase;

class ReorderCycleDriftCalculatorTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    private function makeCalculator(AdapterInterface $connection, int $now = 1700000000): ReorderCycleDriftCalculator
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($now);

        return new ReorderCycleDriftCalculator($resourceConnection, $dateTime);
    }

    public function testGetDriftRatioForCustomerComputesElapsedOverAverageInterval(): void
    {
        $now = 1700000000;
        $fifteenDaysAgo = date('Y-m-d', $now - 15 * 86400);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['avg_interval_days' => 10, 'last_order_date' => $fifteenDaysAgo],
        ]);

        $calculator = $this->makeCalculator($connection, $now);

        self::assertEqualsWithDelta(1.5, $calculator->getDriftRatioForCustomer(42), 0.1);
    }

    public function testGetDriftRatioForCustomerReturnsTheWorstRatioAcrossMultipleCycles(): void
    {
        $now = 1700000000;
        $fiveDaysAgo = date('Y-m-d', $now - 5 * 86400);
        $twentyDaysAgo = date('Y-m-d', $now - 20 * 86400);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            // 5 / 10 = 0.5 - the better-behaved SKU
            ['avg_interval_days' => 10, 'last_order_date' => $fiveDaysAgo],
            // 20 / 10 = 2.0 - the worst, and the one that should win
            ['avg_interval_days' => 10, 'last_order_date' => $twentyDaysAgo],
        ]);

        $calculator = $this->makeCalculator($connection, $now);

        self::assertEqualsWithDelta(2.0, $calculator->getDriftRatioForCustomer(42), 0.1);
    }

    public function testGetDriftRatioForCustomerReturnsNullWhenNoCyclesExist(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);

        $calculator = $this->makeCalculator($connection);

        self::assertNull($calculator->getDriftRatioForCustomer(42));
    }

    public function testGetDriftRatioForCustomerSkipsAZeroIntervalRow(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['avg_interval_days' => 0, 'last_order_date' => '2026-01-01'],
        ]);

        $calculator = $this->makeCalculator($connection);

        self::assertNull($calculator->getDriftRatioForCustomer(42));
    }

    public function testGetDriftRatiosForAllCustomersGroupsByCustomerAndKeepsTheWorstRatio(): void
    {
        $now = 1700000000;
        $fiveDaysAgo = date('Y-m-d', $now - 5 * 86400);
        $twentyDaysAgo = date('Y-m-d', $now - 20 * 86400);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['customer_id' => 1, 'avg_interval_days' => 10, 'last_order_date' => $fiveDaysAgo],
            ['customer_id' => 1, 'avg_interval_days' => 10, 'last_order_date' => $twentyDaysAgo],
            ['customer_id' => 2, 'avg_interval_days' => 10, 'last_order_date' => $fiveDaysAgo],
        ]);

        $calculator = $this->makeCalculator($connection, $now);

        $ratios = $calculator->getDriftRatiosForAllCustomers();

        self::assertEqualsWithDelta(2.0, $ratios[1], 0.1);
        self::assertEqualsWithDelta(0.5, $ratios[2], 0.1);
    }
}
