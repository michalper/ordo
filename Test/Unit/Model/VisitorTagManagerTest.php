<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Ordo\Automation\Model\VisitorTagManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class VisitorTagManagerTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
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

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->expects(self::once())->method('dispatch')
            ->with('ordo_visitor_tag_added', ['visitor_id' => 'v1', 'tag' => 'vip']);

        $manager = new VisitorTagManager($resourceConnection, $eventManager);
        $manager->addTag('v1', 'vip');
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

        $manager = new VisitorTagManager($resourceConnection, $eventManager);
        $manager->addTag('v1', 'vip');
    }

    public function testRemoveTagDeletes(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('delete')->with(
            'ordo_visitor_tag',
            ['visitor_id = ?' => 'v1', 'tag = ?' => 'vip']
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $manager = new VisitorTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        $manager->removeTag('v1', 'vip');
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

        $manager = new VisitorTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertTrue($manager->hasTag('v1', 'vip'));
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

        $manager = new VisitorTagManager($resourceConnection, $this->createStub(EventManagerInterface::class));
        self::assertSame(['vip', 'reorder'], $manager->getTags('v1'));
    }
}
