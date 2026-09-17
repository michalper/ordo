<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Push;

use Ordo\Automation\Model\Campaign\Action\SendRetrier;
use Ordo\Automation\Model\Push\Exception\SubscriptionGoneException;
use Ordo\Automation\Model\Push\PushSendRetryQueue;
use Ordo\Automation\Model\Push\PushSender;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use Ordo\Automation\Model\Push\PushSubscriptionSender;
use Ordo\Automation\Model\PushSubscription;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PushSubscriptionSenderTest extends TestCase
{
    private PushSender $pushSender;
    private PushSubscriptionManager $pushSubscriptionManager;
    private PushSendRetryQueue $pushSendRetryQueue;
    private MessageLogWriter $messageLogWriter;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->pushSender = $this->createMock(PushSender::class);
        $this->pushSubscriptionManager = $this->createMock(PushSubscriptionManager::class);
        $this->pushSendRetryQueue = $this->createMock(PushSendRetryQueue::class);
        $this->messageLogWriter = $this->createMock(MessageLogWriter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeSender(): PushSubscriptionSender
    {
        return new PushSubscriptionSender(
            $this->pushSender,
            $this->pushSubscriptionManager,
            $this->pushSendRetryQueue,
            $this->messageLogWriter,
            new SendRetrier(1),
            $this->logger
        );
    }

    private function subscription(string $endpoint, int $id = 5): PushSubscription
    {
        $subscription = $this->createStub(PushSubscription::class);
        $subscription->method('getEndpoint')->willReturn($endpoint);
        $subscription->method('getEntityId')->willReturn($id);
        return $subscription;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendRecordsSentOnSuccess(): void
    {
        $subscription = $this->subscription('https://push.example.com/a');
        $this->pushSender->expects(self::once())->method('send')->with($subscription, 'payload');

        $this->messageLogWriter->expects(self::once())->method('recordSent')
            ->with('push', 42, 'https://push.example.com/a', null, 7, 'b');
        $this->messageLogWriter->expects(self::never())->method('recordFailed');
        $this->pushSendRetryQueue->expects(self::never())->method('enqueue');

        $this->makeSender()->send($subscription, 'payload', 42, 7, 'b');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendRetriesATransientFailureAndSucceeds(): void
    {
        $subscription = $this->subscription('https://push.example.com/a');
        $this->pushSender->expects(self::exactly(2))->method('send')->willReturnCallback(
            function () {
                static $calls = 0;
                $calls++;
                if ($calls < 2) {
                    throw new \RuntimeException('transient push service timeout');
                }
            }
        );

        $this->messageLogWriter->expects(self::once())->method('recordSent');
        $this->messageLogWriter->expects(self::never())->method('recordFailed');
        $this->pushSendRetryQueue->expects(self::never())->method('enqueue');

        $this->makeSender()->send($subscription, 'payload', 42, null, null);
    }

    /**
     * Regression test: a gone/dead subscription is permanently invalid, so it must fail fast on
     * the first attempt, not burn through every retry on an outcome that can never change - and
     * must never be enqueued for a persisted retry either.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testSendDeletesSubscriptionAndRecordsFailedWhenGone(): void
    {
        $subscription = $this->subscription('https://push.example.com/dead');
        $this->pushSender->expects(self::once())->method('send')
            ->willThrowException(new SubscriptionGoneException('gone'));

        $this->pushSubscriptionManager->expects(self::once())->method('delete')->with($subscription);
        $this->messageLogWriter->expects(self::once())->method('recordFailed')
            ->with('push', 42, 'https://push.example.com/dead', null, null);
        $this->pushSendRetryQueue->expects(self::never())->method('enqueue');

        $this->makeSender()->send($subscription, 'payload', 42, null, null);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendDeletesSubscriptionWhenGoneOnARetryAttemptWithoutRethrowing(): void
    {
        $subscription = $this->subscription('https://push.example.com/dead');
        $this->pushSender->method('send')->willThrowException(new SubscriptionGoneException('gone'));

        $this->pushSubscriptionManager->expects(self::once())->method('delete')->with($subscription);

        $this->makeSender()->send($subscription, 'payload', 42, null, null, isRetryAttempt: true);

        self::assertTrue(true, 'must not rethrow - a gone subscription on retry is a permanent stop, not a failure');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendEnqueuesRetryAndSwallowsOnTransientFailureWhenNotARetryAttempt(): void
    {
        $subscription = $this->subscription('https://push.example.com/a', 9);
        $this->pushSender->method('send')->willThrowException(new \RuntimeException('push service down'));

        $this->pushSubscriptionManager->expects(self::never())->method('delete');
        $this->logger->expects(self::once())->method('error');
        $this->messageLogWriter->expects(self::once())->method('recordFailed')
            ->with('push', 42, 'https://push.example.com/a', 7, 'b');
        $this->pushSendRetryQueue->expects(self::once())->method('enqueue')
            ->with(9, 42, 7, 'b', 'payload', self::isInstanceOf(\RuntimeException::class));

        $this->makeSender()->send($subscription, 'payload', 42, 7, 'b');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSendRethrowsOnTransientFailureWhenIsARetryAttemptInsteadOfEnqueuing(): void
    {
        $subscription = $this->subscription('https://push.example.com/a');
        $this->pushSender->method('send')->willThrowException(new \RuntimeException('push service down'));

        $this->messageLogWriter->expects(self::once())->method('recordFailed');
        $this->pushSendRetryQueue->expects(self::never())->method('enqueue');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('push service down');

        $this->makeSender()->send($subscription, 'payload', 42, null, null, isRetryAttempt: true);
    }
}
