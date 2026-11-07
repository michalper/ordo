<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\FunnelReport\Grid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\ResourceModel\FunnelReport\Grid\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Same ObjectManager-singleton-stubbing technique MessageLog/CohortRetention's own Grid\
 * CollectionTest already establish for a SearchResult-based grid collection - see either test's
 * own docblock for why.
 */
class CollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    private function buildCollection(AdapterInterface $connection): Collection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('columns')->willReturnSelf();
        $select->method('assemble')->willReturn('SELECT 1');

        $connection->method('select')->willReturn($select);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            static fn ($table) => is_string($table) ? $table : 'ordo_funnel_daily_stats'
        );

        $resourceModel = $this->createStub(AbstractDb::class);
        $resourceModel->method('getConnection')->willReturn($connection);
        $resourceModel->method('getIdFieldName')->willReturn('entity_id');

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($resourceConnection);
        $objectManager->method('create')->willReturn($resourceModel);
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

        return $collection;
    }

    /**
     * Regression: "date" alone is ambiguous once the live sales_order-derived join is in the
     * select (both main_table and that join alias have their own "date" column) - the grid's own
     * dateRange filter UI calls addFieldToFilter('date', ...) exactly like this, and would
     * otherwise hit a real SQLSTATE 23000 ambiguous-column error.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testAddFieldToFilterDateQualifiesToMainTable(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('prepareSqlCondition')
            ->with('main_table.date', ['eq' => '2026-11-05'])
            ->willReturn("main_table.date = '2026-11-05'");

        $collection = $this->buildCollection($connection);

        $result = $collection->addFieldToFilter('date', ['eq' => '2026-11-05']);

        self::assertSame($collection, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAddFieldToFilterOtherFieldDelegatesToParentWithoutQualifying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->expects(self::once())->method('prepareSqlCondition')
            ->with('product_view_visitors', ['gt' => 10])
            ->willReturn('product_view_visitors > 10');

        $collection = $this->buildCollection($connection);

        $result = $collection->addFieldToFilter('product_view_visitors', ['gt' => 10]);

        self::assertSame($collection, $result);
    }
}
