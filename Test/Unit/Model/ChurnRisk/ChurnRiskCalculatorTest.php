<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ChurnRisk;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\ChurnRisk\ChurnRiskCalculator;
use PHPUnit\Framework\TestCase;

class ChurnRiskCalculatorTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        return $select;
    }

    private function makeCalculator(AdapterInterface $connection, int $now = 1700000000): ChurnRiskCalculator
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($now);

        return new ChurnRiskCalculator($resourceConnection, $dateTime);
    }

    public function testGetChurnRiskRatioForCustomerComputesElapsedOverMedianInterval(): void
    {
        $now = 1700000000;
        // Two prior orders 10 days apart, the most recent one 15 days ago -> 15 / 10 = 1.5.
        $thirtyFiveDaysAgo = date('Y-m-d', $now - 35 * 86400);
        $twentyFiveDaysAgo = date('Y-m-d', $now - 25 * 86400);
        $fifteenDaysAgo = date('Y-m-d', $now - 15 * 86400);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn([$thirtyFiveDaysAgo, $twentyFiveDaysAgo, $fifteenDaysAgo]);

        $calculator = $this->makeCalculator($connection, $now);

        self::assertEqualsWithDelta(1.5, $calculator->getChurnRiskRatioForCustomer(42), 0.1);
    }

    public function testGetChurnRiskRatioForCustomerReturnsNullWithFewerThanTwoOrders(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['2026-01-01']);

        $calculator = $this->makeCalculator($connection);

        self::assertNull($calculator->getChurnRiskRatioForCustomer(42));
    }

    public function testGetChurnRiskRatioForCustomerReturnsNullWithNoOrders(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn([]);

        $calculator = $this->makeCalculator($connection);

        self::assertNull($calculator->getChurnRiskRatioForCustomer(42));
    }

    public function testGetChurnRiskRatioForCustomerReturnsNullWhenMedianIntervalIsSameDay(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['2026-01-01 10:00:00', '2026-01-01 11:00:00']);

        $calculator = $this->makeCalculator($connection);

        self::assertNull($calculator->getChurnRiskRatioForCustomer(42));
    }

    public function testGetChurnRiskRatiosForAllCustomersGroupsByCustomer(): void
    {
        $now = 1700000000;
        $thirtyDaysAgo = date('Y-m-d', $now - 30 * 86400);
        $twentyDaysAgo = date('Y-m-d', $now - 20 * 86400);
        $tenDaysAgo = date('Y-m-d', $now - 10 * 86400);

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            // Customer 1: interval 10 days, last order 10 days ago -> ratio 1.0.
            ['customer_id' => 1, 'created_at' => $twentyDaysAgo],
            ['customer_id' => 1, 'created_at' => $tenDaysAgo],
            // Customer 2: only one order -> no baseline, excluded entirely.
            ['customer_id' => 2, 'created_at' => $tenDaysAgo],
            // Customer 3: interval 10 days, last order 20 days ago -> ratio 2.0.
            ['customer_id' => 3, 'created_at' => $thirtyDaysAgo],
            ['customer_id' => 3, 'created_at' => $twentyDaysAgo],
        ]);

        $calculator = $this->makeCalculator($connection, $now);

        $ratios = $calculator->getChurnRiskRatiosForAllCustomers();

        self::assertEqualsWithDelta(1.0, $ratios[1], 0.1);
        self::assertArrayNotHasKey(2, $ratios);
        self::assertEqualsWithDelta(2.0, $ratios[3], 0.1);
    }

    /**
     * The snapshot cache Segment\SegmentMemberResolver relies on - see RfmCalculatorTest's
     * identical coverage of its own aggregates/all-customer-ids snapshot for the full reasoning.
     */
    public function testGetChurnRiskRatiosForAllCustomersCachesUntilResetSnapshotCache(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->expects(self::exactly(2))->method('fetchAll')->willReturn([]);

        $calculator = $this->makeCalculator($connection);

        $calculator->getChurnRiskRatiosForAllCustomers();
        $calculator->getChurnRiskRatiosForAllCustomers();
        $calculator->resetSnapshotCache();
        $calculator->getChurnRiskRatiosForAllCustomers();
    }
}
