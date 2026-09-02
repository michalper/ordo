<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Rfm\Grid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\ResourceModel\Rfm\Grid\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Same ObjectManager-singleton technique as
 * Test\Unit\Model\ResourceModel\Campaign\Grid\CollectionTest — SearchResult resolves its
 * ResourceConnection through the global instance during construction, so it has to be stubbed
 * for the duration of the test and restored afterwards.
 */
class CollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testInitSelectLeftJoinsTheSalesOrderAggregateWithQuintileColumns(): void
    {
        $aggregateSelect = $this->createStub(Select::class);
        $aggregateSelect->method('from')->willReturnSelf();
        $aggregateSelect->method('where')->willReturnSelf();
        $aggregateSelect->method('group')->willReturnSelf();
        $aggregateSelect->method('assemble')->willReturn('SELECT ... FROM sales_order');

        $mainSelect = $this->createMock(Select::class);
        $mainSelect->method('from')->willReturnSelf();

        $joinArgs = null;
        $mainSelect->expects(self::once())->method('joinLeft')->willReturnCallback(
            function ($name, $cond, $cols) use (&$joinArgs, $mainSelect) {
                $joinArgs = [$name, $cond, $cols];
                return $mainSelect;
            }
        );

        // setConnection() builds a fresh main select each time it's called, and the construction
        // path calls it twice (once from Data\Collection\AbstractDb, once from
        // Model\ResourceModel\Db\Collection\AbstractCollection) before _initSelect() runs — so
        // the first two select() calls are the main select, and everything after is the
        // sales_order aggregate subselect _initSelect() assembles.
        $selectCalls = 0;
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(
            static function () use (&$selectCalls, $mainSelect, $aggregateSelect) {
                $selectCalls++;
                return $selectCalls <= 2 ? $mainSelect : $aggregateSelect;
            }
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        // SearchResult::__construct() calls setMainTable(true) once before the real table name,
        // so this stub must tolerate a non-string first call.
        $resourceConnection->method('getTableName')->willReturnCallback(
            static fn ($table) => is_string($table) ? $table : 'customer_entity'
        );

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($resourceConnection);
        ObjectManager::setInstance($objectManager);

        $collection = new Collection(
            $this->createStub(EntityFactoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FetchStrategyInterface::class),
            $this->createStub(ManagerInterface::class),
            $resourceConnection
        );
        // _initSelect() runs lazily on first getSelect().
        $collection->getSelect();

        self::assertNotNull($joinArgs);
        self::assertSame(['rfm_agg'], array_keys($joinArgs[0]));
        self::assertSame('rfm_agg.customer_id = main_table.entity_id', $joinArgs[1]);

        $columns = array_map('strval', $joinArgs[2]);
        self::assertSame(
            [
                'frequency',
                'monetary',
                'last_order_at',
                'recency_days',
                'monetary_quintile',
                'frequency_quintile',
                'recency_quintile',
            ],
            array_keys($columns)
        );
        self::assertStringContainsString('DATEDIFF(NOW(), rfm_agg.last_order_at)', $columns['recency_days']);
        // Quintile 5 must always be the best fifth: highest monetary/frequency, lowest recency.
        self::assertStringContainsString('NTILE(5) OVER (ORDER BY COALESCE(rfm_agg.monetary, 0) ASC)', $columns['monetary_quintile']);
        self::assertStringContainsString('NTILE(5) OVER (ORDER BY COALESCE(rfm_agg.frequency, 0) ASC)', $columns['frequency_quintile']);
        self::assertStringContainsString('DESC)', $columns['recency_quintile']);
        self::assertStringContainsString('999999', $columns['recency_quintile']);
    }
}
