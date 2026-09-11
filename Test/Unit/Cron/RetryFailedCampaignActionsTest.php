<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\RetryFailedCampaignActions;
use Ordo\Automation\Model\Campaign\ActionRetryQueue;
use Ordo\Automation\Model\CampaignActionRetry;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry as CampaignActionRetryResource;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry\Collection as CampaignActionRetryCollection;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry\CollectionFactory as CampaignActionRetryCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RetryFailedCampaignActionsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private CampaignActionRetryCollectionFactory $collectionFactory;
    private CampaignActionRetryResource $resource;
    private CampaignDispatcher $dispatcher;
    private ActionRetryQueue $actionRetryQueue;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createStub(CampaignActionRetryCollectionFactory::class);
        $this->resource = $this->createMock(CampaignActionRetryResource::class);
        $this->dispatcher = $this->createMock(CampaignDispatcher::class);
        $this->actionRetryQueue = $this->createStub(ActionRetryQueue::class);
        $this->actionRetryQueue->method('nextRetryAt')->willReturn('2024-01-01 00:05:00');
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeCron(): RetryFailedCampaignActions
    {
        return new RetryFailedCampaignActions(
            $this->collectionFactory,
            $this->resource,
            $this->dispatcher,
            $this->actionRetryQueue,
            $this->makeCronRunLogger($this->logger)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteClaimsAndDeletesOnSuccess(): void
    {
        $retry = $this->createMock(CampaignActionRetry::class);
        $retry->method('getAttempts')->willReturn(1);
        $retry->method('getCampaignId')->willReturn(3);
        $retry->method('getResumeActionId')->willReturn(9);
        $retry->method('getContext')->willReturn(['customer_id' => 1]);

        $collection = $this->createStub(CampaignActionRetryCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$retry]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->resource->expects(self::once())->method('claim')->willReturn(true);
        $this->dispatcher->expects(self::once())->method('resumeScheduledAction')->with(3, 9, ['customer_id' => 1]);
        $this->resource->expects(self::once())->method('delete')->with($retry);
        $this->resource->expects(self::never())->method('save');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsRowClaimedByAnotherProcess(): void
    {
        $retry = $this->createMock(CampaignActionRetry::class);
        $retry->method('getAttempts')->willReturn(0);

        $collection = $this->createStub(CampaignActionRetryCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$retry]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->resource->method('claim')->willReturn(false);

        $this->dispatcher->expects(self::never())->method('resumeScheduledAction');
        $this->resource->expects(self::never())->method('delete');
        $this->resource->expects(self::never())->method('save');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReschedulesOnAnotherFailure(): void
    {
        $retry = $this->createMock(CampaignActionRetry::class);
        $retry->method('getAttempts')->willReturn(1);
        $retry->method('getCampaignId')->willReturn(3);
        $retry->method('getResumeActionId')->willReturn(9);
        $retry->method('getContext')->willReturn([]);

        $collection = $this->createStub(CampaignActionRetryCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$retry]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->resource->method('claim')->willReturn(true);
        $this->dispatcher->method('resumeScheduledAction')->willThrowException(new \RuntimeException('still broken'));

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
        $retry = $this->createMock(CampaignActionRetry::class);
        // Already at MAX_ATTEMPTS - 1: this failure's attemptNumber (MAX_ATTEMPTS) is the one
        // that exhausts the row's retry budget.
        $retry->method('getAttempts')->willReturn(RetryFailedCampaignActions::MAX_ATTEMPTS - 1);
        $retry->method('getCampaignId')->willReturn(3);
        $retry->method('getResumeActionId')->willReturn(9);
        $retry->method('getContext')->willReturn([]);

        $collection = $this->createStub(CampaignActionRetryCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$retry]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->resource->method('claim')->willReturn(true);
        $this->dispatcher->method('resumeScheduledAction')->willThrowException(new \RuntimeException('still broken'));

        $this->resource->expects(self::once())->method('save')->with($retry);
        $this->logger->expects(self::once())->method('error');
        $this->logger->expects(self::once())->method('info')->with(self::stringContains('1 exhausted their retry budget'));

        $this->makeCron()->execute();
    }
}
