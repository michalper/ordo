<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\Queue\VisitorAggregationPublisher;
use PHPUnit\Framework\TestCase;

class VisitorAggregationPublisherTest extends TestCase
{
    public function testPublishForCustomerSerializesCustomerIdOntoTheTopic(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);

        $serializer->expects(self::once())->method('serialize')->with(['customer_id' => 42])
            ->willReturn('{"customer_id":42}');
        $publisher->expects(self::once())->method('publish')
            ->with(VisitorAggregationPublisher::TOPIC, '{"customer_id":42}');

        (new VisitorAggregationPublisher($publisher, $serializer))->publishForCustomer(42);
    }

    public function testPublishForVisitorSerializesVisitorIdOntoTheTopic(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);

        $serializer->expects(self::once())->method('serialize')->with(['visitor_id' => 'v1'])
            ->willReturn('{"visitor_id":"v1"}');
        $publisher->expects(self::once())->method('publish')
            ->with(VisitorAggregationPublisher::TOPIC, '{"visitor_id":"v1"}');

        (new VisitorAggregationPublisher($publisher, $serializer))->publishForVisitor('v1');
    }
}
