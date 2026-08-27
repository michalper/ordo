<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Adminhtml\Campaign\Edit;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\Registry;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\Config\Source\TriggerEvent;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as ActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as ConditionCollectionFactory;

/**
 * Read-only Drawflow (https://github.com/jerosoler/Drawflow) visualization of a campaign's
 * actual trigger → conditions → actions chain, built server-side from the same
 * CampaignCondition/CampaignAction rows the dynamicRows form above edits — this block is
 * deliberately NOT the source of truth (the existing form + Save.php are unchanged), just an
 * additional, hooked-in-place read of what's already there. A visual editor that writes back
 * into the same conditions[]/actions[] structure is a natural next step, not built here.
 */
class Flow extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly ConditionCollectionFactory $conditionCollectionFactory,
        private readonly ActionCollectionFactory $actionCollectionFactory,
        private readonly TriggerEvent $triggerEventSource,
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
        return $this->getCampaign() !== null && $this->getCampaign()->getEntityId();
    }

    private function triggerLabel(string $triggerEvent): string
    {
        foreach ($this->triggerEventSource->toOptionArray() as $option) {
            if ($option['value'] === $triggerEvent) {
                return (string) $option['label'];
            }
        }

        return $triggerEvent;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNode(int $id, string $name, string $html, int $posX, int $posY): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'data' => [],
            'class' => $name,
            'html' => $html,
            'typenode' => false,
            'inputs' => ['input_1' => ['connections' => []]],
            'outputs' => ['output_1' => ['connections' => []]],
            'pos_x' => $posX,
            'pos_y' => $posY,
        ];
    }

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
        if (!$campaign || !$campaign->getEntityId()) {
            return '{}';
        }

        $campaignId = (int) $campaign->getEntityId();
        $nodes = [];
        $nextId = 1;
        $x = 40;

        $triggerId = $nextId++;
        $nodes[$triggerId] = $this->buildNode(
            $triggerId,
            'ordo-flow-trigger',
            '<div class="ordo-flow-node ordo-flow-trigger">' . $this->escapeHtml($this->triggerLabel($campaign->getTriggerEvent())) . '</div>',
            $x,
            150
        );
        $x += 220;

        $conditions = $this->conditionCollectionFactory->create();
        $conditions->addCampaignFilter($campaignId);
        $lastConditionIds = [];
        foreach ($conditions as $condition) {
            $id = $nextId++;
            $nodes[$id] = $this->buildNode(
                $id,
                'ordo-flow-condition',
                '<div class="ordo-flow-node ordo-flow-condition">' . $this->escapeHtml((string) $condition->getData('type')) . '</div>',
                $x,
                150
            );
            $this->connect($nodes, $triggerId, $id);
            $lastConditionIds[] = $id;
            $x += 220;
        }

        $upstreamIds = $lastConditionIds ?: [$triggerId];

        $actions = $this->actionCollectionFactory->create();
        $actions->addCampaignFilter($campaignId);
        $actions->setOrder('sort_order', 'ASC');
        $previousActionId = null;
        foreach ($actions as $action) {
            $id = $nextId++;
            $nodes[$id] = $this->buildNode(
                $id,
                'ordo-flow-action',
                '<div class="ordo-flow-node ordo-flow-action">' . $this->escapeHtml((string) $action->getData('type')) . '</div>',
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
            $x += 220;
        }

        return (string) json_encode([
            'drawflow' => [
                'Home' => [
                    'data' => $nodes,
                ],
            ],
        ]);
    }
}
