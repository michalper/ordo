<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\MessageSendRetry;
use Ordo\Automation\Model\MessageSendRetryFactory;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry as MessageSendRetryResource;
use Ordo\Automation\Model\Retry\RetryBackoff;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageSendRetryQueueTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testEnqueuePersistsANewRetryRowAtAttemptOne(): void
    {
        $retry = $this->createMock(MessageSendRetry::class);
        $retry->expects(self::once())->method('setActionType')->with('send_email');
        $retry->expects(self::once())->method('setContext')->with(['customer_id' => 1]);
        $retry->expects(self::once())->method('setParams')->with(['template' => 'ordo_campaign_generic']);
        $retry->expects(self::once())->method('setAttempts')->with(1);
        $retry->expects(self::once())->method('setLastError')->with('boom');
        $retry->expects(self::once())->method('setNextRetryAt')->with(self::callback('is_string'));

        $factory = $this->createStub(MessageSendRetryFactory::class);
        $factory->method('create')->willReturn($retry);
        $resource = $this->createMock(MessageSendRetryResource::class);
        $resource->expects(self::once())->method('save')->with($retry);

        $queue = new MessageSendRetryQueue(
            $factory,
            $resource,
            $this->createStub(LoggerInterface::class),
            new RetryBackoff()
        );
        $queue->enqueue(
            'send_email',
            ['customer_id' => 1],
            ['template' => 'ordo_campaign_generic'],
            new \RuntimeException('boom')
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEnqueueSwallowsAndLogsWhenPersistingFails(): void
    {
        $factory = $this->createStub(MessageSendRetryFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('db down'));
        $resource = $this->createStub(MessageSendRetryResource::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $queue = new MessageSendRetryQueue($factory, $resource, $logger, new RetryBackoff());

        // Must not throw - a failure enqueuing the retry can't be allowed to crash the calling
        // Send{Email,Sms,WhatsApp} action.
        $queue->enqueue('send_email', [], [], new \RuntimeException('boom'));
    }

    public function testEnqueueOrRethrowPersistsARetryRowOnTheOriginalDispatchPath(): void
    {
        $retry = $this->createStub(MessageSendRetry::class);
        $factory = $this->createStub(MessageSendRetryFactory::class);
        $factory->method('create')->willReturn($retry);

        $resource = $this->createMock(MessageSendRetryResource::class);
        $resource->expects(self::once())->method('save')->with($retry);

        $queue = new MessageSendRetryQueue(
            $factory,
            $resource,
            $this->createStub(LoggerInterface::class),
            new RetryBackoff()
        );

        $queue->enqueueOrRethrow('send_email', ['customer_id' => 42], [], new \RuntimeException('smtp down'));
    }

    public function testEnqueueOrRethrowRethrowsTheOriginalErrorOnARetryAttempt(): void
    {
        $factory = $this->createStub(MessageSendRetryFactory::class);
        $resource = $this->createMock(MessageSendRetryResource::class);
        $resource->expects(self::never())->method('save');

        $queue = new MessageSendRetryQueue(
            $factory,
            $resource,
            $this->createStub(LoggerInterface::class),
            new RetryBackoff()
        );

        $error = new \RuntimeException('smtp down');
        $context = ['customer_id' => 42, MessageSendRetryQueue::RETRY_CONTEXT_FLAG => true];

        $this->expectExceptionObject($error);
        $queue->enqueueOrRethrow('send_email', $context, [], $error);
    }

    public function testNextRetryAtBacksOffExponentiallyAndCaps(): void
    {
        $factory = $this->createStub(MessageSendRetryFactory::class);
        $resource = $this->createStub(MessageSendRetryResource::class);
        $queue = new MessageSendRetryQueue(
            $factory,
            $resource,
            $this->createStub(LoggerInterface::class),
            new RetryBackoff()
        );

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
