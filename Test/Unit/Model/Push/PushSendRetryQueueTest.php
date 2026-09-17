<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Push;

use Ordo\Automation\Model\Push\PushSendRetryQueue;
use Ordo\Automation\Model\PushSendRetry;
use Ordo\Automation\Model\PushSendRetryFactory;
use Ordo\Automation\Model\ResourceModel\PushSendRetry as PushSendRetryResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PushSendRetryQueueTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testEnqueuePersistsANewRetryRowAtAttemptOne(): void
    {
        $retry = $this->createMock(PushSendRetry::class);
        $retry->expects(self::once())->method('setSubscriptionId')->with(9);
        $retry->expects(self::once())->method('setCustomerId')->with(42);
        $retry->expects(self::once())->method('setCampaignId')->with(7);
        $retry->expects(self::once())->method('setVariant')->with('b');
        $retry->expects(self::once())->method('setPayload')->with('{"title":"Hi"}');
        $retry->expects(self::once())->method('setAttempts')->with(1);
        $retry->expects(self::once())->method('setLastError')->with('boom');
        $retry->expects(self::once())->method('setNextRetryAt')->with(self::callback('is_string'));

        $factory = $this->createStub(PushSendRetryFactory::class);
        $factory->method('create')->willReturn($retry);
        $resource = $this->createMock(PushSendRetryResource::class);
        $resource->expects(self::once())->method('save')->with($retry);

        $queue = new PushSendRetryQueue($factory, $resource, $this->createStub(LoggerInterface::class));
        $queue->enqueue(9, 42, 7, 'b', '{"title":"Hi"}', new \RuntimeException('boom'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEnqueueSwallowsAndLogsWhenPersistingFails(): void
    {
        $factory = $this->createStub(PushSendRetryFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('db down'));
        $resource = $this->createStub(PushSendRetryResource::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $queue = new PushSendRetryQueue($factory, $resource, $logger);

        // Must not throw - a failure enqueuing the retry can't be allowed to crash the calling
        // PushSubscriptionSender.
        $queue->enqueue(9, 42, null, null, 'payload', new \RuntimeException('boom'));
    }

    public function testNextRetryAtBacksOffExponentiallyAndCaps(): void
    {
        $factory = $this->createStub(PushSendRetryFactory::class);
        $resource = $this->createStub(PushSendRetryResource::class);
        $queue = new PushSendRetryQueue($factory, $resource, $this->createStub(LoggerInterface::class));

        $attempt1 = strtotime($queue->nextRetryAt(1));
        $attempt2 = strtotime($queue->nextRetryAt(2));
        $attempt10 = strtotime($queue->nextRetryAt(10));

        $now = time();
        self::assertEqualsWithDelta($now + 5 * 60, $attempt1, 5);
        self::assertEqualsWithDelta($now + 10 * 60, $attempt2, 5);
        // Far enough out that the exponential curve would exceed the cap without it.
        self::assertEqualsWithDelta($now + 120 * 60, $attempt10, 5);
    }
}
