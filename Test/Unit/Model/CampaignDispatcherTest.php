<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignScheduledAction;
use Ordo\Automation\Model\CampaignScheduledActionFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection as ActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection as CampaignCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction as CampaignScheduledActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as TriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class CampaignDispatcherTest extends TestCase
{
    private CampaignCollectionFactory $campaignCollectionFactory;
    private TriggerCollectionFactory $triggerCollectionFactory;
    private ConditionCollectionFactory $conditionCollectionFactory;
    private ActionCollectionFactory $actionCollectionFactory;
    private CampaignScheduledActionFactory $campaignScheduledActionFactory;
    private CampaignScheduledActionResource $campaignScheduledActionResource;
    private ConditionPool $conditionPool;
    private ActionPool $actionPool;
    private CacheInterface $cache;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->campaignCollectionFactory = $this->createMock(CampaignCollectionFactory::class);
        $this->triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $this->conditionCollectionFactory = $this->createMock(ConditionCollectionFactory::class);
        $this->actionCollectionFactory = $this->createMock(ActionCollectionFactory::class);
        $this->campaignScheduledActionFactory = $this->createMock(CampaignScheduledActionFactory::class);
        $this->campaignScheduledActionResource = $this->createMock(CampaignScheduledActionResource::class);
        $this->conditionPool = new ConditionPool();
        $this->actionPool = new ActionPool();
        $this->cache = $this->createStub(CacheInterface::class);
        $this->cache->method('load')->willReturn(false);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeDispatcher(): CampaignDispatcher
    {
        return new CampaignDispatcher(
            $this->campaignCollectionFactory,
            $this->triggerCollectionFactory,
            $this->conditionCollectionFactory,
            $this->actionCollectionFactory,
            $this->campaignScheduledActionFactory,
            $this->campaignScheduledActionResource,
            $this->conditionPool,
            $this->actionPool,
            $this->cache,
            new JsonSerializer(),
            $this->logger
        );
    }

    private function makeTriggerCollection(array $campaignIds): TriggerCollection
    {
        $triggers = [];
        foreach ($campaignIds as $campaignId) {
            $trigger = $this->createStub(CampaignTrigger::class);
            $trigger->method('getCampaignId')->willReturn($campaignId);
            $triggers[] = $trigger;
        }

        $collection = $this->createStub(TriggerCollection::class);
        $collection->method('addTriggerEventFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator($triggers));

        return $collection;
    }

    private function makeCampaignCollection(array $campaigns): CampaignCollection
    {
        $collection = $this->createStub(CampaignCollection::class);
        $collection->method('addIdsFilter');
        $collection->method('addEnabledFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator($campaigns));

        return $collection;
    }

    /**
     * @param CampaignCondition[] $rows
     */
    private function makeConditionCollection(array $rows): ConditionCollection
    {
        $collection = $this->createStub(ConditionCollection::class);
        $collection->method('addCampaignIdsFilter')->willReturn($collection);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        return $collection;
    }

    /**
     * @param CampaignAction[] $rows
     */
    private function makeActionCollection(array $rows): ActionCollection
    {
        $collection = $this->createStub(ActionCollection::class);
        $collection->method('addCampaignIdsFilter')->willReturn($collection);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        return $collection;
    }

    private function makeCampaign(int $id, string $conditionLogic = 'all'): \Ordo\Automation\Model\Campaign
    {
        $campaign = $this->createStub(\Ordo\Automation\Model\Campaign::class);
        $campaign->method('getId')->willReturn($id);
        $campaign->method('getData')->willReturnMap([['condition_logic', $conditionLogic]]);
        return $campaign;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchRunsActionWhenNoConditions(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getCampaignId')->willReturn(1);
        $actionRow->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionRow->method('getParams')->willReturn(['tag' => 'vip']);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$actionRow]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')->with(self::anything(), ['tag' => 'vip']);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchInjectsCampaignIdIntoContextBeforeRunningActions(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getCampaignId')->willReturn(1);
        $actionRow->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionRow->method('getParams')->willReturn([]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$actionRow]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')
            ->with(self::callback(fn (array $context): bool => $context['campaign_id'] === 1), []);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchReturnsEarlyWhenNoTriggerMatches(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([]));

        $this->campaignCollectionFactory->expects(self::never())->method('create');
        $this->conditionCollectionFactory->expects(self::never())->method('create');
        $this->actionCollectionFactory->expects(self::never())->method('create');

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchUsesCachedCampaignIdsWithoutRequeryingTriggersOrCampaigns(): void
    {
        $this->cache = $this->createStub(CacheInterface::class);
        // Cached shape is now campaign_id => condition_logic (see
        // CampaignDispatcher::campaignIdsForTrigger()), not a plain list of ids.
        $this->cache->method('load')->willReturn('{"1":"all"}');

        $this->triggerCollectionFactory->expects(self::never())->method('create');
        $this->campaignCollectionFactory->expects(self::never())->method('create');

        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getCampaignId')->willReturn(1);
        $actionRow->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionRow->method('getParams')->willReturn([]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$actionRow]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchDeduplicatesCampaignWithMultipleMatchingTriggers(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1, 1]));

        $campaignCollection = $this->createMock(CampaignCollection::class);
        $campaignCollection->expects(self::once())->method('addIdsFilter')->with([1]);
        $campaignCollection->method('addEnabledFilter');
        $campaignCollection->method('getIterator')->willReturn(new \ArrayIterator([$this->makeCampaign(1)]));
        $this->campaignCollectionFactory->method('create')->willReturn($campaignCollection);

        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([]));

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSkipsCampaignWhenConditionNotSatisfied(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));

        $conditionRow = $this->createMock(CampaignCondition::class);
        $conditionRow->method('getCampaignId')->willReturn(1);
        $conditionRow->method('getData')->willReturnMap([['type', 'has_tag']]);
        $conditionRow->method('getParams')->willReturn([]);
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([$conditionRow]));

        $condition = $this->createStub(ConditionInterface::class);
        $condition->method('isSatisfied')->willReturn(false);
        $this->conditionPool = new ConditionPool(['has_tag' => $condition]);

        // Actions are still batch-loaded for every matched campaign up front (that's the
        // point of batching), but none of them should ever execute() since the condition fails.
        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::never())->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([]));

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchLogsAndSkipsWhenConditionTypeUnknown(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));

        $conditionRow = $this->createMock(CampaignCondition::class);
        $conditionRow->method('getCampaignId')->willReturn(1);
        $conditionRow->method('getData')->willReturnMap([['type', 'unknown_type']]);
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([$conditionRow]));
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([]));

        $this->logger->expects(self::once())->method('error');

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchLogsAndContinuesWhenActionTypeUnknown(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getCampaignId')->willReturn(1);
        $actionRow->method('getData')->willReturnMap([['type', 'unknown_action']]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$actionRow]));

        $this->logger->expects(self::once())->method('error');

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSchedulesInsteadOfRunningWhenActionHasDelay(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $delayedAction = $this->createStub(CampaignAction::class);
        $delayedAction->method('getCampaignId')->willReturn(1);
        $delayedAction->method('getEntityId')->willReturn(42);
        $delayedAction->method('getDelayMinutes')->willReturn(1440);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$delayedAction]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::never())->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->expects(self::once())->method('setCampaignId')->with(1);
        $scheduled->expects(self::once())->method('setResumeActionId')->with(42);
        $scheduled->expects(self::once())->method('setContext')->with(['customer_id' => 1, 'campaign_id' => 1]);
        $scheduled->expects(self::once())->method('setRunAt');
        $this->campaignScheduledActionFactory->method('create')->willReturn($scheduled);
        $this->campaignScheduledActionResource->expects(self::once())->method('save')->with($scheduled);

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchRunsNonDelayedActionsThenSchedulesRemainingChain(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $immediateAction = $this->createMock(CampaignAction::class);
        $immediateAction->method('getCampaignId')->willReturn(1);
        $immediateAction->method('getEntityId')->willReturn(10);
        $immediateAction->method('getDelayMinutes')->willReturn(0);
        $immediateAction->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $immediateAction->method('getParams')->willReturn(['tag' => 'vip']);

        $delayedAction = $this->createStub(CampaignAction::class);
        $delayedAction->method('getCampaignId')->willReturn(1);
        $delayedAction->method('getEntityId')->willReturn(11);
        $delayedAction->method('getDelayMinutes')->willReturn(60);

        $this->actionCollectionFactory->method('create')->willReturn(
            $this->makeActionCollection([$immediateAction, $delayedAction])
        );

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')->with(self::anything(), ['tag' => 'vip']);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->expects(self::once())->method('setResumeActionId')->with(11);
        $this->campaignScheduledActionFactory->method('create')->willReturn($scheduled);
        $this->campaignScheduledActionResource->expects(self::once())->method('save')->with($scheduled);

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchBatchesConditionsAndActionsAcrossMultipleCampaignsInOneQueryEach(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1, 2]));
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeCampaignCollection([$this->makeCampaign(1), $this->makeCampaign(2)])
        );

        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->expects(self::once())->method('addCampaignIdsFilter')->with([1, 2])->willReturnSelf();
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->expects(self::once())->method('create')->willReturn($conditionCollection);

        $actionOfCampaign1 = $this->createMock(CampaignAction::class);
        $actionOfCampaign1->method('getCampaignId')->willReturn(1);
        $actionOfCampaign1->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionOfCampaign1->method('getParams')->willReturn(['tag' => 'one']);

        $actionOfCampaign2 = $this->createMock(CampaignAction::class);
        $actionOfCampaign2->method('getCampaignId')->willReturn(2);
        $actionOfCampaign2->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionOfCampaign2->method('getParams')->willReturn(['tag' => 'two']);

        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->expects(self::once())->method('addCampaignIdsFilter')->with([1, 2])->willReturnSelf();
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$actionOfCampaign1, $actionOfCampaign2]));
        // A single query is made for BOTH campaigns' actions, not one query per campaign.
        $this->actionCollectionFactory->expects(self::once())->method('create')->willReturn($actionCollection);

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::exactly(2))->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResumeScheduledActionRunsFromResumePointOnward(): void
    {
        $firstAction = $this->createMock(CampaignAction::class);
        $firstAction->method('getEntityId')->willReturn(10);
        $firstAction->method('getDelayMinutes')->willReturn(0);
        $firstAction->method('getData')->willReturnMap([['type', 'unused_action']]);

        $resumeAction = $this->createMock(CampaignAction::class);
        $resumeAction->method('getEntityId')->willReturn(11);
        $resumeAction->method('getDelayMinutes')->willReturn(0);
        $resumeAction->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $resumeAction->method('getParams')->willReturn(['tag' => 'reactivated']);

        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$firstAction, $resumeAction]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $action = $this->createMock(ActionInterface::class);
        // Only the resumed action (and anything after it) should ever run — never the one
        // before the resume point, that already ran in the original synchronous dispatch.
        $action->expects(self::once())->method('execute')->with(self::anything(), ['tag' => 'reactivated']);
        $this->actionPool = new ActionPool(['tag_customer' => $action, 'unused_action' => $action]);

        $this->makeDispatcher()->resumeScheduledAction(1, 11, ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResumeScheduledActionInjectsCampaignIdIntoContext(): void
    {
        $resumeAction = $this->createMock(CampaignAction::class);
        $resumeAction->method('getEntityId')->willReturn(11);
        $resumeAction->method('getDelayMinutes')->willReturn(0);
        $resumeAction->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $resumeAction->method('getParams')->willReturn([]);

        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$resumeAction]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')
            ->with(self::callback(fn (array $context): bool => $context['campaign_id'] === 9), []);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->resumeScheduledAction(9, 11, ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResumeScheduledActionDoesNothingWhenActionNoLongerExists(): void
    {
        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $this->campaignScheduledActionFactory->expects(self::never())->method('create');

        $this->makeDispatcher()->resumeScheduledAction(1, 999, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchRunsActionWhenAnyConditionLogicHasOneSatisfiedCondition(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeCampaignCollection([$this->makeCampaign(1, 'any')])
        );

        $failingRow = $this->createStub(CampaignCondition::class);
        $failingRow->method('getCampaignId')->willReturn(1);
        $failingRow->method('getData')->willReturnMap([['type', 'fails']]);
        $failingRow->method('getParams')->willReturn([]);

        $passingRow = $this->createStub(CampaignCondition::class);
        $passingRow->method('getCampaignId')->willReturn(1);
        $passingRow->method('getData')->willReturnMap([['type', 'passes']]);
        $passingRow->method('getParams')->willReturn([]);

        $this->conditionCollectionFactory->method('create')
            ->willReturn($this->makeConditionCollection([$failingRow, $passingRow]));

        $failingCondition = $this->createStub(ConditionInterface::class);
        $failingCondition->method('isSatisfied')->willReturn(false);
        $passingCondition = $this->createStub(ConditionInterface::class);
        $passingCondition->method('isSatisfied')->willReturn(true);
        $this->conditionPool = new ConditionPool(['fails' => $failingCondition, 'passes' => $passingCondition]);

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getCampaignId')->willReturn(1);
        $actionRow->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionRow->method('getParams')->willReturn([]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$actionRow]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSkipsCampaignWhenAnyConditionLogicHasNoConditionsSatisfied(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeCampaignCollection([$this->makeCampaign(1, 'any')])
        );

        $conditionRow = $this->createStub(CampaignCondition::class);
        $conditionRow->method('getCampaignId')->willReturn(1);
        $conditionRow->method('getData')->willReturnMap([['type', 'has_tag']]);
        $conditionRow->method('getParams')->willReturn([]);
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([$conditionRow]));

        $condition = $this->createStub(ConditionInterface::class);
        $condition->method('isSatisfied')->willReturn(false);
        $this->conditionPool = new ConditionPool(['has_tag' => $condition]);

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::never())->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([]));

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchEvaluatesNestedGroupWithItsOwnLogic(): void
    {
        // Top-level AND: has_tag AND (group, OR: score>=999 OR score>=1) - the group only
        // passes via its own internal OR, not the top-level AND.
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeCampaignCollection([$this->makeCampaign(1, 'all')])
        );

        $tagRow = $this->createStub(CampaignCondition::class);
        $tagRow->method('getCampaignId')->willReturn(1);
        $tagRow->method('getData')->willReturnMap([['type', 'has_tag']]);
        $tagRow->method('getParams')->willReturn([]);

        $groupRow = $this->createStub(CampaignCondition::class);
        $groupRow->method('getCampaignId')->willReturn(1);
        $groupRow->method('getData')->willReturnMap([['type', 'group']]);
        $groupRow->method('getParams')->willReturn([
            'logic' => 'any',
            'conditions' => [
                ['type' => 'score_at_least', 'params' => ['threshold' => 999]],
                ['type' => 'score_at_least', 'params' => ['threshold' => 1]],
            ],
        ]);

        $this->conditionCollectionFactory->method('create')
            ->willReturn($this->makeConditionCollection([$tagRow, $groupRow]));

        $tagCondition = $this->createStub(ConditionInterface::class);
        $tagCondition->method('isSatisfied')->willReturn(true);

        $scoreCondition = $this->createMock(ConditionInterface::class);
        $scoreCondition->method('isSatisfied')->willReturnMap([
            [[], ['threshold' => 999], false],
            [[], ['threshold' => 1], true],
        ]);

        $this->conditionPool = new ConditionPool(['has_tag' => $tagCondition, 'score_at_least' => $scoreCondition]);

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getCampaignId')->willReturn(1);
        $actionRow->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionRow->method('getParams')->willReturn([]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$actionRow]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchTreatsAnEmptyNestedGroupAsUnsatisfied(): void
    {
        // Unlike a campaign with zero top-level conditions (which fires unconditionally), a
        // 'group' row an admin built and left with no conditions inside it fails closed - same
        // as SegmentMatcher::evaluateGroup()'s identical rule.
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeCampaignCollection([$this->makeCampaign(1)])
        );

        $groupRow = $this->createStub(CampaignCondition::class);
        $groupRow->method('getCampaignId')->willReturn(1);
        $groupRow->method('getData')->willReturnMap([['type', 'group']]);
        $groupRow->method('getParams')->willReturn(['logic' => 'all', 'conditions' => []]);

        $this->conditionCollectionFactory->method('create')
            ->willReturn($this->makeConditionCollection([$groupRow]));

        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::never())->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSkipsMalformedItemsInsideANestedGroup(): void
    {
        // Every entry in this group's own "conditions" is malformed (a bare string, and an item
        // whose "type" isn't itself a string) - both get filtered out by the same guard a
        // hand-crafted/tampered POST could otherwise smuggle past, leaving zero real specs to
        // evaluate, which fails closed exactly like an empty group does.
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeCampaignCollection([$this->makeCampaign(1)])
        );

        $groupRow = $this->createStub(CampaignCondition::class);
        $groupRow->method('getCampaignId')->willReturn(1);
        $groupRow->method('getData')->willReturnMap([['type', 'group']]);
        $groupRow->method('getParams')->willReturn([
            'logic' => 'any',
            'conditions' => ['not-an-array', ['type' => 42]],
        ]);

        $this->conditionCollectionFactory->method('create')
            ->willReturn($this->makeConditionCollection([$groupRow]));

        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::never())->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchNormalizesANestedGroupItemsNonArrayParamsToEmpty(): void
    {
        // The group's one real item has a "params" that survived JSON-decoding as a plain
        // string, not the string-keyed map a real CampaignCondition row's own getParams() always
        // is - normalized to [] (same as an entirely missing "params" key) rather than passed
        // through as-is or rejected outright.
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeCampaignCollection([$this->makeCampaign(1)])
        );

        $groupRow = $this->createStub(CampaignCondition::class);
        $groupRow->method('getCampaignId')->willReturn(1);
        $groupRow->method('getData')->willReturnMap([['type', 'group']]);
        $groupRow->method('getParams')->willReturn([
            'logic' => 'all',
            'conditions' => [['type' => 'score_at_least', 'params' => 'not-an-array']],
        ]);

        $this->conditionCollectionFactory->method('create')
            ->willReturn($this->makeConditionCollection([$groupRow]));

        $scoreCondition = $this->createMock(ConditionInterface::class);
        $scoreCondition->expects(self::once())->method('isSatisfied')->with([], [])->willReturn(true);
        $this->conditionPool = new ConditionPool(['score_at_least' => $scoreCondition]);

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getCampaignId')->willReturn(1);
        $actionRow->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionRow->method('getParams')->willReturn([]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$actionRow]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchLogsAndAbortsWhenBatchLoadingConditionsThrows(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));

        $this->conditionCollectionFactory->method('create')->willThrowException(new \RuntimeException('db error'));

        $this->logger->expects(self::once())->method('error');

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchLogsAndContinuesToNextCampaignWhenAnActionThrows(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1, 2]));
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeCampaignCollection([$this->makeCampaign(1), $this->makeCampaign(2)])
        );
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $actionOfCampaign1 = $this->createMock(CampaignAction::class);
        $actionOfCampaign1->method('getCampaignId')->willReturn(1);
        $actionOfCampaign1->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionOfCampaign1->method('getParams')->willReturn([]);

        $actionOfCampaign2 = $this->createMock(CampaignAction::class);
        $actionOfCampaign2->method('getCampaignId')->willReturn(2);
        $actionOfCampaign2->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionOfCampaign2->method('getParams')->willReturn([]);

        $this->actionCollectionFactory->method('create')->willReturn(
            $this->makeActionCollection([$actionOfCampaign1, $actionOfCampaign2])
        );

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::exactly(2))->method('execute')
            ->willReturnCallback(function (): void {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    throw new \RuntimeException('mailer down');
                }
            });
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        // Campaign 1's action throws and is logged; campaign 2 still runs — one campaign
        // failing must not abort the whole dispatch for every other matched campaign.
        $this->logger->expects(self::once())->method('error');

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    /**
     * @param \Ordo\Automation\Model\Campaign $campaign
     */
    private function makeSingleCampaignCollection($campaign): CampaignCollection
    {
        $collection = $this->createStub(CampaignCollection::class);
        $collection->method('addIdsFilter');
        $collection->method('addEnabledFilter');
        $collection->method('getFirstItem')->willReturn($campaign);

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchScheduledTriggerRunsActionsForExactlyThatCampaign(): void
    {
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeSingleCampaignCollection($this->makeCampaign(5))
        );

        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getCampaignId')->willReturn(5);
        $actionRow->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionRow->method('getParams')->willReturn(['tag' => 'black-friday']);

        $actionCollection = $this->createStub(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$actionRow]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')->with(self::anything(), ['tag' => 'black-friday']);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->triggerCollectionFactory->expects(self::never())->method('create');

        $this->makeDispatcher()->dispatchScheduledTrigger(5, ['now' => '2026-11-28 09:00:00']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchScheduledTriggerDoesNothingWhenCampaignDisabledOrMissing(): void
    {
        $missingCampaign = $this->createStub(\Ordo\Automation\Model\Campaign::class);
        $missingCampaign->method('getId')->willReturn(null);

        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeSingleCampaignCollection($missingCampaign)
        );

        $this->conditionCollectionFactory->expects(self::never())->method('create');
        $this->actionCollectionFactory->expects(self::never())->method('create');

        $this->makeDispatcher()->dispatchScheduledTrigger(999, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchScheduledTriggerSkipsActionsWhenConditionNotSatisfied(): void
    {
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeSingleCampaignCollection($this->makeCampaign(5))
        );

        $conditionRow = $this->createMock(CampaignCondition::class);
        $conditionRow->method('getData')->willReturnMap([['type', 'unknown_condition_type']]);
        $conditionRow->method('getParams')->willReturn([]);

        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$conditionRow]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $this->actionCollectionFactory->expects(self::never())->method('create');

        $this->makeDispatcher()->dispatchScheduledTrigger(5, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchScheduledTriggerRunsActionsWhenAnyConditionLogicHasOneSatisfiedCondition(): void
    {
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeSingleCampaignCollection($this->makeCampaign(5, 'any'))
        );

        $failingRow = $this->createStub(CampaignCondition::class);
        $failingRow->method('getData')->willReturnMap([['type', 'fails']]);
        $failingRow->method('getParams')->willReturn([]);

        $passingRow = $this->createStub(CampaignCondition::class);
        $passingRow->method('getData')->willReturnMap([['type', 'passes']]);
        $passingRow->method('getParams')->willReturn([]);

        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$failingRow, $passingRow]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $failingCondition = $this->createStub(ConditionInterface::class);
        $failingCondition->method('isSatisfied')->willReturn(false);
        $passingCondition = $this->createStub(ConditionInterface::class);
        $passingCondition->method('isSatisfied')->willReturn(true);
        $this->conditionPool = new ConditionPool(['fails' => $failingCondition, 'passes' => $passingCondition]);

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getCampaignId')->willReturn(5);
        $actionRow->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionRow->method('getParams')->willReturn([]);

        $actionCollection = $this->createStub(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$actionRow]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatchScheduledTrigger(5, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchScheduledTriggerLogsAndSwallowsWhenLoadingConditionsThrows(): void
    {
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeSingleCampaignCollection($this->makeCampaign(5))
        );

        $this->conditionCollectionFactory->method('create')->willThrowException(new \RuntimeException('db error'));
        $this->actionCollectionFactory->expects(self::never())->method('create');

        $this->logger->expects(self::once())->method('error');

        $this->makeDispatcher()->dispatchScheduledTrigger(5, []);
    }
}
