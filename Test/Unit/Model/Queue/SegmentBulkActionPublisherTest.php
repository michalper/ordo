<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\Queue\SegmentBulkActionPublisher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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

    #[AllowMockObjectsWithoutExpectations]
    public function testPublishSplitsLargeCustomerListsIntoChunkSizedMessages(): void
    {
        $publisherInterface = $this->createMock(PublisherInterface::class);
        $serializer = $this->createStub(SerializerInterface::class);

        $customerIds = range(1, SegmentBulkActionPublisher::CHUNK_SIZE + 1);

        $seenChunkSizes = [];
        $serializer->method('serialize')->willReturnCallback(
            function (array $payload) use (&$seenChunkSizes): string {
                $seenChunkSizes[] = count($payload['customer_ids']);
                return 'encoded-' . count($seenChunkSizes);
            }
        );

        $publisherInterface->expects(self::exactly(2))->method('publish')
            ->with(SegmentBulkActionPublisher::TOPIC, self::isString());

        (new SegmentBulkActionPublisher($publisherInterface, $serializer))
            ->publish(5, 'add_tag', ['tag' => 'vip'], $customerIds);

        self::assertSame([SegmentBulkActionPublisher::CHUNK_SIZE, 1], $seenChunkSizes);
    }
}
