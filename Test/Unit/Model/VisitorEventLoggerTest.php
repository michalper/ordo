<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Model\Queue\VisitorAggregationPublisher;
use Ordo\Automation\Model\VisitorEventLogger;
use PHPUnit\Framework\TestCase;

class VisitorEventLoggerTest extends TestCase
{
    public function testLogInsertsRowAndPublishesForCustomerWhenCustomerKnown(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insert')->with(
            'ordo_visitor_event',
            self::callback(fn (array $row) => $row['visitor_id'] === 'v1' && $row['customer_id'] === 42)
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $publisher = $this->createMock(VisitorAggregationPublisher::class);
        $publisher->expects(self::once())->method('publishForCustomer')->with(42);
        $publisher->expects(self::never())->method('publishForVisitor');

        $logger = new VisitorEventLogger($resourceConnection, $publisher);
        $logger->log('v1', 'view_category', '15', 42);
    }

    public function testLogPublishesForVisitorWhenCustomerUnknown(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $publisher = $this->createMock(VisitorAggregationPublisher::class);
        $publisher->expects(self::never())->method('publishForCustomer');
        $publisher->expects(self::once())->method('publishForVisitor')->with('v1');

        $logger = new VisitorEventLogger($resourceConnection, $publisher);
        $logger->log('v1', 'view_category', '15', null);
    }

    public function testAttributeVisitorToCustomerUpdatesAndPublishesForCustomer(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('update')->with(
            'ordo_visitor_event',
            ['customer_id' => 42],
            ['visitor_id = ?' => 'v1', 'customer_id IS NULL']
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $publisher = $this->createMock(VisitorAggregationPublisher::class);
        $publisher->expects(self::once())->method('publishForCustomer')->with(42);

        $logger = new VisitorEventLogger($resourceConnection, $publisher);
        $logger->attributeVisitorToCustomer('v1', 42);
    }
}
