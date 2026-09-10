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
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\WhatsAppTemplate;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection as ActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as TriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ContentBlock\Collection as ContentBlockCollection;
use Ordo\Automation\Model\ResourceModel\ContentBlock\CollectionFactory as ContentBlockCollectionFactory;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate\Collection as WhatsAppTemplateCollection;
use Ordo\Automation\Model\ResourceModel\WhatsAppTemplate\CollectionFactory as WhatsAppTemplateCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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
    private ContentBlockCollectionFactory $contentBlockCollectionFactory;
    private WhatsAppTemplateCollectionFactory $whatsAppTemplateCollectionFactory;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(Registry::class);
        $this->triggerCollectionFactory = $this->createStub(TriggerCollectionFactory::class);
        $this->conditionCollectionFactory = $this->createStub(ConditionCollectionFactory::class);
        $this->actionCollectionFactory = $this->createStub(ActionCollectionFactory::class);
        $this->contentBlockCollectionFactory = $this->createStub(ContentBlockCollectionFactory::class);
        $contentBlockCollection = $this->createStub(ContentBlockCollection::class);
        $contentBlockCollection->method('addFieldToFilter')->willReturnSelf();
        $contentBlockCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->contentBlockCollectionFactory->method('create')->willReturn($contentBlockCollection);
        $this->whatsAppTemplateCollectionFactory = $this->createStub(WhatsAppTemplateCollectionFactory::class);
        $whatsAppTemplateCollection = $this->createStub(WhatsAppTemplateCollection::class);
        $whatsAppTemplateCollection->method('addApprovedFilter')->willReturnSelf();
        $whatsAppTemplateCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->whatsAppTemplateCollectionFactory->method('create')->willReturn($whatsAppTemplateCollection);
        $this->triggerEventSource = $this->createStub(TriggerEvent::class);
        $this->triggerEventSource->method('toOptionArray')->willReturn([
            ['value' => 'order_placed', 'label' => __('Order Placed')],
        ]);
        $this->conditionPool = $this->createStub(ConditionPool::class);
        $this->conditionPool->method('getAvailableTypes')->willReturn(['order_total_gte', 'tag']);
        $this->actionPool = $this->createStub(ActionPool::class);
        $this->actionPool->method('getAvailableTypes')->willReturn(['add_tag', 'send_email']);
    }

    private function makeBlock(): Flow
    {
        $escaper = $this->createStub(\Magento\Framework\Escaper::class);
        $escaper->method('escapeHtml')->willReturnArgument(0);
        $escaper->method('escapeHtmlAttr')->willReturnArgument(0);

        $context = $this->createStub(Context::class);
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
            $this->contentBlockCollectionFactory,
            $this->whatsAppTemplateCollectionFactory,
            [],
            $this->createStub(JsonHelper::class),
            $this->createStub(DirectoryHelper::class)
        );
    }

    private function triggerCollectionWith(array $triggerEvents): TriggerCollection
    {
        $triggers = [];
        foreach ($triggerEvents as $triggerEvent) {
            $trigger = $this->createStub(CampaignTrigger::class);
            $trigger->method('getTriggerEvent')->willReturn($triggerEvent);
            $triggers[] = $trigger;
        }

        $collection = $this->createStub(TriggerCollection::class);
        $collection->method('addCampaignFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator($triggers));

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasCampaignFalseWhenNoneRegistered(): void
    {
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', null]]);

        self::assertFalse($this->makeBlock()->hasCampaign());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasCampaignFalseForUnsavedCampaign(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(null);
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', $campaign]]);

        self::assertFalse($this->makeBlock()->hasCampaign());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTriggerEventLabelsMapsValueToLabel(): void
    {
        self::assertSame(['order_placed' => 'Order Placed'], $this->makeBlock()->getTriggerEventLabels());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetConditionTypeLabelsMapsTypeToLabel(): void
    {
        self::assertSame(
            ['order_total_gte' => 'Order Total ≥', 'tag' => 'Has Tag'],
            $this->makeBlock()->getConditionTypeLabels()
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetActionTypeLabelsMapsTypeToLabel(): void
    {
        self::assertSame(
            ['add_tag' => 'Add Tag', 'send_email' => 'Send Email', 'split' => 'A/B Split Test'],
            $this->makeBlock()->getActionTypeLabels()
        );
    }

    /**
     * 'split' is a reserved pseudo-type CampaignDispatcher::runSplit() intercepts before the
     * ActionPool lookup (same shape as the 'group' condition type) - it must still appear in the
     * Flow canvas's own action type list, even though ActionPool itself never registers it, or
     * an admin could never add one.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGetActionTypesAppendsTheReservedSplitPseudoType(): void
    {
        self::assertSame(['add_tag', 'send_email', 'split'], $this->makeBlock()->getActionTypes());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFlowDataJsonReturnsEmptyObjectWithoutCampaign(): void
    {
        $this->registry->method('registry')->willReturn(null);

        self::assertSame('{}', $this->makeBlock()->getFlowDataJson());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFlowDataJsonChainsTriggerConditionAndAction(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(3);
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', $campaign]]);

        $this->triggerCollectionFactory->method('create')->willReturn($this->triggerCollectionWith(['order_placed']));

        $condition = $this->createStub(CampaignCondition::class);
        $condition->method('getType')->willReturn('order_total_gte');
        $condition->method('getParamsJson')->willReturn('{"amount":"100"}');
        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$condition]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $action = $this->createStub(CampaignAction::class);
        $action->method('getType')->willReturn('add_tag');
        $action->method('getParamsJson')->willReturn('{"tag":"vip"}');
        $action->method('getDelayMinutes')->willReturn(1440);
        $actionCollection = $this->createStub(ActionCollection::class);
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

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFlowDataJsonConnectsTriggerDirectlyToActionsWhenNoConditions(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(4);
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', $campaign]]);

        $this->triggerCollectionFactory->method('create')->willReturn($this->triggerCollectionWith(['order_placed']));

        $emptyConditionCollection = $this->createStub(ConditionCollection::class);
        $emptyConditionCollection->method('addCampaignFilter');
        $emptyConditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($emptyConditionCollection);

        $action = $this->createStub(CampaignAction::class);
        $action->method('getType')->willReturn('send_email');
        $action->method('getParamsJson')->willReturn('{}');
        $action->method('getDelayMinutes')->willReturn(0);
        $actionCollection = $this->createStub(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('setOrder');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$action]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $json = $this->makeBlock()->getFlowDataJson();
        $data = json_decode($json, true)['drawflow']['Home']['data'];

        self::assertCount(2, $data);
        self::assertSame('2', $data[1]['outputs']['output_1']['connections'][0]['node']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFlowDataJsonConnectsMultipleTriggersToSameCondition(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', $campaign]]);

        $this->triggerCollectionFactory->method('create')->willReturn(
            $this->triggerCollectionWith(['order_placed', 'order_placed'])
        );

        $condition = $this->createStub(CampaignCondition::class);
        $condition->method('getType')->willReturn('tag');
        $condition->method('getParamsJson')->willReturn('{}');
        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([$condition]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $emptyActionCollection = $this->createStub(ActionCollection::class);
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

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFlowDataJsonChainsSecondActionToFirstNotToUpstream(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(6);
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', $campaign]]);

        $this->triggerCollectionFactory->method('create')->willReturn($this->triggerCollectionWith(['order_placed']));

        $emptyConditionCollection = $this->createStub(ConditionCollection::class);
        $emptyConditionCollection->method('addCampaignFilter');
        $emptyConditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($emptyConditionCollection);

        $firstAction = $this->createStub(CampaignAction::class);
        $firstAction->method('getType')->willReturn('add_tag');
        $firstAction->method('getParamsJson')->willReturn('{}');
        $firstAction->method('getDelayMinutes')->willReturn(0);

        $secondAction = $this->createStub(CampaignAction::class);
        $secondAction->method('getType')->willReturn('send_email');
        $secondAction->method('getParamsJson')->willReturn('{}');
        $secondAction->method('getDelayMinutes')->willReturn(0);

        $actionCollection = $this->createStub(ActionCollection::class);
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

    #[AllowMockObjectsWithoutExpectations]
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

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFieldsConfigListsScheduledTriggerFields(): void
    {
        $config = $this->makeBlock()->getFieldsConfig();

        self::assertSame('scheduled_at', $config['trigger']['scheduled_at'][0]['name']);
        self::assertSame('cron_expression', $config['trigger']['recurring_schedule'][0]['name']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFlowDataJsonRendersTriggerFieldsContainerAndParams(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(6);
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', $campaign]]);

        $trigger = $this->createStub(CampaignTrigger::class);
        $trigger->method('getTriggerEvent')->willReturn('scheduled_at');
        $trigger->method('getParamsJson')->willReturn('{"scheduled_at":"2026-11-28 09:00:00"}');
        $collection = $this->createStub(TriggerCollection::class);
        $collection->method('addCampaignFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$trigger]));
        $this->triggerCollectionFactory->method('create')->willReturn($collection);

        $emptyConditionCollection = $this->createStub(ConditionCollection::class);
        $emptyConditionCollection->method('addCampaignFilter');
        $emptyConditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($emptyConditionCollection);

        $emptyActionCollection = $this->createStub(ActionCollection::class);
        $emptyActionCollection->method('addCampaignFilter');
        $emptyActionCollection->method('setOrder');
        $emptyActionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->actionCollectionFactory->method('create')->willReturn($emptyActionCollection);

        $json = $this->makeBlock()->getFlowDataJson();
        $data = json_decode($json, true)['drawflow']['Home']['data'];
        $html = $data[1]['html'];

        self::assertStringContainsString('data-params="{"scheduled_at":"2026-11-28 09:00:00"}"', $html);
        self::assertStringContainsString('ordo-flow-fields', $html);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFieldsConfigJsonEncodesTheSameConfig(): void
    {
        $block = $this->makeBlock();

        self::assertSame(
            json_encode($block->getFieldsConfig(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            $block->getFieldsConfigJson()
        );
    }

    /**
     * Regression test for a real stored-XSS finding: getFieldsConfigJson()/getFlowDataJson() are
     * embedded raw (@noEscape) inside a <script> block in flow.phtml, so a literal "</script>"
     * anywhere in admin-authored text reaching this JSON (e.g. a content block's own name) must
     * never survive un-escaped, or it closes the script tag early and lets the rest be parsed as
     * HTML/JS in the admin's own session.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testGetFlowDataJsonEscapesClosingScriptTagInNodeText(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);
        $this->registry->method('registry')->willReturnMap([['ordo_campaign', $campaign]]);

        $this->triggerCollectionFactory->method('create')->willReturn($this->triggerCollectionWith(['order_placed']));

        $action = $this->createStub(CampaignAction::class);
        $action->method('getType')->willReturn('add_tag');
        $action->method('getParamsJson')->willReturn('{"tag":"</script><script>alert(1)</script>"}');
        $action->method('getDelayMinutes')->willReturn(0);
        $actionCollection = $this->createStub(ActionCollection::class);
        $actionCollection->method('addCampaignFilter');
        $actionCollection->method('setOrder');
        $actionCollection->method('getIterator')->willReturn(new \ArrayIterator([$action]));
        $this->actionCollectionFactory->method('create')->willReturn($actionCollection);

        $conditionCollection = $this->createStub(ConditionCollection::class);
        $conditionCollection->method('addCampaignFilter');
        $conditionCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->conditionCollectionFactory->method('create')->willReturn($conditionCollection);

        $json = $this->makeBlock()->getFlowDataJson();

        self::assertStringNotContainsString('</script>', $json);
        $data = json_decode($json, true)['drawflow']['Home']['data'];
        self::assertStringContainsString('alert(1)', $data[2]['html']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetContentBlockOptionsMapsEntityIdToNameAndType(): void
    {
        $contentBlock = $this->createStub(ContentBlock::class);
        $contentBlock->method('getEntityId')->willReturn(7);
        $contentBlock->method('getName')->willReturn('Welcome Snippet');
        $contentBlock->method('getType')->willReturn('snippet');

        $contentBlockCollection = $this->createStub(ContentBlockCollection::class);
        $contentBlockCollection->method('addFieldToFilter')->willReturnSelf();
        $contentBlockCollection->method('getIterator')->willReturn(new \ArrayIterator([$contentBlock]));
        $this->contentBlockCollectionFactory = $this->createStub(ContentBlockCollectionFactory::class);
        $this->contentBlockCollectionFactory->method('create')->willReturn($contentBlockCollection);

        self::assertSame(
            [7 => 'Welcome Snippet (snippet)'],
            $this->makeBlock()->getContentBlockOptions()
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetWhatsAppTemplateOptionsMapsEntityIdToNameAndFiltersToApproved(): void
    {
        $template = $this->createStub(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(3);
        $template->method('getName')->willReturn('Order Shipped');

        $whatsAppTemplateCollection = $this->createMock(WhatsAppTemplateCollection::class);
        $whatsAppTemplateCollection->expects(self::once())->method('addApprovedFilter')->willReturnSelf();
        $whatsAppTemplateCollection->method('getIterator')->willReturn(new \ArrayIterator([$template]));
        $this->whatsAppTemplateCollectionFactory = $this->createStub(WhatsAppTemplateCollectionFactory::class);
        $this->whatsAppTemplateCollectionFactory->method('create')->willReturn($whatsAppTemplateCollection);

        self::assertSame(
            [3 => 'Order Shipped'],
            $this->makeBlock()->getWhatsAppTemplateOptions()
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFieldsConfigListsSendWhatsAppTemplateAndParamsFields(): void
    {
        $template = $this->createStub(WhatsAppTemplate::class);
        $template->method('getEntityId')->willReturn(3);
        $template->method('getName')->willReturn('Order Shipped');

        $whatsAppTemplateCollection = $this->createStub(WhatsAppTemplateCollection::class);
        $whatsAppTemplateCollection->method('addApprovedFilter')->willReturnSelf();
        $whatsAppTemplateCollection->method('getIterator')->willReturn(new \ArrayIterator([$template]));
        $this->whatsAppTemplateCollectionFactory = $this->createStub(WhatsAppTemplateCollectionFactory::class);
        $this->whatsAppTemplateCollectionFactory->method('create')->willReturn($whatsAppTemplateCollection);

        $config = $this->makeBlock()->getFieldsConfig()['action']['send_whatsapp'];

        self::assertSame('template_id', $config[0]['name']);
        self::assertSame([3 => 'Order Shipped'], $config[0]['options']);
        self::assertSame('params', $config[1]['name']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFieldsConfigListsSendPushTitleBodyAndUrlFields(): void
    {
        $config = $this->makeBlock()->getFieldsConfig()['action']['send_push'];

        self::assertSame('title', $config[0]['name']);
        self::assertSame('body', $config[1]['name']);
        self::assertSame('url', $config[2]['name']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetFieldsConfigDescribesSplitAsAVariantListField(): void
    {
        $config = $this->makeBlock()->getFieldsConfig()['action']['split'];

        self::assertSame('variants', $config[0]['name']);
        self::assertSame('variant_list', $config[0]['type']);
    }
}
