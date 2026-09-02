<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Magento\Framework\App\CacheInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\CampaignSaveProcessor;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection as ActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as TriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CampaignSaveProcessorTest extends TestCase
{
    private CampaignFactory $campaignFactory;
    private CampaignResource $campaignResource;
    private CampaignTriggerFactory $campaignTriggerFactory;
    private CampaignTriggerResource $campaignTriggerResource;
    private TriggerCollectionFactory $triggerCollectionFactory;
    private CampaignConditionFactory $campaignConditionFactory;
    private CampaignConditionResource $campaignConditionResource;
    private ConditionCollectionFactory $conditionCollectionFactory;
    private CampaignActionFactory $campaignActionFactory;
    private CampaignActionResource $campaignActionResource;
    private ActionCollectionFactory $actionCollectionFactory;
    private CacheInterface $cache;

    protected function setUp(): void
    {
        $this->campaignFactory = $this->createMock(CampaignFactory::class);
        $this->campaignResource = $this->createMock(CampaignResource::class);
        $this->campaignTriggerFactory = $this->createStub(CampaignTriggerFactory::class);
        $this->campaignTriggerResource = $this->createMock(CampaignTriggerResource::class);
        $this->triggerCollectionFactory = $this->createStub(TriggerCollectionFactory::class);
        $this->campaignConditionFactory = $this->createMock(CampaignConditionFactory::class);
        $this->campaignConditionResource = $this->createMock(CampaignConditionResource::class);
        $this->conditionCollectionFactory = $this->createStub(ConditionCollectionFactory::class);
        $this->campaignActionFactory = $this->createMock(CampaignActionFactory::class);
        $this->campaignActionResource = $this->createMock(CampaignActionResource::class);
        $this->actionCollectionFactory = $this->createStub(ActionCollectionFactory::class);
        $this->cache = $this->createMock(CacheInterface::class);
    }

    private function makeProcessor(): CampaignSaveProcessor
    {
        return new CampaignSaveProcessor(
            $this->campaignFactory,
            $this->campaignResource,
            $this->campaignTriggerFactory,
            $this->campaignTriggerResource,
            $this->triggerCollectionFactory,
            $this->campaignConditionFactory,
            $this->campaignConditionResource,
            $this->conditionCollectionFactory,
            $this->campaignActionFactory,
            $this->campaignActionResource,
            $this->actionCollectionFactory,
            $this->cache
        );
    }

    private function emptyTriggerCollection(): TriggerCollection
    {
        $collection = $this->createStub(TriggerCollection::class);
        $collection->method('addCampaignFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        return $collection;
    }

    private function emptyConditionCollection(): ConditionCollection
    {
        $collection = $this->createStub(ConditionCollection::class);
        $collection->method('addCampaignFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        return $collection;
    }

    private function emptyActionCollection(): ActionCollection
    {
        $collection = $this->createStub(ActionCollection::class);
        $collection->method('addCampaignFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSavesNewCampaignAndCleansCache(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->expects(self::once())->method('setName')->with('Welcome');
        $campaign->expects(self::once())->method('setEnabled')->with(true);
        $campaign->method('getEntityId')->willReturn(7);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->campaignResource->expects(self::once())->method('save')->with($campaign);
        $this->campaignResource->expects(self::never())->method('load');

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());

        $trigger = $this->createMock(CampaignTrigger::class);
        $trigger->expects(self::once())->method('setData')->with([
            'campaign_id' => 7,
            'trigger_event' => 'customer_registered',
        ]);
        $this->campaignTriggerFactory->method('create')->willReturn($trigger);
        $this->campaignTriggerResource->expects(self::once())->method('save')->with($trigger);

        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $condition = $this->createMock(CampaignCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['type'] === 'has_tag' && json_decode($data['params'], true) === ['tag' => 'vip']
        ));
        $this->campaignConditionFactory->method('create')->willReturn($condition);
        $this->campaignConditionResource->expects(self::once())->method('save')->with($condition);

        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $action = $this->createMock(CampaignAction::class);
        $action->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['type'] === 'tag_customer'
                && json_decode($data['params'], true) === ['tag' => 'reordered']
                && $data['delay_minutes'] === 60
        ));
        $this->campaignActionFactory->method('create')->willReturn($action);
        $this->campaignActionResource->expects(self::once())->method('save')->with($action);

        $this->cache->expects(self::once())->method('clean')->with([CampaignDispatcher::CACHE_TAG]);

        $result = $processor->process([
            'entity_id' => 0,
            'name' => 'Welcome',
            'enabled' => '1',
            'triggers' => ['triggers' => [['trigger_event' => 'customer_registered']]],
            'conditions' => ['conditions' => [['type' => 'has_tag', 'tag' => 'vip', 'params_json' => '']]],
            'actions' => ['actions' => [['type' => 'tag_customer', 'tag' => 'reordered', 'params_json' => '', 'delay_minutes' => '60']]],
        ]);

        self::assertSame($campaign, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessDeduplicatesRepeatedTriggerEvents(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(9);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());

        $trigger = $this->createStub(CampaignTrigger::class);
        $this->campaignTriggerFactory->method('create')->willReturn($trigger);
        $this->campaignTriggerResource->expects(self::once())->method('save')->with($trigger);

        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $processor->process([
            'entity_id' => 0,
            'name' => 'Welcome',
            'triggers' => ['triggers' => [
                ['trigger_event' => 'order_placed'],
                ['trigger_event' => 'order_placed'],
                ['trigger_event' => ''],
            ]],
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessLoadsExistingCampaignAndDeletesOldChildRows(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(7);
        $this->campaignFactory->method('create')->willReturn($campaign);
        $this->campaignResource->expects(self::once())->method('load')->with($campaign, 7);

        $existingTrigger = $this->createStub(CampaignTrigger::class);
        $triggerCollection = $this->createStub(TriggerCollection::class);
        $triggerCollection->method('addCampaignFilter');
        $triggerCollection->method('getIterator')->willReturn(new \ArrayIterator([$existingTrigger]));
        $this->triggerCollectionFactory->method('create')->willReturn($triggerCollection);
        $this->campaignTriggerResource->expects(self::once())->method('delete')->with($existingTrigger);

        $existingCondition = $this->createStub(CampaignCondition::class);
        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$existingCondition]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);
        $this->campaignConditionResource->expects(self::once())->method('delete')->with($existingCondition);

        $existingAction = $this->createStub(CampaignAction::class);
        $actionCollection = $this->createStub(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$existingAction]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);
        $this->campaignActionResource->expects(self::once())->method('delete')->with($existingAction);

        $processor->process(['entity_id' => 7, 'name' => 'Welcome']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessFallsBackToJsonTextareaWhenNoDedicatedFields(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(1);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());
        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $condition = $this->createMock(CampaignCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => json_decode($data['params'], true) === ['raw' => 'value']
        ));
        $this->campaignConditionFactory->method('create')->willReturn($condition);

        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $processor->process([
            'conditions' => ['conditions' => [['type' => 'has_tag', 'params_json' => '{"raw":"value"}']]],
            'actions' => ['actions' => []],
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessDefaultsDelayMinutesToZeroWhenAbsent(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(1);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());
        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $action = $this->createMock(CampaignAction::class);
        $action->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['delay_minutes'] === 0
        ));
        $this->campaignActionFactory->method('create')->willReturn($action);

        $processor->process(['actions' => ['actions' => [['type' => 'add_tag', 'tag' => 'vip']]]]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSkipsRowsWithoutType(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(1);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());
        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $this->campaignConditionFactory->expects(self::never())->method('create');
        $this->campaignActionFactory->expects(self::never())->method('create');

        $processor->process([
            'conditions' => ['conditions' => [['type' => '']]],
            'actions' => ['actions' => [[]]],
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessPropagatesExceptionFromSaveWithoutCleaningCache(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createStub(Campaign::class);
        $this->campaignFactory->method('create')->willReturn($campaign);
        $this->campaignResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->cache->expects(self::never())->method('clean');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db down');

        $processor->process(['entity_id' => 3, 'name' => 'Welcome']);
    }
}
