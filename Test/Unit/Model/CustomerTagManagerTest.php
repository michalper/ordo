<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Ordo\Automation\Model\CustomerTagManager;
use PHPUnit\Framework\TestCase;

class CustomerTagManagerTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    public function testAddTagInsertsAndDispatchesWhenNotAlreadyTagged(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(0);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->expects(self::once())->method('dispatch')
            ->with('ordo_customer_tag_added', ['customer_id' => 42, 'tag' => 'vip']);

        $manager = new CustomerTagManager($resourceConnection, $eventManager);
        $manager->addTag(42, 'vip');
    }

    public function testAddTagSkipsWhenAlreadyTagged(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(1);
        $connection->expects(self::never())->method('insert');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->expects(self::never())->method('dispatch');

        $manager = new CustomerTagManager($resourceConnection, $eventManager);
        $manager->addTag(42, 'vip');
    }

    public function testRemoveTagDeletes(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('delete')->with(
            'ordo_customer_tag',
            ['customer_id = ?' => 42, 'tag = ?' => 'vip']
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createMock(EventManagerInterface::class));
        $manager->removeTag(42, 'vip');
    }

    public function testHasTagReturnsTrueWhenCountPositive(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(2);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createMock(EventManagerInterface::class));
        self::assertTrue($manager->hasTag(42, 'vip'));
    }

    public function testGetTagsReturnsColumn(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['vip', 'reorder']);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createMock(EventManagerInterface::class));
        self::assertSame(['vip', 'reorder'], $manager->getTags(42));
    }

    public function testGetCustomerIdsWithTagReturnsIntArray(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['1', '2']);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createMock(EventManagerInterface::class));
        self::assertSame([1, 2], $manager->getCustomerIdsWithTag('vip'));
    }
}
