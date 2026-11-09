<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\ProductPerformance\Grid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\ResourceModel\ProductPerformance\Grid\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Same technique as RevenueAnalytics\Grid\CollectionTest: each connection->select() call returns
 * a fresh mock in the exact order _initSelect() makes them, joinLeft calls on the main select are
 * recorded (not asserted inline, PHPUnit 10+ dropped withConsecutive).
 */
class CollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testInitSelectJoinsMaterializedViewsAndLivePurchasesWithNullifGuardedAbandonRate(): void
    {
        // A stub, not a mock: assertions on its joinLeft() calls happen manually below via the
        // recorded $joinCalls array, not through PHPUnit's own expects()/call-count machinery -
        // createMock() here (with only lenient method() stubs, no expects()) tripped PHPUnit
        // 12's "no expectations configured" notice, which this repo's CI turns into a hard
        // failure via --fail-on-phpunit-notice.
        $mainSelect = $this->createStub(Select::class);
        $mainSelect->method('from')->willReturnSelf();
        $joinCalls = [];
        $mainSelect->method('joinLeft')->willReturnCallback(
            function (...$args) use (&$joinCalls, $mainSelect) {
                $joinCalls[] = $args;
                return $mainSelect;
            }
        );

        $purchases = $this->createMock(Select::class);
        $purchases->expects(self::once())->method('from')->with(['o' => 'sales_order'], [])->willReturnSelf();
        $purchases->expects(self::once())->method('joinInner')->with(
            ['oi' => 'sales_order_item'],
            'oi.order_id = o.entity_id',
            ['sku' => 'oi.sku', 'purchase_count' => 'SUM(oi.qty_ordered)']
        )->willReturnSelf();
        $purchases->expects(self::once())->method('where')
            ->with('o.state != ?', 'canceled')->willReturnSelf();
        $purchases->expects(self::once())->method('group')->with('oi.sku')->willReturnSelf();
        $purchases->method('assemble')->willReturn('PURCHASES_SQL');

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls(
            // SearchResult::__construct() builds the main select lazily via two separate
            // getSelect() accesses (setMainTable(true) for the initial FROM part, then the real
            // table name) before _initSelect() ever runs - confirmed directly by instrumenting
            // connection->select() with a call logger. Both must resolve to the SAME mock.
            $mainSelect,
            $mainSelect,
            $purchases
        );

        $this->buildAndConstruct($connection);

        self::assertCount(2, $joinCalls);

        self::assertSame('ordo_product_performance', $joinCalls[0][0]['ordo_pp']);
        self::assertSame('ordo_pp.sku = main_table.sku', $joinCalls[0][1]);
        self::assertSame([], $joinCalls[0][2]);

        // Embeds the live purchases subselect (excludes canceled orders, grouped by sku) - not a
        // raw, un-grouped sales_order_item join that would fan out one row per order line.
        self::assertSame('(PURCHASES_SQL)', (string) $joinCalls[1][0]['purchases']);
        self::assertSame('purchases.sku = main_table.sku', $joinCalls[1][1]);

        $cols = $joinCalls[1][2];
        self::assertSame('COALESCE(ordo_pp.view_count, 0)', (string) $cols['view_count']);
        self::assertSame('COALESCE(purchases.purchase_count, 0)', (string) $cols['purchase_count']);
        // The NULLIF(..., 0) guard is the specific, documented correctness behavior here: a
        // never-viewed product must read as "no data" (NULL), not a misleading 100% abandon rate.
        self::assertSame(
            '1 - (COALESCE(purchases.purchase_count, 0) / NULLIF(COALESCE(ordo_pp.view_count, 0), 0))',
            (string) $cols['abandon_rate']
        );
    }

    private function buildAndConstruct(AdapterInterface $connection): Collection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            static fn ($table) => is_string($table) ? $table : 'catalog_product_entity'
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
