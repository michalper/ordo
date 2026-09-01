<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Magento\Framework\DB\Select;
use Ordo\Automation\Model\ResourceModel\OrderApproval;

class OrderApprovalTest extends AbstractDbTestCase
{
    public function testInitializesWithOrderApprovalTableAndEntityIdField(): void
    {
        $resource = new OrderApproval($this->makeDbContext());

        self::assertSame('ordo_order_approval', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }

    public function testLoadByTokenDoesNothingWhenTokenNotFound(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn(false);

        $resource = $this->getMockBuilder(OrderApproval::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection', 'load'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);
        $resource->expects(self::never())->method('load');

        $model = $this->createStub(\Ordo\Automation\Model\OrderApproval::class);
        $resource->loadByToken($model, 'no-such-token');
    }

    public function testLoadByTokenLoadsModelWhenTokenFound(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn('7');

        $resource = $this->getMockBuilder(OrderApproval::class)
            ->setConstructorArgs([$this->makeDbContext()])
            ->onlyMethods(['getConnection', 'load'])
            ->getMock();
        $resource->method('getConnection')->willReturn($connection);

        $model = $this->createStub(\Ordo\Automation\Model\OrderApproval::class);
        $resource->expects(self::once())->method('load')->with($model, 7);

        $resource->loadByToken($model, 'real-token');
    }
}
