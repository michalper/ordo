<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Framework\App\CacheInterface;
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
     * Which dedicated fields are actually relevant to each condition type - keyed by type, not a
     * flat list, so normalizeRowParams() only merges a field into a row's params when that row's
     * own type actually reads it. A flat list used to merge ANY non-empty dedicated field into
     * EVERY row regardless of type, because Magento_Ui's dynamicRows always posts every field's
     * current value even when switcherConfig hides it for that row's type - e.g. a
     * monetary_total_at_least condition's saved params picked up a useless tier: "bronze" key
     * nobody asked for, just because some other row on the same form happened to be a
     * loyalty_tier_at_least condition. Verified against every Model\Campaign\Condition\*.php
     * class's own real params reads, not just Block\Adminhtml\Campaign\Edit\Flow::getFieldsConfig()
     * (which turned out to be missing several registered types entirely).
     */
    private const array CONDITION_FIELDS_BY_TYPE = [
        'tag' => ['tags', 'match_mode'],
        'order_total_gte' => ['amount'],
        'visitor_tag' => ['tags', 'match_mode'],
        'score_at_least' => ['threshold'],
        'recency_days_at_most' => ['days'],
        'order_frequency_at_least' => ['count'],
        'monetary_total_at_least' => ['amount'],
        'recency_percentile_at_least' => ['percentile'],
        'order_frequency_percentile_at_least' => ['percentile'],
        'monetary_percentile_at_least' => ['percentile'],
        'reorder_cycle_at_risk' => ['ratio_at_least'],
        'clv_at_least' => ['amount'],
        'in_segment' => ['segment_id'],
        'not_in_segment' => ['segment_id'],
        'loyalty_tier_at_least' => ['tier'],
        'nps_score_at_least' => ['threshold'],
        'purchased_sku' => ['sku'],
        'purchased_category' => ['category_id'],
        'event_occurred' => ['event_type', 'event_key', 'within_days'],
        'message_engaged' => ['campaign_id', 'engagement_type', 'engagement_within_days'],
    ];

    /**
     * Same reasoning as CONDITION_FIELDS_BY_TYPE, for actions. Adding 'template_id'/'params'
     * (send_whatsapp) and 'title'/'url' (send_push) here isn't just closing the leak - those four
     * field names were missing from the old flat list entirely, so send_whatsapp/send_push
     * configured via the Flow canvas (which renders only their dedicated-field inputs, no
     * params_json fallback - see campaign-flow-editor.js's renderFields()) silently lost that
     * configuration on every save. 'split' has no ActionPool entry (CampaignDispatcher::runSplit()
     * handles it directly) but still flows through this same save path.
     */
    private const array ACTION_FIELDS_BY_TYPE = [
        'add_tag' => ['tags'],
        'send_email' => ['template', 'message', 'use_optimal_send_time', 'topic'],
        'generate_coupon' => ['rule_id', 'prefix'],
        'popup' => ['headline', 'body', 'cta_label', 'cta_url'],
        'add_points' => ['points'],
        'add_product_recommendations' => ['count'],
        'add_dynamic_content' => ['content_block_id', 'output_key'],
        'send_sms' => ['message', 'topic'],
        'notify' => ['headline', 'body', 'cta_label', 'cta_url'],
        'nps_survey' => ['question'],
        'send_whatsapp' => ['template_id', 'params', 'topic'],
        'send_push' => ['title', 'body', 'url', 'topic'],
        'generate_ai_content' => ['prompt', 'output_key', 'fallback'],
        'split' => ['variants'],
    ];

    /**
     * Model\Campaign\ScheduledTriggerScanner is the only reader of either of these - every other
     * trigger type has no params of its own.
     */
    private const array TRIGGER_FIELDS_BY_TYPE = [
        'scheduled_at' => ['scheduled_at'],
        'recurring_schedule' => ['cron_expression'],
    ];

    /**
     * Dedicated fields whose posted value is itself JSON (an array/object), not a plain scalar -
     * the split action's own 'variants' field (campaign-flow-editor.js's renderVariantEditor()
     * serializes its whole in-memory model into one hidden input, same as `params_json` itself,
     * just under its own dedicated key instead of replacing the whole params blob) and 'tags'
     * (tag-chip-editor.js does the same for its chip list). Every other field named in
     * CONDITION_FIELDS_BY_TYPE/ACTION_FIELDS_BY_TYPE/TRIGGER_FIELDS_BY_TYPE is a plain string,
     * stored as-is.
     */
    private const array JSON_DEDICATED_PARAM_FIELDS = ['variants', 'tags'];

    public function __construct(
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
        private readonly CacheInterface $cache
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

        /** @var array<int, array<string, mixed>> $triggerRows */
        $triggerRows = (array) ($data['triggers']['triggers'] ?? []);
        /** @var array<int, array<string, mixed>> $conditionRows */
        $conditionRows = (array) ($data['conditions']['conditions'] ?? []);
        /** @var array<int, array<string, mixed>> $actionRows */
        $actionRows = (array) ($data['actions']['actions'] ?? []);
        /** @var array<int, string|int> $websiteIds */
        $websiteIds = (array) ($data['website_ids'] ?? []);

        $this->campaignResource->save($campaign);
        $this->saveTriggers((int) $campaign->getEntityId(), $triggerRows);
        $this->saveChildRows(
            (int) $campaign->getEntityId(),
            $conditionRows,
            $actionRows
        );
        $this->saveWebsites((int) $campaign->getEntityId(), $websiteIds);

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

            $trigger = $this->campaignTriggerFactory->create();
            $trigger->setData([
                'campaign_id' => $campaignId,
                'trigger_event' => $triggerEvent,
                'params' => $this->normalizeRowParams($row, self::TRIGGER_FIELDS_BY_TYPE[$triggerEvent] ?? []),
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
                'params' => $this->normalizeRowParams($row, self::CONDITION_FIELDS_BY_TYPE[$type] ?? []),
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
            $action = $this->campaignActionFactory->create();
            $action->setData([
                'campaign_id' => $campaignId,
                'type' => $type,
                'params' => $this->normalizeRowParams($row, self::ACTION_FIELDS_BY_TYPE[$type] ?? []),
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

    /**
     * Starts from whatever was typed in the JSON textarea (if valid), then overlays whichever of
     * $relevantFields are present on the row — dedicated fields win over the JSON textarea on
     * key conflicts, since a stale/copy-pasted JSON blob shouldn't silently override a field
     * the admin just filled in and can see. $relevantFields is the row's own type's entry from
     * CONDITION_FIELDS_BY_TYPE/ACTION_FIELDS_BY_TYPE/TRIGGER_FIELDS_BY_TYPE (or [] for an
     * unmapped type) - never the full field set, so a field some other type's row happens to have
     * posted a value for never leaks into this row's params.
     *
     * @param array<string, mixed> $row
     * @param array<int, string> $relevantFields
     */
    private function normalizeRowParams(array $row, array $relevantFields): string
    {
        $params = $this->parseJson((string) ($row['params_json'] ?? ''));

        foreach ($relevantFields as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value === '') {
                continue;
            }

            if (in_array($field, self::JSON_DEDICATED_PARAM_FIELDS, true)) {
                $decoded = json_decode($value, true);
                $params[$field] = is_array($decoded) ? $decoded : [];
                continue;
            }

            $params[$field] = $value;
        }

        // json_encode([]) is the JSON array literal "[]", not the empty JSON OBJECT "{}" a
        // condition/action's params conceptually are (a key-value map, not a list) - forced
        // explicitly rather than relying on json_encode's own empty-array behavior, which flips
        // to "[]" the moment $params has zero keys (same fix applied to
        // Model\Segment\SegmentSaveProcessor's identical method, caught there first by its own
        // test asserting the exact "{}" string).
        return $params === [] ? '{}' : (json_encode($params) ?: '{}');
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
