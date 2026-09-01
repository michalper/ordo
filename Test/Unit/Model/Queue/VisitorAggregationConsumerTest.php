<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\Queue\VisitorAggregationConsumer;
use Ordo\Automation\Model\VisitorAggregator;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class VisitorAggregationConsumerTest extends TestCase
{
    public function testExecuteAggregatesForCustomerWhenCustomerIdPresent(): void
    {
        $aggregator = $this->createMock(VisitorAggregator::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $serializer->method('unserialize')->with('raw-message')->willReturn(['customer_id' => 42]);

        $aggregator->expects(self::once())->method('aggregateForCustomer')->with(42);
        $aggregator->expects(self::never())->method('aggregateForVisitor');
        $logger->expects(self::never())->method('error');

        (new VisitorAggregationConsumer($aggregator, $serializer, $logger))->execute('raw-message');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAggregatesForVisitorWhenVisitorIdPresent(): void
    {
        $aggregator = $this->createMock(VisitorAggregator::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $serializer->method('unserialize')->willReturn(['visitor_id' => 'v1']);

        $aggregator->expects(self::once())->method('aggregateForVisitor')->with('v1');
        $aggregator->expects(self::never())->method('aggregateForCustomer');
        $logger->expects(self::never())->method('error');

        (new VisitorAggregationConsumer($aggregator, $serializer, $logger))->execute('raw-message');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenNeitherIdentifierPresent(): void
    {
        $aggregator = $this->createMock(VisitorAggregator::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $serializer->method('unserialize')->willReturn([]);

        $aggregator->expects(self::never())->method('aggregateForCustomer');
        $aggregator->expects(self::never())->method('aggregateForVisitor');
        $logger->expects(self::once())->method('error');

        (new VisitorAggregationConsumer($aggregator, $serializer, $logger))->execute('raw-message');
    }
}
