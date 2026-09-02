<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\ScoreRule\Grid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Grid\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Same ObjectManager-singleton technique as Campaign\Grid\CollectionTest — SearchResult
 * resolves its ResourceConnection through the global instance during construction.
 */
class CollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    /**
     * ordo_score_rule has no "name" column, unlike every other entity
     * AbstractEntityActionsColumn::prepareDataSource() renders a delete-confirm for — this is
     * what makes the delete confirm text actually work instead of reading an undefined array key.
     */
    public function testInitSelectAliasesAttributeCodeAsName(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects(self::once())->method('columns')->with(['name' => 'attribute_code'])
            ->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);

        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('ordo_score_rule');
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $resource->method('getTable')->willReturnCallback(fn (string $table) => $table);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            fn ($table) => is_string($table) ? $table : 'ordo_score_rule'
        );

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturn($resource);
        $objectManager->method('get')->with(ResourceConnection::class)->willReturn($resourceConnection);
        ObjectManager::setInstance($objectManager);

        new Collection(
            $this->createStub(EntityFactoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FetchStrategyInterface::class),
            $this->createStub(ManagerInterface::class),
            'ordo_score_rule',
            \Ordo\Automation\Model\ResourceModel\ScoreRule::class
        );
    }
}
