<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\ConversationMessage\Grid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\ResourceModel\ConversationMessage\Grid\Collection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Same ObjectManager-singleton-stubbing technique MessageLog\Grid\CollectionTest already
 * establishes for a SearchResult-based grid collection - see that test's own docblock for why.
 */
class CollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testInitSelectLeftJoinsCustomerNameAndEmail(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects(self::once())->method('joinLeft')->with(
            ['customer' => 'customer_entity'],
            'customer.entity_id = main_table.customer_id',
            self::callback(fn (array $cols) => isset($cols['customer_name'], $cols['customer_email'])
                && (string) $cols['customer_name'] === "CONCAT(customer.firstname, ' ', customer.lastname)"
                && $cols['customer_email'] === 'customer.email')
        )->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);

        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('ordo_conversation_message');
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $resource->method('getTable')->willReturnCallback(fn (string $table) => $table);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            fn ($table) => is_string($table) ? $table : 'ordo_conversation_message'
        );

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturn($resource);
        $objectManager->method('get')->willReturnMap([[ResourceConnection::class, $resourceConnection]]);
        ObjectManager::setInstance($objectManager);

        new Collection(
            $this->createStub(EntityFactoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FetchStrategyInterface::class),
            $this->createStub(ManagerInterface::class),
            $resourceConnection
        );
    }
}
