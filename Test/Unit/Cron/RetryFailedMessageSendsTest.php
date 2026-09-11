<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Cron\RetryFailedMessageSends;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\MessageSendRetry;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry as MessageSendRetryResource;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry\Collection as MessageSendRetryCollection;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry\CollectionFactory as MessageSendRetryCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetryFailedMessageSendsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private MessageSendRetryCollectionFactory $collectionFactory;
    private MessageSendRetryResource $resource;
    private ActionPool $actionPool;
    private MessageSendRetryQueue $messageSendRetryQueue;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createStub(MessageSendRetryCollectionFactory::class);
        $this->resource = $this->createMock(MessageSendRetryResource::class);
        $this->actionPool = $this->createMock(ActionPool::class);
        $this->messageSendRetryQueue = $this->createStub(MessageSendRetryQueue::class);
        $this->messageSendRetryQueue->method('nextRetryAt')->willReturn('2024-01-01 00:05:00');
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeCron(): RetryFailedMessageSends
    {
        return new RetryFailedMessageSends(
            $this->collectionFactory,
            $this->resource,
            $this->actionPool,
            $this->messageSendRetryQueue,
            $this->makeCronRunLogger($this->logger)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteClaimsAndDeletesOnSuccess(): void
    {
        $retry = $this->createMock(MessageSendRetry::class);
        $retry->method('getAttempts')->willReturn(1);
        $retry->method('getActionType')->willReturn('send_email');
        $retry->method('getContext')->willReturn(['customer_id' => 1]);
        $retry->method('getParams')->willReturn(['template' => 'ordo_campaign_generic']);

        $collection = $this->createStub(MessageSendRetryCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$retry]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')
            ->with(self::isArray(), ['template' => 'ordo_campaign_generic']);
        $this->actionPool->expects(self::once())->method('get')->with('send_email')->willReturn($action);

        $this->resource->expects(self::once())->method('claim')->willReturn(true);
        $this->resource->expects(self::once())->method('delete')->with($retry);
        $this->resource->expects(self::never())->method('save');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsRowClaimedByAnotherProcess(): void
    {
        $retry = $this->createMock(MessageSendRetry::class);
        $retry->method('getAttempts')->willReturn(0);

        $collection = $this->createStub(MessageSendRetryCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$retry]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->resource->method('claim')->willReturn(false);

        $this->actionPool->expects(self::never())->method('get');
        $this->resource->expects(self::never())->method('delete');
        $this->resource->expects(self::never())->method('save');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReschedulesOnAnotherFailure(): void
    {
        $retry = $this->createMock(MessageSendRetry::class);
        $retry->method('getAttempts')->willReturn(1);
        $retry->method('getActionType')->willReturn('send_email');
        $retry->method('getContext')->willReturn([]);
        $retry->method('getParams')->willReturn([]);

        $collection = $this->createStub(MessageSendRetryCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$retry]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $action = $this->createStub(ActionInterface::class);
        $action->method('execute')->willThrowException(new \RuntimeException('still broken'));
        $this->actionPool->method('get')->willReturn($action);

        $this->resource->method('claim')->willReturn(true);

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
        $retry = $this->createMock(MessageSendRetry::class);
        // Already at MAX_ATTEMPTS - 1: this failure's attemptNumber (MAX_ATTEMPTS) is the one
        // that exhausts the row's retry budget.
        $retry->method('getAttempts')->willReturn(RetryFailedMessageSends::MAX_ATTEMPTS - 1);
        $retry->method('getActionType')->willReturn('send_email');
        $retry->method('getContext')->willReturn([]);
        $retry->method('getParams')->willReturn([]);

        $collection = $this->createStub(MessageSendRetryCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$retry]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $action = $this->createStub(ActionInterface::class);
        $action->method('execute')->willThrowException(new \RuntimeException('still broken'));
        $this->actionPool->method('get')->willReturn($action);

        $this->resource->method('claim')->willReturn(true);

        $this->resource->expects(self::once())->method('save')->with($retry);
        $this->logger->expects(self::once())->method('error');
        $this->logger->expects(self::once())->method('info')
            ->with(self::stringContains('1 exhausted their retry budget'));

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteTreatsAnUnregisteredActionTypeAsExhaustedImmediately(): void
    {
        $retry = $this->createMock(MessageSendRetry::class);
        $retry->method('getAttempts')->willReturn(0);
        $retry->method('getActionType')->willReturn('send_carrier_pigeon');

        $collection = $this->createStub(MessageSendRetryCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$retry]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->actionPool->expects(self::once())->method('get')->with('send_carrier_pigeon')->willReturn(null);
        $this->resource->method('claim')->willReturn(true);

        $this->resource->expects(self::never())->method('save');
        $this->resource->expects(self::never())->method('delete');
        $this->logger->expects(self::once())->method('info')
            ->with(self::stringContains('1 exhausted their retry budget'));

        $this->makeCron()->execute();
    }
}
