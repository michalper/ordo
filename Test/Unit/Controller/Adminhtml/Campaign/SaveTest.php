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
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection as ActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;

class SaveTest extends AbstractAdminActionTestCase
{
    private CampaignFactory $campaignFactory;
    private CampaignResource $campaignResource;
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
            $this->campaignConditionFactory,
            $this->campaignConditionResource,
            $this->conditionCollectionFactory,
            $this->campaignActionFactory,
            $this->campaignActionResource,
            $this->actionCollectionFactory
        );
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
            'trigger_event' => 'customer_registered',
            'enabled' => '1',
            'conditions' => ['conditions' => [['type' => 'has_tag', 'tag' => 'vip', 'params_json' => '']]],
            'actions' => ['actions' => [['type' => 'tag_customer', 'tag' => 'reordered', 'params_json' => '']]],
        ]);
        $this->request->method('getParam')->with('back')->willReturn(null);

        $campaign = $this->createMock(Campaign::class);
        $campaign->expects(self::once())->method('setName')->with('Welcome');
        $campaign->expects(self::once())->method('setTriggerEvent')->with('customer_registered');
        $campaign->expects(self::once())->method('setEnabled')->with(true);
        $campaign->method('getEntityId')->willReturn(7);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->campaignResource->expects(self::once())->method('save')->with($campaign);

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

    public function testExecuteRedirectsToEditWhenBackParamSet(): void
    {
        $controller = $this->makeController();
        $this->request->method('getPostValue')->willReturn(['name' => 'Welcome']);
        $this->request->method('getParam')->with('back')->willReturn('1');

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(7);
        $this->campaignFactory->method('create')->willReturn($campaign);

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
