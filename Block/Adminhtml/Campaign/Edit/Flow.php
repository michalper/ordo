<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Campaign\Edit;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\Registry;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\Campaign\TypeLabels;
use Ordo\Automation\Model\CampaignAction;
use Ordo\Automation\Model\CampaignCondition;
use Ordo\Automation\Model\CampaignTrigger;
use Ordo\Automation\Model\Config\Source\TriggerEvent;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as TriggerCollectionFactory;

/**
 * Editable Drawflow (https://github.com/jerosoler/Drawflow) view of a campaign's trigger(s) →
 * conditions → actions chain, built server-side from the same CampaignTrigger/CampaignCondition/
 * CampaignAction rows the dynamicRows form below edits. A campaign can have more than one
 * trigger node (CampaignTriggerInterface is its own child entity — see CampaignDispatcher) —
 * every trigger on the canvas fans out to the same conditions/actions chain, they're
 * alternative starting points for one scenario, not separate scenarios. Nodes can be
 * added/removed/retyped on the canvas; "Apply flow to form" (campaign-flow-editor.js) reads the
 * current graph and writes it into the exact same `triggers`/`conditions`/`actions` provider
 * data paths Save.php already accepts, then calls the form's own native save — this block never
 * talks to the backend directly, it only prepares the data the existing, unmodified save
 * pipeline already knows how to persist.
 */
class Flow extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly TriggerCollectionFactory $triggerCollectionFactory,
        private readonly ConditionCollectionFactory $conditionCollectionFactory,
        private readonly ActionCollectionFactory $actionCollectionFactory,
        private readonly TriggerEvent $triggerEventSource,
        private readonly ConditionPool $conditionPool,
        private readonly ActionPool $actionPool,
        private readonly TypeLabels $typeLabels,
        array $data = [],
        ?JsonHelper $jsonHelper = null,
        ?DirectoryHelper $directoryHelper = null
    ) {
        parent::__construct($context, $data, $jsonHelper, $directoryHelper);
    }

    private function getCampaign(): ?Campaign
    {
        $campaign = $this->registry->registry('ordo_campaign');
        return $campaign instanceof Campaign ? $campaign : null;
    }

    public function hasCampaign(): bool
    {
        return $this->getCampaign() instanceof \Ordo\Automation\Model\Campaign && $this->getCampaign()->getEntityId();
    }

    /**
     * @return string[]
     */
    public function getTriggerEventTypes(): array
    {
        return array_column($this->triggerEventSource->toOptionArray(), 'value');
    }

    /**
     * @return string[]
     */
    public function getConditionTypes(): array
    {
        return $this->conditionPool->getAvailableTypes();
    }

    /**
     * 'split' is deliberately appended here rather than registered in ActionPool -
     * CampaignDispatcher::runSplit() intercepts type === 'split' before the ActionPool lookup
     * (the same reserved-pseudo-type shape the 'group' condition type already has), so it has no
     * ActionInterface implementation of its own to register. Still needs to appear in the Flow
     * canvas's own action type list, though, or an admin could never actually add one.
     *
     * @return string[]
     */
    public function getActionTypes(): array
    {
        return [...$this->actionPool->getAvailableTypes(), 'split'];
    }

    /**
     * type => human-readable label, for the canvas's palette chips and node type dropdowns —
     * the raw type key (e.g. "order_total_gte") is what di.xml registers and what gets
     * persisted, but nobody building a campaign should have to read it. See TypeLabels.
     *
     * @return array<string, string>
     */
    public function getTriggerEventLabels(): array
    {
        $labels = [];
        foreach ($this->triggerEventSource->toOptionArray() as $option) {
            $labels[(string) $option['value']] = (string) $option['label'];
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public function getConditionTypeLabels(): array
    {
        $labels = [];
        foreach ($this->getConditionTypes() as $type) {
            $labels[$type] = $this->typeLabels->conditionLabel($type);
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public function getActionTypeLabels(): array
    {
        $labels = [];
        foreach ($this->getActionTypes() as $type) {
            $labels[$type] = $this->typeLabels->actionLabel($type);
        }

        return $labels;
    }

    /**
     * Which dedicated, labeled admin-UI fields apply to each known condition/action type - read
     * directly from each ConditionPool/ActionPool-registered class's own getParamFields()
     * (the same single source of truth Model\Campaign\CampaignSaveProcessor/Model\Segment\
     * SegmentSaveProcessor read), not a copy of that mapping hand-maintained here. A type not
     * listed (a custom condition/action a store added that doesn't override getParamFields())
     * still works via the JSON fallback field every node also has. 'trigger' stays hardcoded -
     * unlike conditions/actions, trigger types have no extensible Pool (only two ever have
     * params), so there's no class to read this from.
     *
     * @return array<string, array<string, array<int, array<string, string|array<int|string, string>>>>>
     */
    public function getFieldsConfig(): array
    {
        $conditionFields = [];
        foreach ($this->getConditionTypes() as $type) {
            $conditionFields[$type] = $this->conditionPool->get($type)?->getParamFields() ?? [];
        }

        $actionFields = [];
        foreach ($this->getActionTypes() as $type) {
            // 'split' has no ActionPool entry - see ActionPool::splitActionFields()'s own docblock.
            $actionFields[$type] = $type === 'split'
                ? ActionPool::splitActionFields()
                : ($this->actionPool->get($type)?->getParamFields() ?? []);
        }

        return [
            'trigger' => [
                CampaignTriggerInterface::TRIGGER_SCHEDULED_AT => [
                    [
                        'name' => 'scheduled_at',
                        'label' => (string) __('Date/time (store timezone)'),
                        'notice' => (string) __(
                            'Format: YYYY-MM-DD HH:MM:SS, e.g. 2026-11-28 09:00:00. Fires at most once, the'
                            . ' next time Cron\DispatchScheduledCampaignTriggers scans (every 5 minutes) after'
                            . ' this moment.'
                        ),
                    ],
                ],
                CampaignTriggerInterface::TRIGGER_RECURRING_SCHEDULE => [
                    [
                        'name' => 'cron_expression',
                        'label' => (string) __('Cron expression'),
                        'notice' => (string) __(
                            'Standard 5-field cron, e.g. "0 8 * * 1" for every Monday at 08:00. Precision is'
                            . ' bounded by the 5-minute scan interval — a minute field that isn\'t a multiple'
                            . ' of 5 will never match.'
                        ),
                    ],
                ],
                CampaignTriggerInterface::TRIGGER_CUSTOMER_INACTIVE => [
                    [
                        'name' => 'days',
                        'label' => (string) __('Inactive for at least N days'),
                        'notice' => (string) __(
                            'Fires once per customer whose most recent order (or registration, if they'
                            . ' never ordered) is at least this many days old - scanned nightly by'
                            . ' Cron\DispatchCustomerInactiveCampaigns. Re-fires for the same customer'
                            . ' after the configured refire cooldown (Stores → Configuration → Ordo'
                            . ' Automation → Customer Inactive Trigger) once they have ordered again and'
                            . ' gone inactive once more.'
                        ),
                    ],
                ],
            ],
            'condition' => $conditionFields,
            'action' => $actionFields,
        ];
    }

    /**
     * JSON_HEX_TAG|JSON_HEX_AMP - this is embedded raw (@noEscape) directly inside a <script>
     * block in flow.phtml, not an HTML attribute escapeHtmlAttr() would cover - a literal
     * "</script>" inside any label/text here (content block name, translated field label) would
     * otherwise close the script tag early and let the rest be parsed as HTML/JS, a stored XSS
     * in the admin campaign editor.
     */
    public function getFieldsConfigJson(): string
    {
        return (string) json_encode(
            $this->getFieldsConfig(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    /**
     * @param string[] $types
     * @param array<string, string> $labels type => human-readable label (TypeLabels /
     *   TriggerEvent) — the <option> value is still the raw type key (what gets persisted and
     *   what the dispatcher matches against), only the visible text is the label.
     */
    private function typeOptionsHtml(array $types, string $selected, array $labels): string
    {
        $html = '';
        foreach ($types as $type) {
            $isSelected = $type === $selected ? ' selected="selected"' : '';
            $html .= '<option value="' . $this->escapeHtmlAttr($type) . '"' . $isSelected . '>'
                . $this->escapeHtml($labels[$type] ?? $type) . '</option>';
        }

        return $html;
    }

    /**
     * Most trigger types have no dedicated fields/params — their type select value IS the whole
     * payload (the trigger_event itself). The two schedule-based types (`scheduled_at`/
     * `recurring_schedule`) are the exception: they need a datetime/cron input the same way a
     * condition/action does, so this now shares the label/params/fields-container shape with
     * editableNodeHtml() below rather than being a stripped-down variant of it.
     */
    private function triggerNodeHtml(string $optionsHtml, string $paramsJson): string
    {
        $decodedParams = json_decode($paramsJson, true);
        $paramsAttr = json_encode(is_array($decodedParams) ? $decodedParams : []);

        return '<div class="ordo-flow-node" data-kind="trigger" data-params="'
            . $this->escapeHtmlAttr((string) $paramsAttr) . '">'
            . '<div class="ordo-flow-node-head">'
            . '<span>' . $this->escapeHtml((string) __('Trigger')) . '</span>'
            . '<button type="button" class="ordo-flow-delete" title="'
            . $this->escapeHtmlAttr((string) __('Remove')) . '">&times;</button>'
            . '</div>'
            . '<select class="ordo-flow-type-select">' . $optionsHtml . '</select>'
            . '<div class="ordo-flow-fields"></div>'
            . '</div>';
    }

    /**
     * Every condition/action node renders the same editable shape: a type <select> (options
     * from ConditionPool/ActionPool — the same registry the native dynamicRows type dropdown
     * uses) plus an empty `.ordo-flow-fields` container that campaign-flow-editor.js fills with
     * labeled inputs for whatever fields the selected type actually has (getFieldsConfig()) —
     * this node's decoded params travel in the `data-params` attribute so the client-side
     * renderer has something to pre-fill. No raw JSON textarea for a type that has dedicated
     * fields: someone who doesn't know what JSON is should never have to see one to configure
     * this. A type without a mapping still gets the JSON fallback field.
     */
    private function editableNodeHtml(
        string $kind,
        string $label,
        string $paramsJson,
        string $optionsHtml,
        int $delayMinutes = 0
    ): string {
        $decodedParams = json_decode($paramsJson, true);
        $paramsAttr = json_encode(is_array($decodedParams) ? $decodedParams : []);

        // Drawflow itself already applies the 'ordo-flow-condition'/'ordo-flow-action' class
        // (passed as addNode's classoverride / buildNode's $name) to the outer .drawflow-node
        // wrapper it generates around this HTML — repeating that same class on our own inner
        // div here would make jQuery's `.find('.ordo-flow-action')` in campaign-flow-editor.js
        // match both elements per node and silently double every row on save. This inner div
        // only ever needs the `data-kind` marker for that lookup, not the class.
        //
        // delay_minutes only ever applies to action nodes — it's rendered as an always-visible
        // input alongside the per-type fields (campaign-flow-editor.js's bindNode()), not part
        // of `data-params`/getFieldsConfig(), since it's a real CampaignAction column, not
        // something stored inside params JSON.
        $delayHtml = $kind === 'action'
            ? '<label class="ordo-flow-field-label">' . $this->escapeHtml((string) __('Delay (minutes)')) . '</label>'
                . '<input type="text" class="ordo-flow-field-input" data-field="delay_minutes" value="'
                . $this->escapeHtmlAttr((string) $delayMinutes) . '">'
            : '';

        return '<div class="ordo-flow-node" data-kind="' . $this->escapeHtmlAttr($kind)
            . '" data-params="' . $this->escapeHtmlAttr((string) $paramsAttr) . '">'
            . '<div class="ordo-flow-node-head">'
            . '<span>' . $this->escapeHtml($label) . '</span>'
            . '<button type="button" class="ordo-flow-delete" title="'
            . $this->escapeHtmlAttr((string) __('Remove')) . '">&times;</button>'
            . '</div>'
            . '<select class="ordo-flow-type-select">' . $optionsHtml . '</select>'
            . $delayHtml
            . '<div class="ordo-flow-fields"></div>'
            . '</div>';
    }

    /**
     * Trigger nodes have no input — nothing ever feeds into them, they're where the scenario
     * starts — so they get zero `inputs`, not the usual one, and no left-edge connector dot
     * renders for them at all.
     *
     * @return array<string, mixed>
     */
    private function buildNode(int $id, string $name, string $html, int $posX, int $posY): array
    {
        $isTrigger = $name === 'ordo-flow-trigger';

        return [
            'id' => $id,
            'name' => $name,
            'data' => [],
            'class' => $name,
            'html' => $html,
            'typenode' => false,
            'inputs' => $isTrigger ? [] : ['input_1' => ['connections' => []]],
            'outputs' => ['output_1' => ['connections' => []]],
            'pos_x' => $posX,
            'pos_y' => $posY,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function connect(array &$nodes, int $fromId, int $toId): void
    {
        $nodes[$fromId]['outputs']['output_1']['connections'][] = ['node' => (string) $toId, 'output' => 'input_1'];
        $nodes[$toId]['inputs']['input_1']['connections'][] = ['node' => (string) $fromId, 'input' => 'output_1'];
    }

    /**
     * @return string JSON, ready for Drawflow.import()
     */
    public function getFlowDataJson(): string
    {
        $campaign = $this->getCampaign();
        if (!$campaign instanceof Campaign || !$campaign->getEntityId()) {
            return '{}';
        }

        $campaignId = (int) $campaign->getEntityId();
        $nodes = [];
        $nextId = 1;
        $x = 60;

        $triggers = $this->triggerCollectionFactory->create();
        $triggers->addCampaignFilter($campaignId);
        $triggerIds = [];
        foreach ($triggers as $trigger) {
            /** @var CampaignTrigger $trigger */
            $id = $nextId++;
            $triggerEvent = $trigger->getTriggerEvent();
            $nodes[$id] = $this->buildNode(
                $id,
                'ordo-flow-trigger',
                $this->triggerNodeHtml(
                    $this->typeOptionsHtml(
                        $this->getTriggerEventTypes(),
                        $triggerEvent,
                        $this->getTriggerEventLabels()
                    ),
                    $trigger->getParamsJson()
                ),
                $x,
                60 + count($triggerIds) * 160
            );
            $triggerIds[] = $id;
        }
        $x += 260;

        $conditions = $this->conditionCollectionFactory->create();
        $conditions->addCampaignFilter($campaignId);
        $lastConditionIds = [];
        foreach ($conditions as $condition) {
            /** @var CampaignCondition $condition */
            $id = $nextId++;
            $type = $condition->getType();
            $nodes[$id] = $this->buildNode(
                $id,
                'ordo-flow-condition',
                $this->editableNodeHtml(
                    'condition',
                    (string) __('Condition'),
                    $condition->getParamsJson(),
                    $this->typeOptionsHtml($this->getConditionTypes(), $type, $this->getConditionTypeLabels())
                ),
                $x,
                150
            );
            foreach ($triggerIds as $triggerId) {
                $this->connect($nodes, $triggerId, $id);
            }
            $lastConditionIds[] = $id;
            $x += 260;
        }

        $upstreamIds = $lastConditionIds ?: $triggerIds;

        $actions = $this->actionCollectionFactory->create();
        $actions->addCampaignFilter($campaignId);
        $actions->setOrder('sort_order', 'ASC');
        $previousActionId = null;
        foreach ($actions as $action) {
            /** @var CampaignAction $action */
            $id = $nextId++;
            $type = $action->getType();
            $nodes[$id] = $this->buildNode(
                $id,
                'ordo-flow-action',
                $this->editableNodeHtml(
                    'action',
                    (string) __('Action'),
                    $action->getParamsJson(),
                    $this->typeOptionsHtml($this->getActionTypes(), $type, $this->getActionTypeLabels()),
                    $action->getDelayMinutes()
                ),
                $x,
                150
            );

            if ($previousActionId === null) {
                foreach ($upstreamIds as $upstreamId) {
                    $this->connect($nodes, $upstreamId, $id);
                }
            } else {
                $this->connect($nodes, $previousActionId, $id);
            }

            $previousActionId = $id;
            $x += 260;
        }

        // JSON_HEX_TAG|JSON_HEX_AMP - same reasoning as getFieldsConfigJson() above: this is
        // embedded raw (@noEscape) inside a <script> block, and $nodes carries admin-authored
        // text (action/condition labels, params) that could otherwise close the script tag early.
        return (string) json_encode(
            [
                'drawflow' => [
                    'Home' => [
                        'data' => $nodes,
                    ],
                ],
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}
