<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\Campaign\Save;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignConditionFactory;
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
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;

class SaveTest extends AbstractAdminActionTestCase
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

    protected function setUp(): void
    {
        $this->campaignFactory = $this->createMock(CampaignFactory::class);
        $this->campaignResource = $this->createMock(CampaignResource::class);
        $this->campaignTriggerFactory = $this->createMock(CampaignTriggerFactory::class);
        $this->campaignTriggerResource = $this->createMock(CampaignTriggerResource::class);
        $this->triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $this->campaignConditionFactory = $this->createMock(CampaignConditionFactory::class);
        $this->campaignConditionResource = $this->createMock(CampaignConditionResource::class);
        $this->conditionCollectionFactory = $this->createMock(ConditionCollectionFactory::class);
        $this->campaignActionFactory = $this->createMock(CampaignActionFactory::class);
        $this->campaignActionResource = $this->createMock(CampaignActionResource::class);
        $this->actionCollectionFactory = $this->createMock(ActionCollectionFactory::class);
    }

    private function makeController(): Save
    {
        return new Save(
            $this->makeContext(),
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
            $this->actionCollectionFactory
        );
    }

    private function emptyTriggerCollection(): TriggerCollection
    {
        $collection = $this->createMock(TriggerCollection::class);
        $collection->method('addCampaignFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        return $collection;
    }

    public function testExecuteRedirectsImmediatelyWhenNoPostData(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->campaignFactory->expects(self::never())->method('create');

        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteSavesNewCampaignAndRedirectsToGrid(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'entity_id' => 0,
            'name' => 'Welcome',
            'enabled' => '1',
            'triggers' => ['triggers' => [['trigger_event' => 'customer_registered']]],
            'conditions' => ['conditions' => [['type' => 'has_tag', 'tag' => 'vip', 'params_json' => '']]],
            'actions' => ['actions' => [['type' => 'tag_customer', 'tag' => 'reordered', 'params_json' => '']]],
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $campaign = $this->createMock(Campaign::class);
        $campaign->expects(self::once())->method('setName')->with('Welcome');
        $campaign->expects(self::once())->method('setEnabled')->with(true);
        $campaign->method('getEntityId')->willReturn(7);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->campaignResource->expects(self::once())->method('save')->with($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());

        $trigger = $this->createMock(CampaignTrigger::class);
        $trigger->expects(self::once())->method('setData')->with([
            'campaign_id' => 7,
            'trigger_event' => 'customer_registered',
        ]);
        $this->campaignTriggerFactory->method('create')->willReturn($trigger);
        $this->campaignTriggerResource->expects(self::once())->method('save')->with($trigger);

        $emptyConditionCollection = $this->createMock(ConditionCollection::class);
        $emptyConditionCollection->method('addCampaignFilter');
        $emptyConditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($emptyConditionCollection);

        $condition = $this->createMock(CampaignCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['type'] === 'has_tag' && json_decode($data['params'], true) === ['tag' => 'vip']
        ));
        $this->campaignConditionFactory->method('create')->willReturn($condition);
        $this->campaignConditionResource->expects(self::once())->method('save')->with($condition);

        $emptyActionCollection = $this->createMock(ActionCollection::class);
        $emptyActionCollection->method('addCampaignFilter');
        $emptyActionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->actionCollectionFactory->method('create')->willReturn($emptyActionCollection);

        $action = $this->createMock(CampaignAction::class);
        $action->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['type'] === 'tag_customer' && json_decode($data['params'], true) === ['tag' => 'reordered']
        ));
        $this->campaignActionFactory->method('create')->willReturn($action);
        $this->campaignActionResource->expects(self::once())->method('save')->with($action);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteDeduplicatesRepeatedTriggerEvents(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'entity_id' => 0,
            'name' => 'Welcome',
            'triggers' => ['triggers' => [
                ['trigger_event' => 'order_placed'],
                ['trigger_event' => 'order_placed'],
                ['trigger_event' => ''],
            ]],
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(9);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());

        $trigger = $this->createMock(CampaignTrigger::class);
        $this->campaignTriggerFactory->method('create')->willReturn($trigger);
        $this->campaignTriggerResource->expects(self::once())->method('save')->with($trigger);

        $emptyConditionCollection = $this->createMock(ConditionCollection::class);
        $emptyConditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($emptyConditionCollection);

        $emptyActionCollection = $this->createMock(ActionCollection::class);
        $emptyActionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->actionCollectionFactory->method('create')->willReturn($emptyActionCollection);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    public function testExecuteLoadsExistingCampaignAndDeletesOldChildRows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'entity_id' => 7,
            'name' => 'Welcome',
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(7);
        $this->campaignFactory->method('create')->willReturn($campaign);
        $this->campaignResource->expects(self::once())->method('load')->with($campaign, 7);

        $existingTrigger = $this->createMock(CampaignTrigger::class);
        $triggerCollection = $this->createMock(TriggerCollection::class);
        $triggerCollection->method('addCampaignFilter');
        $triggerCollection->method('getIterator')->willReturn(new \ArrayIterator([$existingTrigger]));
        $this->triggerCollectionFactory->method('create')->willReturn($triggerCollection);
        $this->campaignTriggerResource->expects(self::once())->method('delete')->with($existingTrigger);

        $existingCondition = $this->createMock(CampaignCondition::class);
        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$existingCondition]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);
        $this->campaignConditionResource->expects(self::once())->method('delete')->with($existingCondition);

        $existingAction = $this->createMock(CampaignAction::class);
        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$existingAction]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);
        $this->campaignActionResource->expects(self::once())->method('delete')->with($existingAction);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    public function testExecuteFallsBackToJsonTextareaWhenNoDedicatedFields(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'conditions' => ['conditions' => [['type' => 'has_tag', 'params_json' => '{"raw":"value"}']]],
            'actions' => ['actions' => []],
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(1);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());

        $emptyConditionCollection = $this->createMock(ConditionCollection::class);
        $emptyConditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($emptyConditionCollection);

        $condition = $this->createMock(CampaignCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => json_decode($data['params'], true) === ['raw' => 'value']
        ));
        $this->campaignConditionFactory->method('create')->willReturn($condition);

        $emptyActionCollection = $this->createMock(ActionCollection::class);
        $emptyActionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->actionCollectionFactory->method('create')->willReturn($emptyActionCollection);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }

    public function testExecuteRedirectsToEditWhenBackParamSet(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['name' => 'Welcome']);
        $this->request->method('getParam')->with('back')->willReturn('1');

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(7);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());

        $emptyConditionCollection = $this->createMock(ConditionCollection::class);
        $emptyConditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($emptyConditionCollection);

        $emptyActionCollection = $this->createMock(ActionCollection::class);
        $emptyActionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->actionCollectionFactory->method('create')->willReturn($emptyActionCollection);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 7])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteRedirectsToEditWithErrorWhenSaveThrows(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['entity_id' => 3, 'name' => 'Welcome']);

        $campaign = $this->createMock(Campaign::class);
        $this->campaignFactory->method('create')->willReturn($campaign);
        $this->campaignResource->method('save')->willThrowException(new \RuntimeException('db down'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/edit', ['entity_id' => 3])->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteSkipsRowsWithoutType(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn([
            'conditions' => ['conditions' => [['type' => '']]],
            'actions' => ['actions' => [[]]],
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(1);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());

        $emptyConditionCollection = $this->createMock(ConditionCollection::class);
        $emptyConditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($emptyConditionCollection);

        $emptyActionCollection = $this->createMock(ActionCollection::class);
        $emptyActionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->actionCollectionFactory->method('create')->willReturn($emptyActionCollection);

        $this->campaignConditionFactory->expects(self::never())->method('create');
        $this->campaignActionFactory->expects(self::never())->method('create');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $controller->execute();
    }
}
