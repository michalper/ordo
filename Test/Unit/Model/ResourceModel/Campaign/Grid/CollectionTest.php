<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Campaign\Grid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\ResourceModel\Campaign\Grid\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unlike every other Collection in this module, SearchResult (Grid\Collection's real parent,
 * not AbstractCollection) takes its resource model as a class-name string, not an injected
 * instance — getResource() falls back to ObjectManager::getInstance()->create(...) the first
 * time it's needed, which _initSelect() triggers immediately via getSelect(). Stubbing the
 * global ObjectManager singleton for the duration of this one test (and restoring it in
 * tearDown) is the only way to control what that create() call returns without a real DI
 * container — the same technique Magento core's own SearchResult-based grid collection tests
 * use.
 */
class CollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createMock(ObjectManagerInterface::class));
    }

    public function testInitSelectJoinsTriggerEventsAndGroupsByEntityId(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects(self::once())->method('joinLeft')->with(
            ['ordo_campaign_trigger' => 'ordo_campaign_trigger'],
            'ordo_campaign_trigger.campaign_id = main_table.entity_id',
            self::isType('array')
        )->willReturnSelf();
        $select->expects(self::once())->method('group')->with('main_table.entity_id')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);

        $resource = $this->createMock(AbstractDb::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('ordo_campaign');
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $resource->method('getTable')->willReturnCallback(fn (string $table) => $table);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        // SearchResult::__construct() calls setMainTable(true) once before the real table name,
        // so this stub must tolerate a non-string first call, not just the later real one.
        $resourceConnection->method('getTableName')->willReturnCallback(
            fn ($table) => is_string($table) ? $table : 'ordo_campaign'
        );

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturn($resource);
        $objectManager->method('get')->with(ResourceConnection::class)->willReturn($resourceConnection);
        ObjectManager::setInstance($objectManager);

        new Collection(
            $this->createMock(EntityFactoryInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FetchStrategyInterface::class),
            $this->createMock(ManagerInterface::class),
            'ordo_campaign',
            \Ordo\Automation\Model\ResourceModel\Campaign::class
        );
    }
}
