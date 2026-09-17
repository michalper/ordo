<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\RetryFailedPushSends;
use Ordo\Automation\Model\Push\PushSendRetryQueue;
use Ordo\Automation\Model\Push\PushSubscriptionSender;
use Ordo\Automation\Model\PushSendRetry;
use Ordo\Automation\Model\PushSubscription;
use Ordo\Automation\Model\PushSubscriptionFactory;
use Ordo\Automation\Model\ResourceModel\PushSendRetry as PushSendRetryResource;
use Ordo\Automation\Model\ResourceModel\PushSendRetry\Collection as PushSendRetryCollection;
use Ordo\Automation\Model\ResourceModel\PushSendRetry\CollectionFactory as PushSendRetryCollectionFactory;
use Ordo\Automation\Model\ResourceModel\PushSubscription as PushSubscriptionResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetryFailedPushSendsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private PushSendRetryCollectionFactory $collectionFactory;
    private PushSendRetryResource $resource;
    private PushSubscriptionFactory $pushSubscriptionFactory;
    private PushSubscriptionResource $pushSubscriptionResource;
    private PushSubscriptionSender $pushSubscriptionSender;
    private PushSendRetryQueue $pushSendRetryQueue;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createStub(PushSendRetryCollectionFactory::class);
        $this->resource = $this->createMock(PushSendRetryResource::class);
        $this->pushSubscriptionFactory = $this->createStub(PushSubscriptionFactory::class);
        $this->pushSubscriptionResource = $this->createMock(PushSubscriptionResource::class);
        $this->pushSubscriptionSender = $this->createMock(PushSubscriptionSender::class);
        $this->pushSendRetryQueue = $this->createStub(PushSendRetryQueue::class);
        $this->pushSendRetryQueue->method('nextRetryAt')->willReturn('2024-01-01 00:05:00');
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeCron(): RetryFailedPushSends
    {
        return new RetryFailedPushSends(
            $this->collectionFactory,
            $this->resource,
            $this->pushSubscriptionFactory,
            $this->pushSubscriptionResource,
            $this->pushSubscriptionSender,
            $this->pushSendRetryQueue,
            $this->makeCronRunLogger($this->logger)
        );
    }

    private function makeRetry(int $attempts, int $subscriptionId = 9): PushSendRetry
    {
        $retry = $this->createMock(PushSendRetry::class);
        $retry->method('getAttempts')->willReturn($attempts);
        $retry->method('getSubscriptionId')->willReturn($subscriptionId);
        $retry->method('getCustomerId')->willReturn(42);
        $retry->method('getCampaignId')->willReturn(7);
        $retry->method('getVariant')->willReturn('b');
        $retry->method('getPayload')->willReturn('{"title":"Hi"}');
        return $retry;
    }

    private function stubCollection(PushSendRetry $retry): void
    {
        $collection = $this->createStub(PushSendRetryCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$retry]));
        $this->collectionFactory->method('create')->willReturn($collection);
    }

    private function stubExistingSubscription(): PushSubscription
    {
        $subscription = $this->createStub(PushSubscription::class);
        $subscription->method('getId')->willReturn(9);
        $this->pushSubscriptionFactory->method('create')->willReturn($subscription);
        return $subscription;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteClaimsAndDeletesOnSuccess(): void
    {
        $retry = $this->makeRetry(1);
        $this->stubCollection($retry);
        $subscription = $this->stubExistingSubscription();

        $this->resource->expects(self::once())->method('claim')->willReturn(true);
        $this->pushSubscriptionSender->expects(self::once())->method('send')
            ->with($subscription, '{"title":"Hi"}', 42, 7, 'b', isRetryAttempt: true);
        $this->resource->expects(self::once())->method('delete')->with($retry);
        $this->resource->expects(self::never())->method('save');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsRowClaimedByAnotherProcess(): void
    {
        $retry = $this->makeRetry(0);
        $this->stubCollection($retry);

        $this->resource->method('claim')->willReturn(false);

        $this->pushSubscriptionSender->expects(self::never())->method('send');
        $this->resource->expects(self::never())->method('delete');
        $this->resource->expects(self::never())->method('save');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesRetryWithoutSendingWhenSubscriptionNoLongerExists(): void
    {
        $retry = $this->makeRetry(0);
        $this->stubCollection($retry);

        $subscription = $this->createStub(PushSubscription::class);
        $subscription->method('getId')->willReturn(null);
        $this->pushSubscriptionFactory->method('create')->willReturn($subscription);

        $this->resource->method('claim')->willReturn(true);

        $this->pushSubscriptionSender->expects(self::never())->method('send');
        $this->resource->expects(self::once())->method('delete')->with($retry);

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReschedulesOnAnotherFailure(): void
    {
        $retry = $this->makeRetry(1);
        $this->stubCollection($retry);
        $this->stubExistingSubscription();

        $this->resource->method('claim')->willReturn(true);
        $this->pushSubscriptionSender->method('send')->willThrowException(new \RuntimeException('still broken'));

        $retry->expects(self::once())->method('setAttempts')->with(2);
        $retry->expects(self::once())->method('setLastError')->with('still broken');
        $this->resource->expects(self::once())->method('save')->with($retry);
        $this->resource->expects(self::never())->method('delete');
        $this->logger->expects(self::once())->method('error');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteCountsAnExhaustedRetryBudgetInTheSummary(): void
    {
        // Already at MAX_ATTEMPTS - 1: this failure's attemptNumber (MAX_ATTEMPTS) is the one
        // that exhausts the row's retry budget.
        $retry = $this->makeRetry(RetryFailedPushSends::MAX_ATTEMPTS - 1);
        $this->stubCollection($retry);
        $this->stubExistingSubscription();

        $this->resource->method('claim')->willReturn(true);
        $this->pushSubscriptionSender->method('send')->willThrowException(new \RuntimeException('still broken'));

        $this->resource->expects(self::once())->method('save')->with($retry);
        $this->logger->expects(self::once())->method('error');
        $this->logger->expects(self::once())->method('info')
            ->with(self::stringContains('1 exhausted their retry budget'));

        $this->makeCron()->execute();
    }
}
