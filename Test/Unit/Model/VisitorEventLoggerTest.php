<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Model\VisitorAggregator;
use Ordo\Automation\Model\VisitorEventLogger;
use PHPUnit\Framework\TestCase;

class VisitorEventLoggerTest extends TestCase
{
    public function testLogInsertsRowAndAggregatesWhenCustomerKnown(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insert')->with(
            'ordo_visitor_event',
            self::callback(fn (array $row) => $row['visitor_id'] === 'v1' && $row['customer_id'] === 42)
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $aggregator = $this->createMock(VisitorAggregator::class);
        $aggregator->expects(self::once())->method('aggregateForCustomer')->with(42);

        $logger = new VisitorEventLogger($resourceConnection, $aggregator);
        $logger->log('v1', 'view_category', '15', 42);
    }

    public function testLogSkipsAggregationWhenCustomerUnknown(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $aggregator = $this->createMock(VisitorAggregator::class);
        $aggregator->expects(self::never())->method('aggregateForCustomer');

        $logger = new VisitorEventLogger($resourceConnection, $aggregator);
        $logger->log('v1', 'view_category', '15', null);
    }

    public function testAttributeVisitorToCustomerUpdatesAndAggregates(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('update')->with(
            'ordo_visitor_event',
            ['customer_id' => 42],
            ['visitor_id = ?' => 'v1', 'customer_id IS NULL']
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $aggregator = $this->createMock(VisitorAggregator::class);
        $aggregator->expects(self::once())->method('aggregateForCustomer')->with(42);

        $logger = new VisitorEventLogger($resourceConnection, $aggregator);
        $logger->attributeVisitorToCustomer('v1', 42);
    }
}
