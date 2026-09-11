<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\Config\Source\TriggerEvent;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;

/**
 * The other half of Controller\Adminhtml\Campaign\Export - see
 * Model\Segment\SegmentImporter's own docblock for why this is its own class rather than reusing
 * Model\Campaign\CampaignSaveProcessor (same reasoning: that processor expects the admin form's
 * own posted shape, not Export's already-clean {type, params, sort_order}/{trigger_event, params}
 * shape).
 *
 * Always creates a NEW campaign, never overwrites an existing one - re-import assigns fresh ids
 * to everything, exactly like Export's own docblock already promises. A malformed/unknown-type
 * trigger, condition, or action row is dropped rather than failing the whole import, same
 * fail-soft philosophy CampaignSaveProcessor itself applies to a hand-crafted/tampered form POST.
 * A fundamentally wrong file (missing name, wrong export_type) is rejected outright.
 */
class CampaignImporter
{
    private const string EXPECTED_EXPORT_TYPE = 'ordo_campaign';

    public function __construct(
        private readonly CampaignFactory $campaignFactory,
        private readonly CampaignResource $campaignResource,
        private readonly CampaignTriggerFactory $campaignTriggerFactory,
        private readonly CampaignTriggerResource $campaignTriggerResource,
        private readonly CampaignConditionFactory $campaignConditionFactory,
        private readonly CampaignConditionResource $campaignConditionResource,
        private readonly CampaignActionFactory $campaignActionFactory,
        private readonly CampaignActionResource $campaignActionResource,
        private readonly ConditionPool $conditionPool,
        private readonly ActionPool $actionPool,
        private readonly TriggerEvent $triggerEvent
    ) {
    }

    /**
     * @param array<string, mixed> $payload decoded JSON, same shape Export produces
     * @throws \InvalidArgumentException the payload isn't a campaign export at all (wrong
     *   export_type, missing/blank name) - nothing reasonable to import.
     */
    public function import(array $payload): Campaign
    {
        if (($payload['export_type'] ?? null) !== self::EXPECTED_EXPORT_TYPE) {
            throw new \InvalidArgumentException(
                'This file is not a campaign export (missing or unexpected "export_type").'
            );
        }

        $rawName = $payload['name'] ?? '';
        $name = trim(is_string($rawName) ? $rawName : '');
        if ($name === '') {
            throw new \InvalidArgumentException('The campaign export is missing a name.');
        }

        $campaign = $this->campaignFactory->create();
        $campaign->setName($name);
        $campaign->setEnabled(!empty($payload['enabled']));
        $conditionLogic = ($payload['condition_logic'] ?? 'all') === 'any' ? 'any' : 'all';
        $campaign->setConditionLogic($conditionLogic);

        $this->campaignResource->save($campaign);

        $campaignId = (int) $campaign->getEntityId();
        $triggers = $payload['triggers'] ?? [];
        $conditions = $payload['conditions'] ?? [];
        $actions = $payload['actions'] ?? [];
        $this->saveTriggers($campaignId, is_array($triggers) ? $triggers : []);
        $this->saveConditions($campaignId, is_array($conditions) ? $conditions : []);
        $this->saveActions($campaignId, is_array($actions) ? $actions : []);

        return $campaign;
    }

    /**
     * @return string[]
     */
    private function validTriggerEvents(): array
    {
        return array_column($this->triggerEvent->toOptionArray(), 'value');
    }

    /**
     * @param array<mixed, mixed> $triggerRows
     */
    private function saveTriggers(int $campaignId, array $triggerRows): void
    {
        $validTriggerEvents = $this->validTriggerEvents();

        foreach ($triggerRows as $row) {
            if (!is_array($row) || !isset($row['trigger_event']) || !is_string($row['trigger_event'])) {
                continue;
            }

            $triggerEvent = $row['trigger_event'];
            if (!in_array($triggerEvent, $validTriggerEvents, true)) {
                continue;
            }

            $params = $row['params'] ?? [];
            $trigger = $this->campaignTriggerFactory->create();
            $trigger->setCampaignId($campaignId);
            $trigger->setTriggerEvent($triggerEvent);
            $trigger->setParamsJson(is_array($params) ? (json_encode($params) ?: '{}') : '{}');
            $this->campaignTriggerResource->save($trigger);
        }
    }

    /**
     * @param array<mixed, mixed> $conditionRows
     */
    private function saveConditions(int $campaignId, array $conditionRows): void
    {
        $sortOrder = 0;
        foreach ($conditionRows as $row) {
            if (!is_array($row) || !isset($row['type']) || !is_string($row['type']) || $row['type'] === '') {
                continue;
            }

            $type = $row['type'];
            if ($type !== 'group' && !$this->conditionPool->get($type) instanceof ConditionInterface) {
                continue;
            }

            $params = $row['params'] ?? [];
            $condition = $this->campaignConditionFactory->create();
            $condition->setCampaignId($campaignId);
            $condition->setType($type);
            $condition->setParamsJson(is_array($params) ? (json_encode($params) ?: '{}') : '{}');
            $condition->setSortOrder($sortOrder++);
            $this->campaignConditionResource->save($condition);
        }
    }

    /**
     * @param array<mixed, mixed> $actionRows
     */
    private function saveActions(int $campaignId, array $actionRows): void
    {
        $sortOrder = 0;
        foreach ($actionRows as $row) {
            if (!is_array($row) || !isset($row['type']) || !is_string($row['type'])
                || !$this->actionPool->get($row['type']) instanceof ActionInterface
            ) {
                continue;
            }

            $params = $row['params'] ?? [];
            $rawDelayMinutes = $row['delay_minutes'] ?? 0;
            $delayMinutes = is_int($rawDelayMinutes) || is_float($rawDelayMinutes) || is_string($rawDelayMinutes)
                ? (int) $rawDelayMinutes
                : 0;

            $action = $this->campaignActionFactory->create();
            $action->setCampaignId($campaignId);
            $action->setType($row['type']);
            $action->setParamsJson(is_array($params) ? (json_encode($params) ?: '{}') : '{}');
            $action->setSortOrder($sortOrder++);
            $action->setDelayMinutes(max(0, $delayMinutes));
            $this->campaignActionResource->save($action);
        }
    }
}
