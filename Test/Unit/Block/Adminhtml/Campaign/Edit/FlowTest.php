<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Campaign\Edit;

use Magento\Backend\Block\Template\Context;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\Registry;
use Ordo\Automation\Block\Adminhtml\Campaign\Edit\Flow;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\Campaign\TypeLabels;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\Config\Source\TriggerEvent;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection as ActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as TriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use PHPUnit\Framework\TestCase;

class FlowTest extends TestCase
{
    private Registry $registry;
    private TriggerCollectionFactory $triggerCollectionFactory;
    private ConditionCollectionFactory $conditionCollectionFactory;
    private ActionCollectionFactory $actionCollectionFactory;
    private TriggerEvent $triggerEventSource;
    private ConditionPool $conditionPool;
    private ActionPool $actionPool;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(Registry::class);
        $this->triggerCollectionFactory = $this->createMock(TriggerCollectionFactory::class);
        $this->conditionCollectionFactory = $this->createMock(ConditionCollectionFactory::class);
        $this->actionCollectionFactory = $this->createMock(ActionCollectionFactory::class);
        $this->triggerEventSource = $this->createMock(TriggerEvent::class);
        $this->triggerEventSource->method('toOptionArray')->willReturn([
            ['value' => 'order_placed', 'label' => __('Order Placed')],
        ]);
        $this->conditionPool = $this->createMock(ConditionPool::class);
        $this->conditionPool->method('getAvailableTypes')->willReturn(['order_total_gte', 'tag']);
        $this->actionPool = $this->createMock(ActionPool::class);
        $this->actionPool->method('getAvailableTypes')->willReturn(['add_tag', 'send_email']);
    }

    private function makeBlock(): Flow
    {
        $escaper = $this->createMock(\Magento\Framework\Escaper::class);
        $escaper->method('escapeHtml')->willReturnArgument(0);
        $escaper->method('escapeHtmlAttr')->willReturnArgument(0);

        $context = $this->createMock(Context::class);
        $context->method('getEscaper')->willReturn($escaper);

        return new Flow(
            $context,
            $this->registry,
            $this->triggerCollectionFactory,
            $this->conditionCollectionFactory,
            $this->actionCollectionFactory,
            $this->triggerEventSource,
            $this->conditionPool,
            $this->actionPool,
            new TypeLabels(),
            [],
            $this->createMock(JsonHelper::class),
            $this->createMock(DirectoryHelper::class)
        );
    }

    private function triggerCollectionWith(array $triggerEvents): TriggerCollection
    {
        $triggers = [];
        foreach ($triggerEvents as $triggerEvent) {
            $trigger = $this->createMock(CampaignTrigger::class);
            $trigger->method('getTriggerEvent')->willReturn($triggerEvent);
            $triggers[] = $trigger;
        }

        $collection = $this->createMock(TriggerCollection::class);
        $collection->method('addCampaignFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator($triggers));

        return $collection;
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

    public function testGetTriggerEventLabelsMapsValueToLabel(): void
    {
        self::assertSame(['order_placed' => 'Order Placed'], $this->makeBlock()->getTriggerEventLabels());
    }

    public function testGetConditionTypeLabelsMapsTypeToLabel(): void
    {
        self::assertSame(
            ['order_total_gte' => 'Order Total ≥', 'tag' => 'Has Tag'],
            $this->makeBlock()->getConditionTypeLabels()
        );
    }

    public function testGetActionTypeLabelsMapsTypeToLabel(): void
    {
        self::assertSame(
            ['add_tag' => 'Add Tag', 'send_email' => 'Send Email'],
            $this->makeBlock()->getActionTypeLabels()
        );
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
        $this->registry->method('registry')->with('ordo_campaign')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->triggerCollectionWith(['order_placed']));

        $condition = $this->createMock(CampaignCondition::class);
        $condition->method('getType')->willReturn('order_total_gte');
        $condition->method('getParamsJson')->willReturn('{"amount":"100"}');
        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$condition]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $action = $this->createMock(CampaignAction::class);
        $action->method('getType')->willReturn('add_tag');
        $action->method('getParamsJson')->willReturn('{"tag":"vip"}');
        $action->method('getDelayMinutes')->willReturn(1440);
        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('setOrder');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$action]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $json = $this->makeBlock()->getFlowDataJson();
        $data = json_decode($json, true)['drawflow']['Home']['data'];

        self::assertCount(3, $data);
        self::assertStringContainsString('order_placed', $data[1]['html']);
        self::assertStringContainsString('order_total_gte', $data[2]['html']);
        self::assertStringContainsString('{"amount":"100"}', $data[2]['html']);
        self::assertStringContainsString('add_tag', $data[3]['html']);
        self::assertStringContainsString('{"tag":"vip"}', $data[3]['html']);
        self::assertStringContainsString('data-field="delay_minutes" value="1440"', $data[3]['html']);

        // trigger has no input
        self::assertSame([], $data[1]['inputs']);

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
        $this->registry->method('registry')->with('ordo_campaign')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->triggerCollectionWith(['order_placed']));

        $emptyConditionCollection = $this->createMock(ConditionCollection::class);
        $emptyConditionCollection->method('addCampaignFilter');
        $emptyConditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($emptyConditionCollection);

        $action = $this->createMock(CampaignAction::class);
        $action->method('getType')->willReturn('send_email');
        $action->method('getParamsJson')->willReturn('{}');
        $action->method('getDelayMinutes')->willReturn(0);
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

    public function testGetFlowDataJsonConnectsMultipleTriggersToSameCondition(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->registry->method('registry')->with('ordo_campaign')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn(
            $this->triggerCollectionWith(['order_placed', 'order_placed'])
        );

        $condition = $this->createMock(CampaignCondition::class);
        $condition->method('getType')->willReturn('tag');
        $condition->method('getParamsJson')->willReturn('{}');
        $conditionCollection = $this->createMock(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$condition]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $emptyActionCollection = $this->createMock(ActionCollection::class);
        $emptyActionCollection->method('addCampaignFilter');
        $emptyActionCollection->method('setOrder');
        $emptyActionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->actionCollectionFactory->method('create')->willReturn($emptyActionCollection);

        $json = $this->makeBlock()->getFlowDataJson();
        $data = json_decode($json, true)['drawflow']['Home']['data'];

        // two trigger nodes + one condition node
        self::assertCount(3, $data);
        self::assertSame([], $data[1]['inputs']);
        self::assertSame([], $data[2]['inputs']);

        // both triggers connect to the single condition node (id 3)
        self::assertSame('3', $data[1]['outputs']['output_1']['connections'][0]['node']);
        self::assertSame('3', $data[2]['outputs']['output_1']['connections'][0]['node']);
    }

    public function testGetFlowDataJsonChainsSecondActionToFirstNotToUpstream(): void
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(6);
        $this->registry->method('registry')->with('ordo_campaign')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->triggerCollectionWith(['order_placed']));

        $emptyConditionCollection = $this->createMock(ConditionCollection::class);
        $emptyConditionCollection->method('addCampaignFilter');
        $emptyConditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($emptyConditionCollection);

        $firstAction = $this->createMock(CampaignAction::class);
        $firstAction->method('getType')->willReturn('add_tag');
        $firstAction->method('getParamsJson')->willReturn('{}');
        $firstAction->method('getDelayMinutes')->willReturn(0);

        $secondAction = $this->createMock(CampaignAction::class);
        $secondAction->method('getType')->willReturn('send_email');
        $secondAction->method('getParamsJson')->willReturn('{}');
        $secondAction->method('getDelayMinutes')->willReturn(0);

        $actionCollection = $this->createMock(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('setOrder');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$firstAction, $secondAction]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $json = $this->makeBlock()->getFlowDataJson();
        $data = json_decode($json, true)['drawflow']['Home']['data'];

        // trigger (1) -> first action (2) -> second action (3), not trigger -> both actions.
        self::assertCount(3, $data);
        self::assertSame('2', $data[1]['outputs']['output_1']['connections'][0]['node']);
        self::assertSame('3', $data[2]['outputs']['output_1']['connections'][0]['node']);
        self::assertSame([], $data[3]['outputs']['output_1']['connections']);
    }

    public function testGetFieldsConfigListsKnownConditionAndActionFields(): void
    {
        $config = $this->makeBlock()->getFieldsConfig();

        self::assertSame(
            [['name' => 'tag', 'label' => 'Tag']],
            $config['condition']['tag']
        );
        self::assertSame(
            [
                ['name' => 'rule_id', 'label' => 'Cart price rule ID'],
                ['name' => 'prefix', 'label' => 'Coupon code prefix'],
            ],
            $config['action']['generate_coupon']
        );
    }

    public function testGetFieldsConfigJsonEncodesTheSameConfig(): void
    {
        $block = $this->makeBlock();

        self::assertSame(json_encode($block->getFieldsConfig()), $block->getFieldsConfigJson());
    }
}
