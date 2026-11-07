<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\CohortRetention\Grid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\ResourceModel\CohortRetention\Grid\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Same ObjectManager-singleton-stubbing technique Campaign\Grid\CollectionTest/MessageLog\Grid\
 * CollectionTest already establish for a SearchResult-based grid collection (see either test's
 * own docblock for why) - here every select()/from()/where()/group()/joinInner()/columns()/
 * distinct() call shares one generic chainable Select mock, since this Collection's own SQL
 * (nested derived-table subqueries, a MySQL window function) is only meaningfully verified
 * against a real database - CohortRetentionGridCollectionTest (Test/Integration) does that.
 * What a unit test CAN usefully prove without a real connection is addFieldToFilter()'s own
 * branching: does the acquisition-campaign-narrowing path actually run (traced here via
 * quoteIdentifier(), the one call unique to narrowToAcquisitionCampaign()) for a real campaign
 * id and NOT for a zero/negative one, and does every other field name still delegate to the
 * parent's own generic filter handling.
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
        $select->method('joinInner')->willReturnSelf();
        $select->method('columns')->willReturnSelf();
        $select->method('distinct')->willReturnSelf();
        $select->method('assemble')->willReturn('SELECT 1');

        $connection->method('select')->willReturn($select);
        $connection->method('quoteIdentifier')->willReturnArgument(0);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            static fn ($table) => is_string($table) ? $table : 'sales_order'
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
        // _initSelect() runs lazily on first getSelect(), same as every other SearchResult-based
        // Collection test in this module.
        $collection->getSelect();

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUnfilteredConstructionNeverQueriesCampaignAttribution(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('quoteIdentifier');

        $this->buildCollection($connection);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAddFieldToFilterAcquisitionCampaignIdNarrowsViaCampaignAttributionTable(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('quoteIdentifier')->with('ordo_campaign_attribution');

        $collection = $this->buildCollection($connection);
        $result = $collection->addFieldToFilter('acquisition_campaign_id', 42);

        self::assertSame($collection, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAddFieldToFilterAcquisitionCampaignIdReadsEqConditionFromArray(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('quoteIdentifier')->with('ordo_campaign_attribution');

        $collection = $this->buildCollection($connection);
        $collection->addFieldToFilter('acquisition_campaign_id', ['eq' => 42]);
    }

    /**
     * A zero/negative campaign id (e.g. an unset UI filter resolving to 0) must behave exactly
     * like no filter at all, not attempt to narrow by a nonexistent campaign - this is the same
     * "no filter = unchanged" guarantee buildCohortSelect()'s own docblock promises.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testAddFieldToFilterAcquisitionCampaignIdZeroDoesNotNarrow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('quoteIdentifier');

        $collection = $this->buildCollection($connection);
        $collection->addFieldToFilter('acquisition_campaign_id', 0);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAddFieldToFilterOtherFieldDelegatesToParentWithoutNarrowing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        // The parent's own generic filter handling quotes the plain field name itself - this
        // only needs to prove narrowToAcquisitionCampaign() specifically never ran, i.e. the
        // attribution table name is never the thing quoted.
        $connection->method('quoteIdentifier')->willReturnCallback(function (string $identifier) {
            self::assertNotSame('ordo_campaign_attribution', $identifier);
            return $identifier;
        });

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('columns')->willReturnSelf();
        $select->method('distinct')->willReturnSelf();
        $select->method('assemble')->willReturn('SELECT 1');
        $connection->method('select')->willReturn($select);
        $connection->method('prepareSqlCondition')->willReturn('cohort_month = \'2026-01-01\'');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            static fn ($table) => is_string($table) ? $table : 'sales_order'
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
        $collection->getSelect();

        $result = $collection->addFieldToFilter('cohort_month', ['eq' => '2026-01-01']);

        self::assertSame($collection, $result);
    }
}
