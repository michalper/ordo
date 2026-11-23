<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\Queue\VisitorAggregationConsumer;
use Ordo\Automation\Model\VisitorAggregator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VisitorAggregationConsumerTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAggregatesForCustomerWhenCustomerIdPresent(): void
    {
        $aggregator = $this->createMock(VisitorAggregator::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $serializer->method('unserialize')->willReturnMap([['raw-message', ['customer_id' => 42]]]);

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

    /**
     * Regression: an undecodable message used to throw uncaught straight out of execute() -
     * must be logged and dropped instead, the same fail-closed shape this class's own docblock
     * already claimed but didn't actually implement.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenMessageIsUndecodable(): void
    {
        $aggregator = $this->createMock(VisitorAggregator::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $serializer->method('unserialize')->willThrowException(new \RuntimeException('malformed JSON'));

        $aggregator->expects(self::never())->method('aggregateForCustomer');
        $aggregator->expects(self::never())->method('aggregateForVisitor');
        $logger->expects(self::once())->method('error')->with(self::stringContains('malformed JSON'));

        (new VisitorAggregationConsumer($aggregator, $serializer, $logger))->execute('raw-message');
    }

    /**
     * Regression: a genuine aggregation failure (a DB hiccup) used to throw uncaught too - must
     * be logged and dropped, not crash the consumer.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenAggregationThrows(): void
    {
        $aggregator = $this->createMock(VisitorAggregator::class);
        $serializer = $this->createMock(SerializerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $serializer->method('unserialize')->willReturn(['customer_id' => 42]);
        $aggregator->method('aggregateForCustomer')->willThrowException(new \RuntimeException('db down'));
        $logger->expects(self::once())->method('error')->with(self::stringContains('db down'));

        (new VisitorAggregationConsumer($aggregator, $serializer, $logger))->execute('raw-message');
    }
}
