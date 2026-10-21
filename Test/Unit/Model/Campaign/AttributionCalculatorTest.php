<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\AttributionCalculator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class AttributionCalculatorTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('distinct')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        return $select;
    }

    private function makeResourceConnection(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return $resourceConnection;
    }

    private function makeConfig(int $windowDays = 14): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getAttributionWindowDays')->willReturn($windowDays);

        return $config;
    }

    public function testComputeForRecentOrdersWithNoOrdersInsertsNothing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);
        $connection->expects(self::never())->method('delete');
        $connection->expects(self::never())->method('insert');

        $calculator = new AttributionCalculator($this->makeResourceConnection($connection), $this->makeConfig());

        self::assertSame(0, $calculator->computeForRecentOrders());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testComputeForRecentOrdersWithNoTouchesWritesNothing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => '10', 'customer_id' => '42', 'grand_total' => '100.0000', 'created_at' => '2026-09-01 00:00:00'],
        ]);
        $connection->method('fetchCol')->willReturn([]);
        // The anti-join guarantees the order has no rows, so there's nothing to delete either —
        // a zero-touch order simply stays a candidate for later passes.
        $connection->expects(self::never())->method('delete');
        $connection->expects(self::never())->method('insertMultiple');

        $calculator = new AttributionCalculator($this->makeResourceConnection($connection), $this->makeConfig());

        self::assertSame(1, $calculator->computeForRecentOrders());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testComputeForRecentOrdersSplitsRevenueEquallyAcrossTouchedCampaignsInOneBatchInsert(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => '10', 'customer_id' => '42', 'grand_total' => '100.0000', 'created_at' => '2026-09-01 00:00:00'],
        ]);
        $connection->method('fetchCol')->willReturn(['5', '9']);

        $inserted = [];
        $connection->expects(self::once())->method('insertMultiple')->willReturnCallback(
            function (string $table, array $rows) use (&$inserted): int {
                self::assertSame('ordo_campaign_attribution', $table);
                $inserted = $rows;
                return count($rows);
            }
        );

        $calculator = new AttributionCalculator($this->makeResourceConnection($connection), $this->makeConfig());
        $calculator->computeForRecentOrders();

        self::assertCount(2, $inserted);
        foreach ($inserted as $row) {
            self::assertSame(10, $row['order_id']);
            self::assertSame(42, $row['customer_id']);
            self::assertSame(50.0, $row['attributed_revenue']);
            self::assertSame(2, $row['touch_count']);
            self::assertContains($row['campaign_id'], [5, 9]);
        }
    }

    /**
     * The skip-already-attributed behavior lives entirely in the order query's shape: a LEFT JOIN
     * against ordo_campaign_attribution with "ca.order_id IS NULL" is what keeps an attributed
     * order from ever being refetched (and re-split) — and newest-first ordering is what keeps a
     * fresh order from starving behind an old zero-touch backlog at the per-run cap.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testComputeForRecentOrdersAntiJoinsAwayAlreadyAttributedOrdersNewestFirst(): void
    {
        // Not makeSelect(): PHPUnit serves the FIRST stub configured per method, so the recorders
        // below must be the only joinLeft/where/order configuration this stub ever gets.
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $joinLeftCalls = [];
        $select->method('joinLeft')->willReturnCallback(
            function (mixed ...$args) use (&$joinLeftCalls, $select): Select {
                $joinLeftCalls[] = $args;
                return $select;
            }
        );
        $whereCalls = [];
        $select->method('where')->willReturnCallback(
            function (mixed ...$args) use (&$whereCalls, $select): Select {
                $whereCalls[] = $args[0];
                return $select;
            }
        );
        $orderCalls = [];
        $select->method('order')->willReturnCallback(
            function (mixed ...$args) use (&$orderCalls, $select): Select {
                $orderCalls[] = $args[0];
                return $select;
            }
        );

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);

        $calculator = new AttributionCalculator($this->makeResourceConnection($connection), $this->makeConfig());
        $calculator->computeForRecentOrders();

        self::assertCount(1, $joinLeftCalls);
        self::assertSame(['ca' => 'ordo_campaign_attribution'], $joinLeftCalls[0][0]);
        self::assertSame('ca.order_id = so.entity_id', $joinLeftCalls[0][1]);
        self::assertContains('ca.order_id IS NULL', $whereCalls);
        self::assertSame(['so.entity_id DESC'], $orderCalls);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetAttributedRevenueForCampaignsReturnsEmptyArrayForEmptyInput(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $calculator = new AttributionCalculator($this->makeResourceConnection($connection), $this->makeConfig());

        self::assertSame([], $calculator->getAttributedRevenueForCampaigns([]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetAttributedRevenueForCampaignsKeysResultByCampaignId(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn () => $this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['campaign_id' => '5', 'revenue' => '150.0000', 'orders' => '3'],
        ]);

        $calculator = new AttributionCalculator($this->makeResourceConnection($connection), $this->makeConfig());
        $totals = $calculator->getAttributedRevenueForCampaigns([5, 9]);

        self::assertSame(['revenue' => 150.0, 'orders' => 3], $totals[5]);
        self::assertArrayNotHasKey(9, $totals);
    }

}
