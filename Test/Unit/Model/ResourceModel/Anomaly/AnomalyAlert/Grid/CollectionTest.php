<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Anomaly\AnomalyAlert\Grid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\ResourceModel\Anomaly\AnomalyAlert\Grid\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Same ObjectManager-singleton-stubbing technique FunnelReport/Grid/CollectionTest already
 * establishes for a SearchResult-based grid collection - see that test's own docblock for why.
 */
class CollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInitSelectJoinsTheCampaignTableForDisplayName(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->expects(self::once())->method('joinLeft')
            ->with(
                ['campaign' => 'ordo_campaign'],
                'campaign.entity_id = main_table.campaign_id',
                ['campaign_name' => 'campaign.name']
            )
            ->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            static fn ($table) => is_string($table) ? $table : 'ordo_anomaly_alert'
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
    }
}
