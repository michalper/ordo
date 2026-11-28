<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\LoyaltyPointsReport\Grid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\ResourceModel\LoyaltyPointsReport\Grid\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Same ObjectManager-singleton-stubbing + consecutive-select-stub technique RevenueAnalytics\
 * Grid\CollectionTest's own docblock already establishes for a _initSelect() with nested
 * subselects: each connection->select() call returns a FRESH mock in the exact order
 * _initSelect() makes them (parent::_initSelect() first, for the main select - built lazily via
 * two separate getSelect() accesses before _initSelect() runs - then one per subselect in code
 * order). The real SQL (a MySQL SUM() OVER window function) is only meaningfully verified against
 * a real database - LoyaltyPointsReportGridCollectionTest (Test/Integration) does that; this
 * proves the query is actually ASSEMBLED the way the class's own docblock claims (right table,
 * right columns, right grouping, the running-total select wrapping the monthly one).
 */
class CollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testInitSelectBuildsTheMonthlyAggregateThenWrapsItWithARunningTotal(): void
    {
        $mainSelect = $this->createMock(Select::class);
        $mainFromCalls = [];
        // Called twice: once by parent::_initSelect()'s own main-table setup (this test doesn't
        // care about that call's args), once by this class's own reset()+from() pair at the end -
        // every call is recorded, and only the LAST one is asserted on below.
        $mainSelect->method('from')->willReturnCallback(
            function (...$args) use (&$mainFromCalls, $mainSelect) {
                $mainFromCalls[] = $args;
                return $mainSelect;
            }
        );
        $mainSelect->expects(self::once())->method('reset')->with(Select::FROM)->willReturnSelf();

        $monthlySelect = $this->createMock(Select::class);
        $monthlyFromArgs = [];
        $monthlySelect->expects(self::once())->method('from')->willReturnCallback(
            function (...$args) use (&$monthlyFromArgs, $monthlySelect) {
                $monthlyFromArgs = $args;
                return $monthlySelect;
            }
        );
        $monthlySelect->expects(self::once())->method('group')->with('month')->willReturnSelf();
        $monthlySelect->method('assemble')->willReturn('MONTHLY_SQL');

        $runningTotalSelect = $this->createMock(Select::class);
        $runningTotalFromArgs = [];
        $runningTotalSelect->expects(self::once())->method('from')->willReturnCallback(
            function (...$args) use (&$runningTotalFromArgs, $runningTotalSelect) {
                $runningTotalFromArgs = $args;
                return $runningTotalSelect;
            }
        );
        $runningTotalSelect->method('assemble')->willReturn('RUNNING_TOTAL_SQL');

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls(
            $mainSelect,
            $mainSelect,
            $monthlySelect,
            $runningTotalSelect
        );

        $this->buildAndConstruct($connection);

        $finalFromArgs = end($mainFromCalls);

        self::assertSame(
            $this->loyaltyPointsLedgerTable(),
            $monthlyFromArgs[0],
            'The monthly aggregate must read from ordo_loyalty_points_ledger.'
        );
        $monthlyCols = $monthlyFromArgs[1];
        self::assertSame(["DATE_FORMAT(created_at, '%Y-%m-01')"], [(string) $monthlyCols['month']]);
        self::assertStringContainsString("type = 'earn'", (string) $monthlyCols['earned']);
        self::assertStringContainsString("type = 'redeem'", (string) $monthlyCols['redeemed']);
        self::assertStringContainsString("type = 'expire'", (string) $monthlyCols['expired']);
        self::assertSame('SUM(points_delta)', (string) $monthlyCols['net']);

        self::assertSame('(MONTHLY_SQL)', (string) $runningTotalFromArgs[0]['m']);
        $runningCols = $runningTotalFromArgs[1];
        self::assertSame(
            ['entity_id', 'month', 'earned', 'redeemed', 'expired', 'net', 'outstanding_liability', 'redemption_rate'],
            array_keys($runningCols)
        );
        self::assertSame('m.month', $runningCols['entity_id']);
        self::assertStringContainsString('SUM(m.net) OVER (ORDER BY m.month)', (string) $runningCols['outstanding_liability']);
        self::assertStringContainsString('NULLIF(m.earned, 0)', (string) $runningCols['redemption_rate']);

        self::assertSame('(RUNNING_TOTAL_SQL)', (string) $finalFromArgs[0]['main_table']);
    }

    private function loyaltyPointsLedgerTable(): string
    {
        return 'ordo_loyalty_points_ledger';
    }

    private function buildAndConstruct(AdapterInterface $connection): Collection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            static fn ($table) => is_string($table) ? $table : 'ordo_loyalty_points_ledger'
        );

        $resourceModel = $this->createStub(AbstractDb::class);
        $resourceModel->method('getConnection')->willReturn($connection);
        $resourceModel->method('getIdFieldName')->willReturn('entity_id');

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($resourceConnection);
        $objectManager->method('create')->willReturn($resourceModel);
        ObjectManager::setInstance($objectManager);

        return new Collection(
            $this->createStub(EntityFactoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FetchStrategyInterface::class),
            $this->createStub(ManagerInterface::class),
            $resourceConnection
        );
    }
}
