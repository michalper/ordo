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
use Ordo\Automation\Model\CampaignWebsite;
use Ordo\Automation\Model\CampaignWebsiteFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\Collection as ActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection as ConditionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as TriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Website as CampaignWebsiteResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Website\Collection as WebsiteCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Website\CollectionFactory as WebsiteCollectionFactory;
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
    private CampaignWebsiteFactory $campaignWebsiteFactory;
    private CampaignWebsiteResource $campaignWebsiteResource;
    private WebsiteCollectionFactory $websiteCollectionFactory;
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
        $this->campaignWebsiteFactory = $this->createMock(CampaignWebsiteFactory::class);
        $this->campaignWebsiteResource = $this->createMock(CampaignWebsiteResource::class);
        $this->websiteCollectionFactory = $this->createStub(WebsiteCollectionFactory::class);
        $this->websiteCollectionFactory->method('create')->willReturn($this->emptyWebsiteCollection());
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
            $this->campaignWebsiteFactory,
            $this->campaignWebsiteResource,
            $this->websiteCollectionFactory,
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

    /**
     * @param CampaignWebsite[] $rows
     */
    private function makeWebsiteCollection(array $rows = []): WebsiteCollection
    {
        $collection = $this->createStub(WebsiteCollection::class);
        $collection->method('addCampaignFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));
        return $collection;
    }

    private function emptyWebsiteCollection(): WebsiteCollection
    {
        return $this->makeWebsiteCollection([]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSavesNewCampaignAndCleansCache(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->expects(self::once())->method('setName')->with('Welcome');
        $campaign->expects(self::once())->method('setEnabled')->with(true);
        $campaign->expects(self::once())->method('setConditionLogic')->with('all');
        $campaign->method('getEntityId')->willReturn(7);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->campaignResource->expects(self::once())->method('save')->with($campaign);
        $this->campaignResource->expects(self::never())->method('load');

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());

        $trigger = $this->createMock(CampaignTrigger::class);
        $trigger->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['campaign_id'] === 7
                && $data['trigger_event'] === 'customer_registered'
                && $data['params'] === '{}'
        ));
        $this->campaignTriggerFactory->method('create')->willReturn($trigger);
        $this->campaignTriggerResource->expects(self::once())->method('save')->with($trigger);

        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $condition = $this->createMock(CampaignCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['type'] === 'tag' && json_decode($data['params'], true) === ['tags' => ['vip']]
        ));
        $this->campaignConditionFactory->method('create')->willReturn($condition);
        $this->campaignConditionResource->expects(self::once())->method('save')->with($condition);

        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $action = $this->createMock(CampaignAction::class);
        $action->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['type'] === 'add_tag'
                && json_decode($data['params'], true) === ['tags' => ['reordered']]
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
            'conditions' => ['conditions' => [['type' => 'tag', 'tags' => '["vip"]', 'params_json' => '']]],
            'actions' => ['actions' => [['type' => 'add_tag', 'tags' => '["reordered"]', 'params_json' => '', 'delay_minutes' => '60']]],
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
    public function testProcessNormalizesScheduledAtTriggerParamsLikeAConditionOrAction(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(11);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());

        $trigger = $this->createMock(CampaignTrigger::class);
        $trigger->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['trigger_event'] === 'scheduled_at'
                && json_decode($data['params'], true) === ['scheduled_at' => '2026-11-28 09:00:00']
        ));
        $this->campaignTriggerFactory->method('create')->willReturn($trigger);
        $this->campaignTriggerResource->expects(self::once())->method('save')->with($trigger);

        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $processor->process([
            'entity_id' => 0,
            'name' => 'Black Friday',
            'triggers' => ['triggers' => [
                ['trigger_event' => 'scheduled_at', 'scheduled_at' => '2026-11-28 09:00:00'],
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

    /**
     * Regression test: the Flow canvas renders content_block_id/output_key as dedicated fields
     * (Block\Adminhtml\Campaign\Edit\Flow::getFieldsConfig()'s add_dynamic_content entry), not
     * via the params_json fallback, so they must be in DEDICATED_PARAM_FIELDS or they're posted
     * but silently dropped — exactly the bug this test was written to catch after finding it by
     * tracing the save path (content_block_id was missing from that list, so every
     * add_dynamic_content action saved through the admin UI persisted params = '{}').
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessPersistsDynamicContentActionFields(): void
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
            fn (array $data) => json_decode($data['params'], true) === [
                'content_block_id' => '7',
                'output_key' => 'custom_html_key',
            ]
        ));
        $this->campaignActionFactory->method('create')->willReturn($action);
        $this->campaignActionResource->expects(self::once())->method('save')->with($action);

        $processor->process([
            'conditions' => ['conditions' => []],
            'actions' => ['actions' => [[
                'type' => 'add_dynamic_content',
                'content_block_id' => '7',
                'output_key' => 'custom_html_key',
                'params_json' => '',
            ]]],
        ]);
    }

    /**
     * Regression test: the exact same class of bug as testProcessPersistsDynamicContentActionFields()
     * above, found live against a real Ollama instance in a real browser - generate_ai_content's
     * prompt/fallback fields (Block\Adminhtml\Campaign\Edit\Flow::getFieldsConfig()'s
     * generate_ai_content entry) were missing from DEDICATED_PARAM_FIELDS (only output_key was
     * already present, inherited from add_dynamic_content), so every generate_ai_content action
     * saved through the admin UI silently dropped its prompt and fallback text, persisting only
     * params = {"output_key": "..."} with no way to actually generate anything.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessPersistsGenerateAiContentActionFields(): void
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
            fn (array $data) => json_decode($data['params'], true) == [
                'output_key' => 'ai_content_html',
                'prompt' => 'Write a greeting for {{customer_first_name}}',
                'fallback' => 'Welcome back!',
            ]
        ));
        $this->campaignActionFactory->method('create')->willReturn($action);
        $this->campaignActionResource->expects(self::once())->method('save')->with($action);

        $processor->process([
            'conditions' => ['conditions' => []],
            'actions' => ['actions' => [[
                'type' => 'generate_ai_content',
                'prompt' => 'Write a greeting for {{customer_first_name}}',
                'output_key' => 'ai_content_html',
                'fallback' => 'Welcome back!',
                'params_json' => '',
            ]]],
        ]);
    }

    /**
     * Regression test: send_whatsapp/send_push have dedicated fields in
     * Block\Adminhtml\Campaign\Edit\Flow::getFieldsConfig() (template_id/params, title/url), and
     * campaign-flow-editor.js's renderFields() renders ONLY those dedicated-field inputs for a
     * type with descriptors - no params_json fallback at all. Those four field names were
     * missing from the old flat DEDICATED_PARAM_FIELDS list entirely, so a send_whatsapp or
     * send_push action configured via the Flow canvas silently lost its template_id/params or
     * title/url on every save, with no error and nothing in params_json to notice the loss in.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessPersistsSendWhatsAppAndSendPushActionFields(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(1);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());
        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $whatsAppAction = $this->createMock(CampaignAction::class);
        $whatsAppAction->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['type'] === 'send_whatsapp' && json_decode($data['params'], true) == [
                'template_id' => '3',
                'params' => 'John,ORD-1234',
            ]
        ));
        $pushAction = $this->createMock(CampaignAction::class);
        $pushAction->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['type'] === 'send_push' && json_decode($data['params'], true) == [
                'body' => 'Your order shipped',
                'title' => 'Order update',
                'url' => '/sales/order/view',
            ]
        ));
        $this->campaignActionFactory->method('create')->willReturnOnConsecutiveCalls($whatsAppAction, $pushAction);
        $this->campaignActionResource->expects(self::exactly(2))->method('save');

        $processor->process([
            'conditions' => ['conditions' => []],
            'actions' => ['actions' => [
                [
                    'type' => 'send_whatsapp',
                    'template_id' => '3',
                    'params' => 'John,ORD-1234',
                    'params_json' => '',
                ],
                [
                    'type' => 'send_push',
                    'title' => 'Order update',
                    'body' => 'Your order shipped',
                    'url' => '/sales/order/view',
                    'params_json' => '',
                ],
            ]],
        ]);
    }

    /**
     * Regression test: the merge used to consider every dedicated field across every type, not
     * just the ones the row's own type actually reads, because Magento_Ui's dynamicRows always
     * posts every field's current value even when switcherConfig hides it for that row's type.
     * A monetary_total_at_least condition's saved params could end up with a useless tier
     * value nobody asked for just because some other row on the same form happened to be a
     * loyalty_tier_at_least condition.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessDoesNotLeakUnrelatedDedicatedFieldsIntoARowsParams(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(1);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $condition = $this->createMock(CampaignCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => $data['type'] === 'monetary_total_at_least'
                && json_decode($data['params'], true) === ['amount' => '100']
        ));
        $this->campaignConditionFactory->method('create')->willReturn($condition);
        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());

        $processor->process([
            'conditions' => ['conditions' => [[
                'type' => 'monetary_total_at_least',
                'amount' => '100',
                // Posted anyway by dynamicRows even though this row's own type never reads it -
                // a loyalty_tier_at_least-only field that must not leak into these saved params.
                'tier' => 'bronze',
                'params_json' => '',
            ]]],
            'actions' => ['actions' => []],
        ]);
    }

    /**
     * The split action's 'variants' field is JSON, not a plain scalar like every other
     * DEDICATED_PARAM_FIELDS entry - campaign-flow-editor.js's renderVariantEditor() posts it as
     * a JSON string (its own serialized in-memory model), which must be decoded back into a real
     * array before landing in params, not double-encoded as a JSON-string-inside-JSON.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessDecodesSplitActionVariantsFieldAsJsonNotAPlainString(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(1);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());
        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $variants = [
            ['key' => 'a', 'weight' => 50, 'actions' => [['type' => 'add_tag', 'params' => ['tag' => 'vip']]]],
            ['key' => 'b', 'weight' => 50, 'actions' => []],
        ];

        $action = $this->createMock(CampaignAction::class);
        $action->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => json_decode($data['params'], true) === ['variants' => $variants]
        ));
        $this->campaignActionFactory->method('create')->willReturn($action);
        $this->campaignActionResource->expects(self::once())->method('save')->with($action);

        $processor->process([
            'conditions' => ['conditions' => []],
            'actions' => ['actions' => [[
                'type' => 'split',
                'variants' => json_encode($variants),
                'params_json' => '',
            ]]],
        ]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessOmitsVariantsFromParamsWhenBlank(): void
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
            fn (array $data) => json_decode($data['params'], true) === []
        ));
        $this->campaignActionFactory->method('create')->willReturn($action);
        $this->campaignActionResource->expects(self::once())->method('save')->with($action);

        $processor->process([
            'conditions' => ['conditions' => []],
            'actions' => ['actions' => [[
                'type' => 'split',
                'variants' => '',
                'params_json' => '',
            ]]],
        ]);
    }

    /**
     * Regression test, same reasoning as testProcessPersistsDynamicContentActionFields above:
     * the RFM-based condition types (recency_days_at_most, order_frequency_at_least,
     * monetary_percentile_at_least, ...) render days/count/percentile as dedicated fields
     * (view/adminhtml/ui_component/ordo_campaign_form.xml's conditions switcherConfig), so all
     * three must be in DEDICATED_PARAM_FIELDS or they're posted but silently dropped.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessPersistsRfmConditionFields(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(1);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $condition = $this->createMock(CampaignCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => json_decode($data['params'], true) === ['percentile' => '80']
        ));
        $this->campaignConditionFactory->method('create')->willReturn($condition);
        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());
        $this->campaignConditionResource->expects(self::once())->method('save')->with($condition);

        $processor->process([
            'conditions' => ['conditions' => [[
                'type' => 'recency_percentile_at_least',
                'percentile' => '80',
                'params_json' => '',
            ]]],
            'actions' => ['actions' => []],
        ]);
    }

    /**
     * Regression test, same reasoning as testProcessPersistsRfmConditionFields above:
     * in_segment/not_in_segment (segment_id), loyalty_tier_at_least (tier), and event_occurred
     * (event_type/event_key/within_days) all now render dedicated fields
     * (ordo_campaign_form.xml's conditions switcherConfig, added alongside
     * Block\Adminhtml\Campaign\Edit\Flow::getFieldsConfig()'s own entries for the same types),
     * so every one of these must be in DEDICATED_PARAM_FIELDS or it's posted but silently
     * dropped. nps_score_at_least needs no entry of its own here - it reuses the existing
     * "threshold" field ('threshold' has been in DEDICATED_PARAM_FIELDS since score_at_least
     * got its own dedicated field).
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessPersistsInSegmentLoyaltyTierAndEventOccurredConditionFields(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(1);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $inSegmentCondition = $this->createMock(CampaignCondition::class);
        $inSegmentCondition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => json_decode($data['params'], true) === ['segment_id' => '3']
        ));

        $loyaltyCondition = $this->createMock(CampaignCondition::class);
        $loyaltyCondition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => json_decode($data['params'], true) === ['tier' => 'gold']
        ));

        $eventCondition = $this->createMock(CampaignCondition::class);
        $eventCondition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => json_decode($data['params'], true) === [
                'event_type' => 'cart_add',
                'event_key' => '24-MB01',
                'within_days' => '14',
            ]
        ));

        $this->campaignConditionFactory->method('create')->willReturnOnConsecutiveCalls(
            $inSegmentCondition,
            $loyaltyCondition,
            $eventCondition
        );
        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());
        $this->campaignConditionResource->expects(self::exactly(3))->method('save');

        $processor->process([
            'conditions' => ['conditions' => [
                ['type' => 'in_segment', 'segment_id' => '3', 'params_json' => ''],
                ['type' => 'loyalty_tier_at_least', 'tier' => 'gold', 'params_json' => ''],
                [
                    'type' => 'event_occurred',
                    'event_type' => 'cart_add',
                    'event_key' => '24-MB01',
                    'within_days' => '14',
                    'params_json' => '',
                ],
            ]],
            'actions' => ['actions' => []],
        ]);
    }

    /**
     * Regression test, same reasoning as the two tests above: reorder_cycle_at_risk's own
     * dedicated field (ratio_at_least) must be in DEDICATED_PARAM_FIELDS or it's posted but
     * silently dropped - found via a real MFTF run (AdminReorderCycleAtRiskTest) whose segment
     * saved with empty params despite the form field having the right value.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testProcessPersistsReorderCycleAtRiskRatioAtLeastDedicatedField(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getEntityId')->willReturn(1);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $condition = $this->createMock(CampaignCondition::class);
        $condition->expects(self::once())->method('setData')->with(self::callback(
            fn (array $data) => json_decode($data['params'], true) === ['ratio_at_least' => '0.5']
        ));

        $this->campaignConditionFactory->method('create')->willReturn($condition);
        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());
        $this->campaignConditionResource->expects(self::once())->method('save');

        $processor->process([
            'conditions' => ['conditions' => [
                ['type' => 'reorder_cycle_at_risk', 'ratio_at_least' => '0.5', 'params_json' => ''],
            ]],
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

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSavesPostedWebsiteIdsDeduplicatingAndSkippingInvalidValues(): void
    {
        $processor = $this->makeProcessor();

        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(7);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());
        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $savedWebsiteIds = [];
        $this->campaignWebsiteFactory->method('create')->willReturnCallback(function () use (&$savedWebsiteIds) {
            $website = $this->createMock(CampaignWebsite::class);
            $website->method('setData')->willReturnCallback(function (array $data) use ($website, &$savedWebsiteIds) {
                self::assertSame(7, $data['campaign_id']);
                $savedWebsiteIds[] = $data['website_id'];
                return $website;
            });
            return $website;
        });
        $this->campaignWebsiteResource->expects(self::exactly(2))->method('save');

        // '2' (string, a dupe of 2), 0, and -1 must all be skipped - only real, distinct,
        // positive ids get a row.
        $processor->process([
            'entity_id' => 7,
            'website_ids' => [1, 2, '2', 0, -1],
        ]);

        self::assertSame([1, 2], $savedWebsiteIds);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessDeletesExistingWebsiteRowsWhenNoneArePosted(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(7);
        $this->campaignFactory->method('create')->willReturn($campaign);

        $this->triggerCollectionFactory->method('create')->willReturn($this->emptyTriggerCollection());
        $this->conditionCollectionFactory->method('create')->willReturn($this->emptyConditionCollection());
        $this->actionCollectionFactory->method('create')->willReturn($this->emptyActionCollection());

        $existingWebsite = $this->createMock(CampaignWebsite::class);
        // A fresh stub, not a second ->method('create') on the one from setUp() - PHPUnit
        // matches configured behaviors in configuration order for an unlimited (any()) matcher,
        // so a second willReturn() on the same double never overrides the first.
        $this->websiteCollectionFactory = $this->createStub(WebsiteCollectionFactory::class);
        $this->websiteCollectionFactory->method('create')
            ->willReturn($this->makeWebsiteCollection([$existingWebsite]));

        $this->campaignWebsiteResource->expects(self::once())->method('delete')->with($existingWebsite);
        $this->campaignWebsiteFactory->expects(self::never())->method('create');

        // makeProcessor() must come after the websiteCollectionFactory reassignment above, since
        // the constructor captures that property's value at call time.
        $processor = $this->makeProcessor();

        // No 'website_ids' key at all - the common, single-website-install case - leaves the
        // campaign unscoped (matches every website), not scoped to zero.
        $processor->process(['entity_id' => 7]);
    }
}
