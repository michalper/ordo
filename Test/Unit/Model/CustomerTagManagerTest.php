<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Ordo\Automation\Model\CustomerTagManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CustomerTagManagerTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('having')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    public function testAddTagInsertsAndDispatchesWhenNotAlreadyTagged(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(0);
        $connection->expects(self::once())->method('insert');

        $resourceConnection = $this->createStub(ResourceConnection::class);
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

        $resourceConnection = $this->createStub(ResourceConnection::class);
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

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        $manager->removeTag(42, 'vip');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasTagReturnsTrueWhenCountPositive(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(2);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertTrue($manager->hasTag(42, 'vip'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTagsReturnsColumn(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['vip', 'reorder']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertSame(['vip', 'reorder'], $manager->getTags(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCustomerIdsWithTagReturnsIntArray(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['1', '2']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertSame([1, 2], $manager->getCustomerIdsWithTag('vip'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCustomerIdsWithTagFromSetReturnsOnlyTaggedSubset(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['2']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertSame([2], $manager->getCustomerIdsWithTagFromSet([1, 2, 3], 'inactive'));
    }

    public function testGetCustomerIdsWithTagFromSetReturnsEmptyArrayForEmptyInputWithoutQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertSame([], $manager->getCustomerIdsWithTagFromSet([], 'inactive'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasAnyTagReturnsTrueWhenCountPositive(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(1);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertTrue($manager->hasAnyTag(42, ['vip', 'loyal']));
    }

    public function testHasAnyTagReturnsFalseForEmptyTagsWithoutQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertFalse($manager->hasAnyTag(42, []));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasAllTagsReturnsTrueWhenEveryTagMatched(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(2);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertTrue($manager->hasAllTags(42, ['vip', 'loyal']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasAllTagsReturnsFalseWhenOnlySomeTagsMatched(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchOne')->willReturn(1);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertFalse($manager->hasAllTags(42, ['vip', 'loyal']));
    }

    public function testHasAllTagsReturnsFalseForEmptyTagsWithoutQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertFalse($manager->hasAllTags(42, []));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCustomerIdsWithAnyTagReturnsIntArray(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['1', '2']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertSame([1, 2], $manager->getCustomerIdsWithAnyTag(['vip', 'loyal']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCustomerIdsWithAllTagsReturnsIntArray(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['2']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertSame([2], $manager->getCustomerIdsWithAllTags(['vip', 'loyal']));
    }

    public function testGetCustomerIdsWithAnyTagReturnsEmptyArrayForEmptyTagsWithoutQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertSame([], $manager->getCustomerIdsWithAnyTag([]));
    }

    public function testGetCustomerIdsWithAllTagsReturnsEmptyArrayForEmptyTagsWithoutQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertSame([], $manager->getCustomerIdsWithAllTags([]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSearchTagsReturnsColumn(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchCol')->willReturn(['vip', 'vip-gold']);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertSame(['vip', 'vip-gold'], $manager->searchTags('vip'));
    }

    public function testSearchTagsReturnsEmptyArrayForEmptyTermWithoutQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);

        $manager = new CustomerTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertSame([], $manager->searchTags(''));
    }
}
