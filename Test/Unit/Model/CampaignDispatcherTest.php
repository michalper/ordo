<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection as ActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection as CampaignCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as TriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class CampaignDispatcherTest extends TestCase
{
    private CampaignCollectionFactory $campaignCollectionFactory;
    private TriggerCollectionFactory $triggerCollectionFactory;
    private ConditionCollectionFactory $conditionCollectionFactory;
    private ActionCollectionFactory $actionCollectionFactory;
    private ConditionPool $conditionPool;
    private ActionPool $actionPool;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->campaignCollectionFactory = $this->createMock(CampaignCollectionFactory::class);
        $this->triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $this->conditionCollectionFactory = $this->createMock(ConditionCollectionFactory::class);
        $this->actionCollectionFactory = $this->createMock(ActionCollectionFactory::class);
        $this->conditionPool = new ConditionPool();
        $this->actionPool = new ActionPool();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeDispatcher(): CampaignDispatcher
    {
        return new CampaignDispatcher(
            $this->campaignCollectionFactory,
            $this->triggerCollectionFactory,
            $this->conditionCollectionFactory,
            $this->actionCollectionFactory,
            $this->conditionPool,
            $this->actionPool,
            $this->logger
        );
    }

    private function makeTriggerCollection(array $campaignIds): TriggerCollection
    {
        $triggers = [];
        foreach ($campaignIds as $campaignId) {
            $trigger = $this->createMock(CampaignTrigger::class);
            $trigger->method('getCampaignId')->willReturn($campaignId);
            $triggers[] = $trigger;
        }

        $collection = $this->createMock(TriggerCollection::class);
        $collection->method('addTriggerEventFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator($triggers));

        return $collection;
    }

    private function makeCampaignCollection(array $campaigns): CampaignCollection
    {
        $collection = $this->createMock(CampaignCollection::class);
        $collection->method('addIdsFilter');
        $collection->method('addEnabledFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator($campaigns));

        return $collection;
    }

    public function testDispatchRunsActionWhenNoConditions(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));

        $campaign = $this->createMock(\Ordo\Automation\Model\Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$campaign]));

        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getData')->with('type')->willReturn('tag_customer');
        $actionRow->method('getParams')->willReturn(['tag' => 'vip']);

        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$actionRow]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $action = $this->createMock(ActionInterface::class);
        $action->expects(self::once())->method('execute')->with(self::anything(), ['tag' => 'vip']);
        $this->actionPool = new ActionPool(['tag_customer' => $action]);

        $this->makeDispatcher()->dispatch('order_placed', ['customer_id' => 1]);
    }

    public function testDispatchReturnsEarlyWhenNoTriggerMatches(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([]));

        $this->campaignCollectionFactory->expects(self::never())->method('create');

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    public function testDispatchDeduplicatesCampaignWithMultipleMatchingTriggers(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1, 1]));

        $campaign = $this->createMock(\Ordo\Automation\Model\Campaign::class);
        $campaign->method('getId')->willReturn(1);

        $campaignCollection = $this->createMock(CampaignCollection::class);
        $campaignCollection->expects(self::once())->method('addIdsFilter')->with([1]);
        $campaignCollection->method('addEnabledFilter');
        $campaignCollection->method('getIterator')->willReturn(new \ArrayIterator([$campaign]));
        $this->campaignCollectionFactory->method('create')->willReturn($campaignCollection);

        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    public function testDispatchSkipsCampaignWhenConditionNotSatisfied(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));

        $campaign = $this->createMock(\Ordo\Automation\Model\Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$campaign]));

        $conditionRow = $this->createMock(CampaignCondition::class);
        $conditionRow->method('getData')->with('type')->willReturn('has_tag');
        $conditionRow->method('getParams')->willReturn([]);

        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$conditionRow]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $condition = $this->createMock(ConditionInterface::class);
        $condition->method('isSatisfied')->willReturn(false);
        $this->conditionPool = new ConditionPool(['has_tag' => $condition]);

        $this->actionCollectionFactory->expects(self::never())->method('create');

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    public function testDispatchLogsAndSkipsWhenConditionTypeUnknown(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));

        $campaign = $this->createMock(\Ordo\Automation\Model\Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$campaign]));

        $conditionRow = $this->createMock(CampaignCondition::class);
        $conditionRow->method('getData')->with('type')->willReturn('unknown_type');

        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$conditionRow]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $this->logger->expects(self::once())->method('error');

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    public function testDispatchLogsAndContinuesWhenActionTypeUnknown(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));

        $campaign = $this->createMock(\Ordo\Automation\Model\Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$campaign]));

        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $actionRow = $this->createMock(CampaignAction::class);
        $actionRow->method('getData')->with('type')->willReturn('unknown_action');

        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$actionRow]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $this->logger->expects(self::once())->method('error');

        $this->makeDispatcher()->dispatch('order_placed', []);
    }

    public function testDispatchLogsAndContinuesWhenCampaignThrows(): void
    {
        $this->triggerCollectionFactory->method('create')->willReturn($this->makeTriggerCollection([1]));

        $campaign = $this->createMock(\Ordo\Automation\Model\Campaign::class);
        $campaign->method('getId')->willReturn(1);
        $this->campaignCollectionFactory->method('create')->willReturn($this->makeCampaignCollection([$campaign]));

        $this->conditionCollectionFactory->method('create')->willThrowException(new \RuntimeException('db error'));

        $this->logger->expects(self::once())->method('error');

        $this->makeDispatcher()->dispatch('order_placed', []);
    }
}
