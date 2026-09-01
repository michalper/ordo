<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Campaign;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as CampaignConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;

/**
 * Persists the campaign row plus its trigger/condition/action child rows in one request. Child
 * rows are posted by the form's dynamicRows fields as plain arrays — this always deletes and
 * re-inserts them rather than diffing, which is simple and correct for the small row counts a
 * campaign realistically has (a handful of triggers/conditions/actions, not hundreds).
 */
class Save extends AbstractCampaignAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly CampaignFactory $campaignFactory,
        private readonly CampaignResource $campaignResource,
        private readonly CampaignTriggerFactory $campaignTriggerFactory,
        private readonly CampaignTriggerResource $campaignTriggerResource,
        private readonly CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory,
        private readonly CampaignConditionFactory $campaignConditionFactory,
        private readonly CampaignConditionResource $campaignConditionResource,
        private readonly CampaignConditionCollectionFactory $campaignConditionCollectionFactory,
        private readonly CampaignActionFactory $campaignActionFactory,
        private readonly CampaignActionResource $campaignActionResource,
        private readonly CampaignActionCollectionFactory $campaignActionCollectionFactory,
        private readonly CacheInterface $cache
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data = $this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $entityId = (int) ($data['entity_id'] ?? 0);
        $campaign = $this->campaignFactory->create();

        if ($entityId) {
            $this->campaignResource->load($campaign, $entityId);
        }

        $campaign->setName((string) ($data['name'] ?? ''));
        $campaign->setEnabled(!empty($data['enabled']));

        try {
            $this->campaignResource->save($campaign);
            $this->saveTriggers((int) $campaign->getEntityId(), (array) ($data['triggers']['triggers'] ?? []));
            $this->saveChildRows(
                (int) $campaign->getEntityId(),
                (array) ($data['conditions']['conditions'] ?? []),
                (array) ($data['actions']['actions'] ?? [])
            );

            // This is the admin UI's actual write path for triggers/enabled status — it saves
            // child rows via the resource models directly, not CampaignRepository — so
            // CampaignDispatcher's cached "campaign ids for trigger event" lookups need
            // invalidating here too, or a just-added trigger silently won't fire until expiry.
            $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

            $this->messageManager->addSuccessMessage(__('The campaign has been saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $campaign->getEntityId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not save the campaign: %1', $e->getMessage()));
            return $resultRedirect->setPath('*/*/edit', ['entity_id' => $entityId]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $triggerRows
     */
    private function saveTriggers(int $campaignId, array $triggerRows): void
    {
        $existingTriggers = $this->campaignTriggerCollectionFactory->create();
        $existingTriggers->addCampaignFilter($campaignId);
        foreach ($existingTriggers as $existing) {
            $this->campaignTriggerResource->delete($existing);
        }

        // A campaign posting the same trigger_event twice (e.g. two rows both set to
        // "order_placed") would otherwise hit the unique(campaign_id, trigger_event) constraint
        // on save — de-duplicate here rather than surfacing that as a confusing DB error.
        $seen = [];
        foreach ($triggerRows as $row) {
            $triggerEvent = (string) ($row['trigger_event'] ?? '');
            if ($triggerEvent === '' || isset($seen[$triggerEvent])) {
                continue;
            }
            $seen[$triggerEvent] = true;

            $trigger = $this->campaignTriggerFactory->create();
            $trigger->setData([
                'campaign_id' => $campaignId,
                'trigger_event' => $triggerEvent,
            ]);
            $this->campaignTriggerResource->save($trigger);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $conditionRows
     * @param array<int, array<string, mixed>> $actionRows
     */
    private function saveChildRows(int $campaignId, array $conditionRows, array $actionRows): void
    {
        $conditions = $this->campaignConditionCollectionFactory->create();
        $conditions->addCampaignFilter($campaignId);
        foreach ($conditions as $existing) {
            $this->campaignConditionResource->delete($existing);
        }

        $sortOrder = 0;
        foreach ($conditionRows as $row) {
            if (empty($row['type'])) {
                continue;
            }

            $condition = $this->campaignConditionFactory->create();
            $condition->setData([
                'campaign_id' => $campaignId,
                'type' => (string) $row['type'],
                'params' => $this->normalizeRowParams($row),
                'sort_order' => $sortOrder++,
            ]);
            $this->campaignConditionResource->save($condition);
        }

        $actions = $this->campaignActionCollectionFactory->create();
        $actions->addCampaignFilter($campaignId);
        foreach ($actions as $existing) {
            $this->campaignActionResource->delete($existing);
        }

        $sortOrder = 0;
        foreach ($actionRows as $row) {
            if (empty($row['type'])) {
                continue;
            }

            $action = $this->campaignActionFactory->create();
            $action->setData([
                'campaign_id' => $campaignId,
                'type' => (string) $row['type'],
                'params' => $this->normalizeRowParams($row),
                'sort_order' => $sortOrder++,
                'delay_minutes' => max(0, (int) ($row['delay_minutes'] ?? 0)),
            ]);
            $this->campaignActionResource->save($action);
        }
    }

    /**
     * Dedicated per-type fields the form posts (see ordo_campaign_form.xml switcherConfig) —
     * whichever of these are non-empty for a row get merged into its params, so the raw JSON
     * textarea is only needed for a condition/action type that doesn't have one yet.
     */
    private const DEDICATED_PARAM_FIELDS = [
        'tag', 'amount', 'rule_id', 'prefix', 'template', 'message',
        'headline', 'body', 'cta_label', 'cta_url',
    ];

    /**
     * Starts from whatever was typed in the JSON textarea (if valid), then overlays any
     * dedicated fields present on the row — dedicated fields win over the JSON textarea on
     * key conflicts, since a stale/copy-pasted JSON blob shouldn't silently override a field
     * the admin just filled in and can see.
     *
     * @param array<string, mixed> $row
     */
    private function normalizeRowParams(array $row): string
    {
        $params = $this->parseJson((string) ($row['params_json'] ?? ''));

        foreach (self::DEDICATED_PARAM_FIELDS as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                $params[$field] = $value;
            }
        }

        return json_encode($params) ?: '{}';
    }

    /**
     * Falls back to an empty object rather than rejecting the save outright — a
     * condition/action with unparsable params simply won't find the keys it expects at
     * dispatch time (fails closed, doesn't crash).
     *
     * @return array<string, mixed>
     */
    private function parseJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }
}
