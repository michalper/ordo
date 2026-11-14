<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\CampaignActionFactory;
use Ordo\Automation\Model\CampaignConditionFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\CampaignFactory;
use Ordo\Automation\Model\CampaignTriggerFactory;
use Ordo\Automation\Model\CampaignWebsiteFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Action as CampaignActionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Action\CollectionFactory as CampaignActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition as CampaignConditionResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Condition\CollectionFactory as CampaignConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Website as CampaignWebsiteResource;
use Ordo\Automation\Model\ResourceModel\Campaign\Website\CollectionFactory as CampaignWebsiteCollectionFactory;

/**
 * Persists the campaign row plus its trigger/condition/action child rows in one request. Child
 * rows are posted by the form's dynamicRows fields as plain arrays — this always deletes and
 * re-inserts them rather than diffing, which is simple and correct for the small row counts a
 * campaign realistically has (a handful of triggers/conditions/actions, not hundreds).
 *
 * Extracted from Controller\Adminhtml\Campaign\Save so the persistence/normalization logic can
 * be unit tested and reused without a controller in the loop; the controller still owns the
 * HTTP/session/redirect concerns.
 */
class CampaignSaveProcessor
{
    /**
     * Model\Campaign\ScheduledTriggerScanner reads the first two, Cron\
     * DispatchCustomerInactiveCampaigns reads the third - every other trigger type has no params
     * of its own. Triggers have no extensible Pool the way conditions/actions do
     * (ConditionPool/ActionPool) - just these three fixed types - so this stays its own small
     * hardcoded map rather than a ConditionInterface-style getParamFields() method on a one-off
     * TriggerInterface nothing else would ever implement.
     */
    private const array TRIGGER_FIELDS_BY_TYPE = [
        'scheduled_at' => ['scheduled_at'],
        'recurring_schedule' => ['cron_expression'],
        'customer_inactive' => ['days'],
        'customer_anniversary' => ['date_source'],
    ];

    public function __construct(
        private readonly ConditionPool $conditionPool,
        private readonly ActionPool $actionPool,
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
        private readonly CampaignWebsiteFactory $campaignWebsiteFactory,
        private readonly CampaignWebsiteResource $campaignWebsiteResource,
        private readonly CampaignWebsiteCollectionFactory $campaignWebsiteCollectionFactory,
        private readonly CacheInterface $cache,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Loads the campaign (when an entity_id was posted), applies the posted fields, saves it,
     * and rebuilds its trigger/condition/action child rows. Returns the saved campaign so the
     * caller can read back the entity_id for redirects/messages.
     *
     * @param array<string, mixed> $data
     */
    public function process(array $data): Campaign
    {
        $entityId = (int) ($data['entity_id'] ?? 0);
        $campaign = $this->campaignFactory->create();

        if ($entityId) {
            $this->campaignResource->load($campaign, $entityId);
        }

        $campaign->setName((string) ($data['name'] ?? ''));
        $campaign->setEnabled(!empty($data['enabled']));
        $campaign->setConditionLogic((string) ($data['condition_logic'] ?? 'all'));
        $campaign->setHoldoutPercent((int) ($data['holdout_percent'] ?? 0));
        // No dedicated per-type fields for this yet (a single JSON textarea, see
        // ordo_campaign_form.xml) - sanitizeForSave() drops malformed rows rather than this
        // going through ParamFieldsNormalizer's dedicated-field merge at all.
        $campaign->setExitConditions(ExitConditionSpecs::sanitizeForSave((string) ($data['exit_conditions'] ?? '')));

        /** @var array<int, array<string, mixed>> $triggerRows */
        $triggerRows = (array) ($data['triggers']['triggers'] ?? []);
        /** @var array<int, array<string, mixed>> $conditionRows */
        $conditionRows = (array) ($data['conditions']['conditions'] ?? []);
        /** @var array<int, array<string, mixed>> $actionRows */
        $actionRows = (array) ($data['actions']['actions'] ?? []);
        /** @var array<int, string|int> $websiteIds */
        $websiteIds = (array) ($data['website_ids'] ?? []);

        // Wrapped in one transaction so a failure partway through (e.g. a condition row that
        // fails validation) can't leave the campaign saved with only some of its triggers/
        // conditions/actions deleted-and-not-yet-reinserted - every child table here is
        // delete-then-reinsert (see this class's own docblock), which is only safe if the whole
        // sequence commits or rolls back together.
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            $this->campaignResource->save($campaign);
            $this->saveTriggers((int) $campaign->getEntityId(), $triggerRows);
            $this->saveChildRows(
                (int) $campaign->getEntityId(),
                $conditionRows,
                $actionRows
            );
            $this->saveWebsites((int) $campaign->getEntityId(), $websiteIds);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        // This is the admin UI's actual write path for triggers/enabled status — it saves
        // child rows via the resource models directly, not CampaignRepository — so
        // CampaignDispatcher's cached "campaign ids for trigger event" lookups need
        // invalidating here too, or a just-added trigger silently won't fire until expiry.
        $this->cache->clean([CampaignDispatcher::CACHE_TAG]);

        return $campaign;
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

            $fieldNames = self::TRIGGER_FIELDS_BY_TYPE[$triggerEvent] ?? [];
            $fields = array_map(static fn (string $name): array => ['name' => $name], $fieldNames);

            $trigger = $this->campaignTriggerFactory->create();
            $trigger->setData([
                'campaign_id' => $campaignId,
                'trigger_event' => $triggerEvent,
                'params' => ParamFieldsNormalizer::normalizeRowParams($row, $fields),
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

            $type = (string) $row['type'];
            $condition = $this->campaignConditionFactory->create();
            $condition->setData([
                'campaign_id' => $campaignId,
                'type' => $type,
                'params' => ParamFieldsNormalizer::normalizeRowParams(
                    $row,
                    $this->conditionPool->get($type)?->getParamFields() ?? []
                ),
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

            $type = (string) $row['type'];
            // 'split' has no ActionPool entry - see ActionPool::splitActionFields()'s own docblock.
            $fields = $type === 'split'
                ? ActionPool::splitActionFields()
                : ($this->actionPool->get($type)?->getParamFields() ?? []);
            $action = $this->campaignActionFactory->create();
            $action->setData([
                'campaign_id' => $campaignId,
                'type' => $type,
                'params' => ParamFieldsNormalizer::normalizeRowParams($row, $fields),
                'sort_order' => $sortOrder++,
                'delay_minutes' => max(0, (int) ($row['delay_minutes'] ?? 0)),
            ]);
            $this->campaignActionResource->save($action);
        }
    }

    /**
     * No rows at all means unscoped (matches every website) - see docs/adr/0014 - so an empty
     * posted selection (the common case; most installs are single-website) correctly leaves
     * this campaign with zero website rows rather than writing anything.
     *
     * @param array<int, string|int> $websiteIds
     */
    private function saveWebsites(int $campaignId, array $websiteIds): void
    {
        $existingWebsites = $this->campaignWebsiteCollectionFactory->create();
        $existingWebsites->addCampaignFilter($campaignId);
        foreach ($existingWebsites as $existing) {
            $this->campaignWebsiteResource->delete($existing);
        }

        $seen = [];
        foreach ($websiteIds as $websiteId) {
            $websiteId = (int) $websiteId;
            if ($websiteId <= 0 || isset($seen[$websiteId])) {
                continue;
            }
            $seen[$websiteId] = true;

            $website = $this->campaignWebsiteFactory->create();
            $website->setData([
                'campaign_id' => $campaignId,
                'website_id' => $websiteId,
            ]);
            $this->campaignWebsiteResource->save($website);
        }
    }
}
