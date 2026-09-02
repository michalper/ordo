<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\Queue\SegmentBulkActionPublisher;
use PHPUnit\Framework\TestCase;

class SegmentBulkActionPublisherTest extends TestCase
{
    public function testPublishSerializesSegmentActionAndCustomerIdsOntoTheTopic(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $serializer = $this->createMock(SerializerInterface::class);

        $expectedPayload = [
            'segment_id' => 5,
            'action_type' => 'add_tag',
            'params' => ['tag' => 'vip'],
            'customer_ids' => [1, 2, 3],
        ];

        $serializer->expects(self::once())
            ->method('serialize')
            ->with($expectedPayload)
            ->willReturn('{"encoded":true}');

        $publisher->expects(self::once())
            ->method('publish')
            ->with(SegmentBulkActionPublisher::TOPIC, '{"encoded":true}');

        (new SegmentBulkActionPublisher($publisher, $serializer))
            ->publish(5, 'add_tag', ['tag' => 'vip'], [1, 2, 3]);
    }
}
