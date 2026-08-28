<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\RunScheduledCampaignActions;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignScheduledAction;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction as CampaignScheduledActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\Collection as ScheduledActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\CollectionFactory as ScheduledActionCollectionFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class RunScheduledCampaignActionsTest extends TestCase
{
    private ScheduledActionCollectionFactory $collectionFactory;
    private CampaignScheduledActionResource $resource;
    private CampaignDispatcher $dispatcher;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(ScheduledActionCollectionFactory::class);
        $this->resource = $this->createMock(CampaignScheduledActionResource::class);
        $this->dispatcher = $this->createMock(CampaignDispatcher::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeCron(): RunScheduledCampaignActions
    {
        return new RunScheduledCampaignActions($this->collectionFactory, $this->resource, $this->dispatcher, $this->logger);
    }

    public function testExecuteClaimsAndResumesDueRows(): void
    {
        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->method('getCampaignId')->willReturn(3);
        $scheduled->method('getResumeActionId')->willReturn(9);
        $scheduled->method('getContext')->willReturn(['customer_id' => 1]);
        $scheduled->expects(self::once())->method('setExecutedAt');

        $collection = $this->createMock(ScheduledActionCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$scheduled]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->resource->expects(self::once())->method('save')->with($scheduled);

        $this->dispatcher->expects(self::once())->method('resumeScheduledAction')->with(3, 9, ['customer_id' => 1]);

        $this->makeCron()->execute();
    }

    public function testExecuteLogsAndContinuesWhenResumeThrows(): void
    {
        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->method('getEntityId')->willReturn(4);
        $scheduled->method('getCampaignId')->willReturn(3);
        $scheduled->method('getResumeActionId')->willReturn(9);
        $scheduled->method('getContext')->willReturn([]);

        $collection = $this->createMock(ScheduledActionCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$scheduled]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->dispatcher->method('resumeScheduledAction')->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects(self::once())->method('error');

        $this->makeCron()->execute();
    }

    public function testExecuteDoesNothingWhenNoRowsDue(): void
    {
        $collection = $this->createMock(ScheduledActionCollection::class);
        $collection->method('addDueFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->collectionFactory->method('create')->willReturn($collection);

        $this->dispatcher->expects(self::never())->method('resumeScheduledAction');

        $this->makeCron()->execute();
    }

    public function testExecuteQueriesASecondBatchWhenFirstBatchIsFull(): void
    {
        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->method('getCampaignId')->willReturn(3);
        $scheduled->method('getResumeActionId')->willReturn(9);
        $scheduled->method('getContext')->willReturn([]);

        $fullBatch = $this->createMock(ScheduledActionCollection::class);
        $fullBatch->method('addDueFilter');
        $fullBatch->method('getIterator')->willReturn(new \ArrayIterator(array_fill(0, 500, $scheduled)));

        $emptyBatch = $this->createMock(ScheduledActionCollection::class);
        $emptyBatch->method('addDueFilter');
        $emptyBatch->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->collectionFactory->method('create')->willReturnOnConsecutiveCalls($fullBatch, $emptyBatch);

        $this->dispatcher->expects(self::exactly(500))->method('resumeScheduledAction');
        $this->logger->expects(self::never())->method('warning');

        $this->makeCron()->execute();
    }

    public function testExecuteLogsWarningWhenBatchCapReached(): void
    {
        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->method('getCampaignId')->willReturn(3);
        $scheduled->method('getResumeActionId')->willReturn(9);
        $scheduled->method('getContext')->willReturn([]);

        $fullBatch = $this->createMock(ScheduledActionCollection::class);
        $fullBatch->method('addDueFilter');
        $fullBatch->method('getIterator')->willReturn(new \ArrayIterator(array_fill(0, 500, $scheduled)));

        // Every one of the 20 allowed batches comes back full — the cron must stop after the
        // cap instead of looping forever, and must say so.
        $this->collectionFactory->method('create')->willReturn($fullBatch);

        $this->logger->expects(self::once())->method('warning');

        $this->makeCron()->execute();
    }
}
