<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Ordo\Automation\Controller\Adminhtml\Campaign\Export;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection as ActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as TriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ExportTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenEntityIdMissing(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 0]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $controller = new Export(
            $context,
            $this->createStub(RawFactory::class),
            $this->createStub(CampaignFactory::class),
            $this->createStub(CampaignResource::class),
            $this->createStub(TriggerCollectionFactory::class),
            $this->createStub(ConditionCollectionFactory::class),
            $this->createStub(ActionCollectionFactory::class)
        );

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenCampaignNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);
        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(null);
        $campaignFactory = $this->createStub(CampaignFactory::class);
        $campaignFactory->method('create')->willReturn($campaign);

        $controller = new Export(
            $context,
            $this->createStub(RawFactory::class),
            $campaignFactory,
            $this->createStub(CampaignResource::class),
            $this->createStub(TriggerCollectionFactory::class),
            $this->createStub(ConditionCollectionFactory::class),
            $this->createStub(ActionCollectionFactory::class)
        );

        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturnsJsonDownloadOnSuccess(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $campaign->method('getName')->willReturn('Welcome series');
        $campaign->method('isEnabled')->willReturn(true);
        $campaign->method('getConditionLogic')->willReturn('all');
        $campaignFactory = $this->createStub(CampaignFactory::class);
        $campaignFactory->method('create')->willReturn($campaign);

        $trigger = $this->createStub(CampaignTrigger::class);
        $trigger->method('getTriggerEvent')->willReturn('order_placed');
        $trigger->method('getParams')->willReturn([]);
        $triggerCollection = $this->createStub(TriggerCollection::class);
        $triggerCollection->method('addCampaignFilter')->willReturnSelf();
        $triggerCollection->method('getIterator')->willReturn(new \ArrayIterator([$trigger]));
        $triggerCollectionFactory = $this->createStub(TriggerCollectionFactory::class);
        $triggerCollectionFactory->method('create')->willReturn($triggerCollection);

        $condition = $this->createStub(CampaignCondition::class);
        $condition->method('getType')->willReturn('order_total_gte');
        $condition->method('getParams')->willReturn(['amount' => 100]);
        $condition->method('getSortOrder')->willReturn(0);
        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter')->willReturnSelf();
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$condition]));
        $conditionCollectionFactory = $this->createStub(ConditionCollectionFactory::class);
        $conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $action = $this->createStub(CampaignAction::class);
        $action->method('getType')->willReturn('send_email');
        $action->method('getParams')->willReturn(['template' => 'welcome']);
        $action->method('getSortOrder')->willReturn(0);
        $action->method('getDelayMinutes')->willReturn(0);
        $actionCollection = $this->createStub(ActionCollection::class);
        $actionCollection->method('addCampaignFilter')->willReturnSelf();
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$action]));
        $actionCollectionFactory = $this->createStub(ActionCollectionFactory::class);
        $actionCollectionFactory->method('create')->willReturn($actionCollection);

        $raw = $this->createMock(Raw::class);
        $raw->expects(self::exactly(2))->method('setHeader')->willReturnSelf();
        $raw->expects(self::once())->method('setContents')
            ->with(self::callback(function ($json) {
                $decoded = json_decode($json, true);
                return $decoded['export_type'] === 'ordo_campaign'
                    && $decoded['name'] === 'Welcome series'
                    && $decoded['triggers'][0]['trigger_event'] === 'order_placed'
                    && $decoded['conditions'][0]['type'] === 'order_total_gte'
                    && $decoded['actions'][0]['type'] === 'send_email'
                    && !isset($decoded['entity_id']);
            }))
            ->willReturnSelf();
        $resultRawFactory = $this->createStub(RawFactory::class);
        $resultRawFactory->method('create')->willReturn($raw);

        $controller = new Export(
            $context,
            $resultRawFactory,
            $campaignFactory,
            $this->createStub(CampaignResource::class),
            $triggerCollectionFactory,
            $conditionCollectionFactory,
            $actionCollectionFactory
        );

        self::assertSame($raw, $controller->execute());
    }
}
