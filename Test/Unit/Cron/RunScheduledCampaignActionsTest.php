<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\RunScheduledCampaignActions;
use Ordo\Automation\Model\Campaign\ActionRetryQueue;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignScheduledAction;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction as CampaignScheduledActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\Collection as ScheduledActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\CollectionFactory as ScheduledActionCollectionFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class RunScheduledCampaignActionsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private ScheduledActionCollectionFactory $collectionFactory;
    private CampaignScheduledActionResource $resource;
    private CampaignDispatcher $dispatcher;
    private LoggerInterface $logger;
    private ActionRetryQueue $actionRetryQueue;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createStub(ScheduledActionCollectionFactory::class);
        $this->resource = $this->createMock(CampaignScheduledActionResource::class);
        $this->dispatcher = $this->createMock(CampaignDispatcher::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->actionRetryQueue = $this->createMock(ActionRetryQueue::class);
    }

    private function makeCron(): RunScheduledCampaignActions
    {
        return new RunScheduledCampaignActions(
            $this->collectionFactory,
            $this->resource,
            $this->dispatcher,
            $this->logger,
            $this->makeCronRunLogger($this->logger),
            $this->actionRetryQueue
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteClaimsAndResumesDueRows(): void
    {
        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->method('getCampaignId')->willReturn(3);
        $scheduled->method('getResumeActionId')->willReturn(9);
        $scheduled->method('getContext')->willReturn(['customer_id' => 1]);

        $collection = $this->createStub(ScheduledActionCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$scheduled]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->resource->expects(self::once())->method('claim')
            ->with($scheduled, self::callback(static fn ($now) => is_string($now)))
            ->willReturn(true);

        $this->dispatcher->expects(self::once())->method('resumeScheduledAction')->with(3, 9, ['customer_id' => 1]);

        $this->makeCron()->execute();
    }

    /**
     * Regression test for a real race-condition bug a code audit found: the claim used to be a
     * plain load()-then-save(), so two overlapping cron runs could both "win" the same due row
     * and both dispatch its action. The atomic conditional UPDATE (claim()) now returning false -
     * meaning some other process's UPDATE already matched this row - must make this process skip
     * it entirely, never dispatching.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsRowWhenAnotherProcessAlreadyClaimedIt(): void
    {
        $scheduled = $this->createMock(CampaignScheduledAction::class);

        $collection = $this->createStub(ScheduledActionCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$scheduled]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->resource->method('claim')->willReturn(false);

        $this->dispatcher->expects(self::never())->method('resumeScheduledAction');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndContinuesWhenResumeThrows(): void
    {
        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->method('getEntityId')->willReturn(4);
        $scheduled->method('getCampaignId')->willReturn(3);
        $scheduled->method('getResumeActionId')->willReturn(9);
        $scheduled->method('getContext')->willReturn([]);

        $collection = $this->createStub(ScheduledActionCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$scheduled]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->resource->method('claim')->willReturn(true);
        $this->dispatcher->method('resumeScheduledAction')->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects(self::once())->method('error');
        $this->actionRetryQueue->expects(self::once())->method('enqueue')->with(3, 9, [], self::isInstanceOf(\RuntimeException::class));

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNothingWhenNoRowsDue(): void
    {
        $collection = $this->createStub(ScheduledActionCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->dispatcher->expects(self::never())->method('resumeScheduledAction');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteQueriesASecondBatchWhenFirstBatchIsFull(): void
    {
        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->method('getCampaignId')->willReturn(3);
        $scheduled->method('getResumeActionId')->willReturn(9);
        $scheduled->method('getContext')->willReturn([]);

        $fullBatch = $this->createStub(ScheduledActionCollection::class);
        $fullBatch->method('addDueFilter');
        $fullBatch->method('getIterator')->willReturn(new \ArrayIterator(array_fill(0, 500, $scheduled)));

        $emptyBatch = $this->createStub(ScheduledActionCollection::class);
        $emptyBatch->method('addDueFilter');
        $emptyBatch->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls($fullBatch, $emptyBatch);
        $this->resource->method('claim')->willReturn(true);

        $this->dispatcher->expects(self::exactly(500))->method('resumeScheduledAction');
        $this->logger->expects(self::never())->method('warning');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsWarningWhenBatchCapReached(): void
    {
        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->method('getCampaignId')->willReturn(3);
        $scheduled->method('getResumeActionId')->willReturn(9);
        $scheduled->method('getContext')->willReturn([]);

        $fullBatch = $this->createStub(ScheduledActionCollection::class);
        $fullBatch->method('addDueFilter');
        $fullBatch->method('getIterator')->willReturn(new \ArrayIterator(array_fill(0, 500, $scheduled)));

        // Every one of the 20 allowed batches comes back full — the cron must stop after the
        // cap instead of looping forever, and must say so.
        $this->collectionFactory->method('create')->willReturn($fullBatch);
        $this->resource->method('claim')->willReturn(true);

        $this->logger->expects(self::once())->method('warning');

        $this->makeCron()->execute();
    }
}
