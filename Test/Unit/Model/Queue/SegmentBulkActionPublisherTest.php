<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Queue;

use Magento\Framework\DataObject\IdentityGeneratorInterface;
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
        $identityGenerator = $this->createStub(IdentityGeneratorInterface::class);
        $identityGenerator->method('generateId')->willReturn('batch-uuid-1');

        $expectedPayload = [
            'segment_id' => 5,
            'action_type' => 'add_tag',
            'params' => ['tag' => 'vip'],
            'customer_ids' => [1, 2, 3],
            'batch_id' => 'batch-uuid-1',
        ];

        $serializer->expects(self::once())
            ->method('serialize')
            ->with($expectedPayload)
            ->willReturn('{"encoded":true}');

        $publisher->expects(self::once())
            ->method('publish')
            ->with(SegmentBulkActionPublisher::TOPIC, '{"encoded":true}');

        (new SegmentBulkActionPublisher($publisher, $serializer, $identityGenerator))
            ->publish(5, 'add_tag', ['tag' => 'vip'], [1, 2, 3]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testPublishSplitsLargeCustomerListsIntoChunkSizedMessages(): void
    {
        $publisherInterface = $this->createMock(PublisherInterface::class);
        $serializer = $this->createStub(SerializerInterface::class);
        $identityGenerator = $this->createStub(IdentityGeneratorInterface::class);
        $identityGenerator->method('generateId')->willReturn('batch-uuid');

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

        (new SegmentBulkActionPublisher($publisherInterface, $serializer, $identityGenerator))
            ->publish(5, 'add_tag', ['tag' => 'vip'], $customerIds);

        self::assertSame([SegmentBulkActionPublisher::CHUNK_SIZE, 1], $seenChunkSizes);
    }

    /**
     * Regression: each chunk must get its own, distinct batch_id - a shared id across chunks
     * would let SegmentBulkActionClaimStore's (batch_id, customer_id) claim collide between two
     * different chunks that happen to both contain the same customer_id (impossible here since
     * chunks partition array_chunk()'s own input, but the id must still be fresh per chunk, not
     * reused, so a future change to how chunks are built can't silently reintroduce that).
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testPublishGeneratesADistinctBatchIdPerChunk(): void
    {
        $publisherInterface = $this->createStub(PublisherInterface::class);
        $serializer = $this->createStub(SerializerInterface::class);
        $identityGenerator = $this->createMock(IdentityGeneratorInterface::class);
        $identityGenerator->expects(self::exactly(2))->method('generateId')
            ->willReturnOnConsecutiveCalls('batch-uuid-1', 'batch-uuid-2');

        $seenBatchIds = [];
        $serializer->method('serialize')->willReturnCallback(
            function (array $payload) use (&$seenBatchIds): string {
                $seenBatchIds[] = $payload['batch_id'];
                return 'encoded';
            }
        );

        $customerIds = range(1, SegmentBulkActionPublisher::CHUNK_SIZE + 1);

        (new SegmentBulkActionPublisher($publisherInterface, $serializer, $identityGenerator))
            ->publish(5, 'add_tag', ['tag' => 'vip'], $customerIds);

        self::assertSame(['batch-uuid-1', 'batch-uuid-2'], $seenBatchIds);
    }
}
