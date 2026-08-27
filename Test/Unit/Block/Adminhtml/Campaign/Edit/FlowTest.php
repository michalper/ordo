<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Campaign\Edit;

use Magento\Backend\Block\Template\Context;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\Registry;
use Ordo\Automation\Block\Adminhtml\Campaign\Edit\Flow;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\Config\Source\TriggerEvent;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection as ActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use PHPUnit\Framework\TestCase;

class FlowTest extends TestCase
{
    private Registry $registry;
    private ConditionCollectionFactory $conditionCollectionFactory;
    private ActionCollectionFactory $actionCollectionFactory;
    private TriggerEvent $triggerEventSource;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(Registry::class);
        $this->conditionCollectionFactory = $this->createMock(ConditionCollectionFactory::class);
        $this->actionCollectionFactory = $this->createMock(ActionCollectionFactory::class);
        $this->triggerEventSource = $this->createMock(TriggerEvent::class);
        $this->triggerEventSource->method('toOptionArray')->willReturn([
            ['value' => 'order_placed', 'label' => __('Order Placed')],
        ]);
    }

    private function makeBlock(): Flow
    {
        $escaper = $this->createMock(\Magento\Framework\Escaper::class);
        $escaper->method('escapeHtml')->willReturnArgument(0);

        $context = $this->createMock(Context::class);
        $context->method('getEscaper')->willReturn($escaper);

        return new Flow(
            $context,
            $this->registry,
            $this->conditionCollectionFactory,
            $this->actionCollectionFactory,
            $this->triggerEventSource,
            [],
            $this->createMock(JsonHelper::class),
            $this->createMock(DirectoryHelper::class)
        );
    }

    public function testHasCampaignFalseWhenNoneRegistered(): void
    {
        $this->registry->method('registry')->with('ordo_campaign')->willReturn(null);

        self::assertFalse($this->makeBlock()->hasCampaign());
    }

    public function testHasCampaignFalseForUnsavedCampaign(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(null);
        $this->registry->method('registry')->with('ordo_campaign')->willReturn($campaign);

        self::assertFalse($this->makeBlock()->hasCampaign());
    }

    public function testGetFlowDataJsonReturnsEmptyObjectWithoutCampaign(): void
    {
        $this->registry->method('registry')->willReturn(null);

        self::assertSame('{}', $this->makeBlock()->getFlowDataJson());
    }

    public function testGetFlowDataJsonChainsTriggerConditionAndAction(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(3);
        $campaign->method('getTriggerEvent')->willReturn('order_placed');
        $this->registry->method('registry')->with('ordo_campaign')->willReturn($campaign);

        $condition = $this->createMock(CampaignCondition::class);
        $condition->method('getData')->with('type')->willReturn('order_total_gte');
        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$condition]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $action = $this->createMock(CampaignAction::class);
        $action->method('getData')->with('type')->willReturn('add_tag');
        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('setOrder');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$action]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $json = $this->makeBlock()->getFlowDataJson();
        $data = json_decode($json, true)['drawflow']['Home']['data'];

        self::assertCount(3, $data);
        self::assertStringContainsString('Order Placed', $data[1]['html']);
        self::assertStringContainsString('order_total_gte', $data[2]['html']);
        self::assertStringContainsString('add_tag', $data[3]['html']);

        // trigger -> condition
        self::assertSame('2', $data[1]['outputs']['output_1']['connections'][0]['node']);
        // condition -> action
        self::assertSame('3', $data[2]['outputs']['output_1']['connections'][0]['node']);
        // action has no downstream
        self::assertSame([], $data[3]['outputs']['output_1']['connections']);
    }

    public function testGetFlowDataJsonConnectsTriggerDirectlyToActionsWhenNoConditions(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(4);
        $campaign->method('getTriggerEvent')->willReturn('order_placed');
        $this->registry->method('registry')->with('ordo_campaign')->willReturn($campaign);

        $emptyConditionCollection = $this->createMock(ConditionCollection::class);
        $emptyConditionCollection->method('addCampaignFilter');
        $emptyConditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($emptyConditionCollection);

        $action = $this->createMock(CampaignAction::class);
        $action->method('getData')->with('type')->willReturn('send_email');
        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('setOrder');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$action]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $json = $this->makeBlock()->getFlowDataJson();
        $data = json_decode($json, true)['drawflow']['Home']['data'];

        self::assertCount(2, $data);
        self::assertSame('2', $data[1]['outputs']['output_1']['connections'][0]['node']);
    }
}
