<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\CampaignEntryGuard;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\Campaign\SplitVariantSelector;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignScheduledAction;
use Ordo\Automation\Model\CampaignScheduledActionFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\Condition\ConditionGroupEvaluator;
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
    private CampaignActionFactory $campaignActionFactory;
    private CampaignScheduledActionFactory $campaignScheduledActionFactory;
    private CampaignScheduledActionResource $campaignScheduledActionResource;
    private ConditionPool $conditionPool;
    private ActionPool $actionPool;
    private SplitVariantSelector $splitVariantSelector;
    private CampaignEntryGuard&\PHPUnit\Framework\MockObject\MockObject $campaignEntryGuard;
    private CacheInterface $cache;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->campaignCollectionFactory = $this->createMock(CampaignCollectionFactory::class);
        $this->triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $this->conditionCollectionFactory = $this->createMock(ConditionCollectionFactory::class);
        $this->actionCollectionFactory = $this->createMock(ActionCollectionFactory::class);
        $this->campaignActionFactory = $this->createStub(CampaignActionFactory::class);
        $this->campaignActionFactory->method('create')->willReturnCallback(fn () => $this->makeRealCampaignAction());
        $this->campaignScheduledActionFactory = $this->createMock(CampaignScheduledActionFactory::class);
        $this->campaignScheduledActionResource = $this->createMock(CampaignScheduledActionResource::class);
        $this->conditionPool = new ConditionPool();
        $this->actionPool = new ActionPool();
        $this->splitVariantSelector = new SplitVariantSelector();
        $this->campaignEntryGuard = $this->createMock(CampaignEntryGuard::class);
        $this->campaignEntryGuard->method('hasPendingEntry')->willReturn(false);
        $this->cache = $this->createStub(CacheInterface::class);
        $this->cache->method('load')->willReturn(false);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeRealCampaignAction(): CampaignAction
    {
        $resource = $this->createStub(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        return new CampaignAction(
            $this->createStub(\Magento\Framework\Model\Context::class),
            $this->createStub(\Magento\Framework\Registry::class),
            $resource
        );
    }

    private function makeDispatcher(): CampaignDispatcher
    {
        return new CampaignDispatcher(
            $this->campaignCollectionFactory,
            $this->triggerCollectionFactory,
            $this->conditionCollectionFactory,
            $this->actionCollectionFactory,
            $this->campaignActionFactory,
            $this->campaignScheduledActionFactory,
            $this->campaignScheduledActionResource,
            new ConditionGroupEvaluator($this->conditionPool, $this->logger),
            $this->actionPool,
            $this->splitVariantSelector,
            $this->campaignEntryGuard,
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
    public function testDispatchRunsSplitActionRunningOnlyTheChosenVariantsActions(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $splitAction = $this->createMock(CampaignAction::class);
        $splitAction->method('getCampaignId')->willReturn(1);
        $splitAction->method('getEntityId')->willReturn(20);
        $splitAction->method('getDelayMinutes')->willReturn(0);
        $splitAction->method('getData')->willReturnMap([['type', 'split']]);
        // Weight 100/0 - deterministically always variant 'a', regardless of identity hash.
        $splitAction->method('getParams')->willReturn([
            'variants' => [
                ['key' => 'a', 'weight' => 100, 'actions' => [['type' => 'tag_customer', 'params' => ['tag' => 'variant-a']]]],
                ['key' => 'b', 'weight' => 0, 'actions' => [['type' => 'tag_customer', 'params' => ['tag' => 'variant-b']]]],
            ],
        ]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$splitAction]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')
            ->with(self::anything(), ['tag' => 'variant-a']);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSplitActionStampsChosenVariantIntoContext(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $splitAction = $this->createMock(CampaignAction::class);
        $splitAction->method('getCampaignId')->willReturn(1);
        $splitAction->method('getEntityId')->willReturn(20);
        $splitAction->method('getDelayMinutes')->willReturn(0);
        $splitAction->method('getData')->willReturnMap([['type', 'split']]);
        $splitAction->method('getParams')->willReturn([
            'variants' => [
                ['key' => 'a', 'weight' => 100, 'actions' => [['type' => 'tag_customer', 'params' => []]]],
            ],
        ]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$splitAction]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')
            ->with(self::callback(fn (array $context): bool => $context['ordo_split_variant'] === 'a'), []);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSplitActionSkipsMalformedActionSpecsInsideTheChosenVariant(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $splitAction = $this->createMock(CampaignAction::class);
        $splitAction->method('getCampaignId')->willReturn(1);
        $splitAction->method('getEntityId')->willReturn(20);
        $splitAction->method('getDelayMinutes')->willReturn(0);
        $splitAction->method('getData')->willReturnMap([['type', 'split']]);
        $splitAction->method('getParams')->willReturn([
            'variants' => [
                ['key' => 'a', 'weight' => 100, 'actions' => [
                    'not-an-array',
                    ['params' => []], // missing 'type'
                    ['type' => 123], // 'type' not a string
                    ['type' => 'tag_customer', 'params' => ['tag' => 'kept']],
                ]],
            ],
        ]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$splitAction]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')->with(self::anything(), ['tag' => 'kept']);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSplitActionWithNonArrayVariantsParamLogsAndSkips(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $splitAction = $this->createMock(CampaignAction::class);
        $splitAction->method('getCampaignId')->willReturn(1);
        $splitAction->method('getEntityId')->willReturn(20);
        $splitAction->method('getDelayMinutes')->willReturn(0);
        $splitAction->method('getData')->willReturnMap([['type', 'split']]);
        $splitAction->method('getParams')->willReturn(['variants' => 'not-an-array']);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$splitAction]));

        $this->logger->expects(self::once())->method('error');

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSplitActionSkipsMalformedVariantEntries(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $splitAction = $this->createMock(CampaignAction::class);
        $splitAction->method('getCampaignId')->willReturn(1);
        $splitAction->method('getEntityId')->willReturn(20);
        $splitAction->method('getDelayMinutes')->willReturn(0);
        $splitAction->method('getData')->willReturnMap([['type', 'split']]);
        $splitAction->method('getParams')->willReturn([
            'variants' => [
                'not-an-array',
                ['weight' => 100, 'actions' => []], // missing 'key'
                ['key' => '', 'weight' => 100, 'actions' => []], // empty 'key'
                ['key' => 'a', 'weight' => 'not-numeric', 'actions' => []], // non-numeric weight
                ['key' => 'kept', 'weight' => 100, 'actions' => [['type' => 'tag_customer', 'params' => []]]],
            ],
        ]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$splitAction]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')->with(
            self::callback(fn (array $context): bool => $context['ordo_split_variant'] === 'kept'),
            []
        );
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSplitActionWithNoUsableVariantsLogsAndSkips(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $splitAction = $this->createMock(CampaignAction::class);
        $splitAction->method('getCampaignId')->willReturn(1);
        $splitAction->method('getEntityId')->willReturn(20);
        $splitAction->method('getDelayMinutes')->willReturn(0);
        $splitAction->method('getData')->willReturnMap([['type', 'split']]);
        $splitAction->method('getParams')->willReturn(['variants' => []]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$splitAction]));

        $this->logger->expects(self::once())->method('error');

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSplitFollowedByDelayedActionPersistsVariantIntoScheduledContext(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $splitAction = $this->createMock(CampaignAction::class);
        $splitAction->method('getCampaignId')->willReturn(1);
        $splitAction->method('getEntityId')->willReturn(20);
        $splitAction->method('getDelayMinutes')->willReturn(0);
        $splitAction->method('getData')->willReturnMap([['type', 'split']]);
        $splitAction->method('getParams')->willReturn([
            'variants' => [['key' => 'a', 'weight' => 100, 'actions' => []]],
        ]);

        $delayedAction = $this->createStub(CampaignAction::class);
        $delayedAction->method('getCampaignId')->willReturn(1);
        $delayedAction->method('getEntityId')->willReturn(21);
        $delayedAction->method('getDelayMinutes')->willReturn(60);

        $this->actionCollectionFactory->method('create')
            ->willReturn($this->makeActionCollection([$splitAction, $delayedAction]));

        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->method('setCampaignId')->willReturnSelf();
        $scheduled->method('setResumeActionId')->willReturnSelf();
        $scheduled->expects(self::once())->method('setContext')
            ->with(self::callback(fn (array $context): bool => $context['ordo_split_variant'] === 'a'));
        $scheduled->method('setRunAt')->willReturnSelf();
        $this->campaignScheduledActionFactory->method('create')->willReturn($scheduled);

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

    /**
     * Builds the pair of collection mocks resumeScheduledAction() now needs: one for the small
     * "find the resume row's own sort_order" lookup query (campaign_id + entity_id filter,
     * getFirstItem()), one for the main "sort_order >= that" query (addCampaignFilter() +
     * getIterator()) - matching the two campaignActionCollectionFactory->create() calls the
     * method makes, in order.
     *
     * @param CampaignAction[] $mainRows
     */
    private function makeResumeActionCollections(?CampaignAction $resumeRow, array $mainRows): void
    {
        $lookupCollection = $this->createStub(ActionCollection::class);
        $lookupCollection->method('addFieldToFilter')->willReturnSelf();
        $lookupCollection->method('getFirstItem')->willReturn(
            $resumeRow ?? $this->createStub(CampaignAction::class)
        );

        $mainCollection = $this->createStub(ActionCollection::class);
        $mainCollection->method('addCampaignFilter')->willReturnSelf();
        $mainCollection->method('addFieldToFilter')->willReturnSelf();
        $mainCollection->method('getIterator')->willReturn(new \ArrayIterator($mainRows));

        $this->actionCollectionFactory->method('create')
            ->willReturnOnConsecutiveCalls($lookupCollection, $mainCollection);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResumeScheduledActionRunsFromResumePointOnward(): void
    {
        $resumeAction = $this->createMock(CampaignAction::class);
        $resumeAction->method('getEntityId')->willReturn(11);
        $resumeAction->method('getSortOrder')->willReturn(20);
        $resumeAction->method('getDelayMinutes')->willReturn(0);
        $resumeAction->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $resumeAction->method('getParams')->willReturn(['tag' => 'reactivated']);

        // The main query is now filtered to sort_order >= the resume row's own sort_order, so
        // an action that already ran before the resume point is never even loaded — never mind
        // executed — this time around.
        $this->makeResumeActionCollections($resumeAction, [$resumeAction]);

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')->with(self::anything(), ['tag' => 'reactivated']);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->resumeScheduledAction(1, 11, ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResumeScheduledActionFiltersMainQueryBySortOrderOfResumeRow(): void
    {
        $resumeAction = $this->createMock(CampaignAction::class);
        $resumeAction->method('getEntityId')->willReturn(11);
        $resumeAction->method('getSortOrder')->willReturn(30);
        $resumeAction->method('getDelayMinutes')->willReturn(0);
        $resumeAction->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $resumeAction->method('getParams')->willReturn([]);

        $lookupFilters = [];
        $lookupCollection = $this->createMock(ActionCollection::class);
        $lookupCollection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, $condition) use (&$lookupFilters, $lookupCollection) {
                $lookupFilters[] = [$field, $condition];
                return $lookupCollection;
            }
        );
        $lookupCollection->method('getFirstItem')->willReturn($resumeAction);

        $mainFilters = [];
        $mainCollection = $this->createMock(ActionCollection::class);
        $mainCollection->method('addCampaignFilter')->willReturnSelf();
        $mainCollection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, $condition) use (&$mainFilters, $mainCollection) {
                $mainFilters[] = [$field, $condition];
                return $mainCollection;
            }
        );
        $mainCollection->method('getIterator')->willReturn(new \ArrayIterator([$resumeAction]));

        $this->actionCollectionFactory->method('create')
            ->willReturnOnConsecutiveCalls($lookupCollection, $mainCollection);

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->resumeScheduledAction(1, 11, []);

        self::assertSame([['campaign_id', ['eq' => 1]], ['entity_id', ['eq' => 11]]], $lookupFilters);
        self::assertSame([['sort_order', ['gteq' => 30]]], $mainFilters);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResumeScheduledActionInjectsCampaignIdIntoContext(): void
    {
        $resumeAction = $this->createMock(CampaignAction::class);
        $resumeAction->method('getEntityId')->willReturn(11);
        $resumeAction->method('getSortOrder')->willReturn(20);
        $resumeAction->method('getDelayMinutes')->willReturn(0);
        $resumeAction->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $resumeAction->method('getParams')->willReturn([]);

        $this->makeResumeActionCollections($resumeAction, [$resumeAction]);

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')
            ->with(self::callback(fn (array $context): bool => $context['campaign_id'] === 9), []);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->resumeScheduledAction(9, 11, ['customer_id' => 1]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResumeScheduledActionDoesNothingWhenActionNoLongerExists(): void
    {
        // getFirstItem() on the lookup collection returns a fresh, id-less model - the standard
        // AbstractCollection "nothing matched" result - so getEntityId() === null here.
        $this->makeResumeActionCollections(null, []);

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

    /**
     * Regression test for the ROADMAP.md "No campaign entry dedup" gap: dispatch() must not
     * re-enter a campaign the customer is already mid-flow in (waiting on a delay_minutes resume).
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchSkipsACampaignTheCustomerAlreadyHasAPendingEntryIn(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));
        // Conditions/actions for ALL matched campaigns are still batch-loaded up front
        // (dispatch()'s own N+1 avoidance) before the per-campaign guard check - only the actual
        // per-campaign runActionsFrom() call is what the guard must prevent.
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([]));

        $this->campaignEntryGuard = $this->createMock(CampaignEntryGuard::class);
        $this->campaignEntryGuard->expects(self::once())->method('hasPendingEntry')->with(1, 42)->willReturn(true);

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 42]);
    }

    /**
     * A dispatch context with no identified customer at all can't be deduped against anything -
     * the guard must be skipped entirely, not treated as "always blocked" or "always allowed
     * against customer 0".
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchNeverConsultsTheGuardWhenContextHasNoCustomerId(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([]));

        $this->campaignEntryGuard = $this->createMock(CampaignEntryGuard::class);
        $this->campaignEntryGuard->expects(self::never())->method('hasPendingEntry');

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    /**
     * dispatchScheduledTrigger() (the scheduled_at/recurring_schedule entry path) gets the same
     * guard as dispatch() - both end at runActionsFrom($campaignId, ..., 0, $context).
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchScheduledTriggerSkipsACampaignTheCustomerAlreadyHasAPendingEntryIn(): void
    {
        $this->campaignCollectionFactory->method('create')->willReturn(
            $this->makeSingleCampaignCollection($this->makeCampaign(5))
        );
        $this->conditionCollectionFactory->expects(self::never())->method('create');

        $this->campaignEntryGuard = $this->createMock(CampaignEntryGuard::class);
        $this->campaignEntryGuard->expects(self::once())->method('hasPendingEntry')->with(5, 42)->willReturn(true);

        $this->makeDispatcher()->dispatchScheduledTrigger(5, ['customer_id' => 42]);
    }

    /**
     * resumeScheduledAction() is deliberately NOT guarded - it's the continuation of an already
     * in-flight chain, not a new entry, so it must run even if (hypothetically) a pending row
     * existed for this customer+campaign.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testResumeScheduledActionNeverConsultsTheGuard(): void
    {
        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getEntityId')->willReturn(11);
        $actionRow->method('getCampaignId')->willReturn(1);
        $actionRow->method('getSortOrder')->willReturn(10);
        $actionRow->method('getDelayMinutes')->willReturn(0);
        $actionRow->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionRow->method('getParams')->willReturn([]);
        $this->makeResumeActionCollections($actionRow, [$actionRow]);

        $action = $this->createMock(ActionInterface::class);
        $action->method('execute');
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->campaignEntryGuard = $this->createMock(CampaignEntryGuard::class);
        $this->campaignEntryGuard->expects(self::never())->method('hasPendingEntry');

        $this->makeDispatcher()->resumeScheduledAction(1, 11, ['customer_id' => 42]);
    }

    /**
     * Regression test for the ROADMAP.md "No time-zone-aware quiet hours" work: an action's own
     * entity_id is stamped into context as ordo_action_id right before execute() - the one piece
     * of identity a Send* action needs to hand QuietHoursGate for deferral.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testDispatchStampsTheActionsOwnEntityIdIntoContextBeforeExecuting(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$this->makeCampaign(1)]));
        $this->conditionCollectionFactory->method('create')->willReturn($this->makeConditionCollection([]));

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getCampaignId')->willReturn(1);
        $actionRow->method('getEntityId')->willReturn(77);
        $actionRow->method('getDelayMinutes')->willReturn(0);
        $actionRow->method('getData')->willReturnMap([['type', 'tag_customer']]);
        $actionRow->method('getParams')->willReturn([]);
        $this->actionCollectionFactory->method('create')->willReturn($this->makeActionCollection([$actionRow]));

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')
            ->with(self::callback(fn (array $context): bool => $context['ordo_action_id'] === 77), []);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    /**
     * deferActionUntil() (QuietHoursGate's public entry point into the scheduled-action write
     * path) must write the exact same row shape scheduleResume() does, just with an explicit
     * run_at instead of one computed from delay_minutes.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testDeferActionUntilWritesAScheduledActionRowWithTheGivenRunAt(): void
    {
        $scheduled = $this->createMock(CampaignScheduledAction::class);
        $scheduled->expects(self::once())->method('setCampaignId')->with(3);
        $scheduled->expects(self::once())->method('setResumeActionId')->with(15);
        $scheduled->expects(self::once())->method('setContext')->with(['customer_id' => 9]);
        $scheduled->expects(self::once())->method('setRunAt')->with('2026-01-16 08:00:00');
        $this->campaignScheduledActionFactory->method('create')->willReturn($scheduled);
        $this->campaignScheduledActionResource->expects(self::once())->method('save')->with($scheduled);

        $this->makeDispatcher()->deferActionUntil(3, 15, '2026-01-16 08:00:00', ['customer_id' => 9]);
    }
}
