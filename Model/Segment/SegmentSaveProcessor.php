<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Segment;

use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Segment\Website as SegmentWebsiteResource;
use Ordo\Automation\Model\ResourceModel\Segment\Website\CollectionFactory as SegmentWebsiteCollectionFactory;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\SegmentConditionFactory;
use Ordo\Automation\Model\SegmentFactory;
use Ordo\Automation\Model\SegmentWebsiteFactory;

/**
 * Persists the segment row plus its condition child rows in one request — same
 * delete-and-reinsert approach as Model\Campaign\CampaignSaveProcessor for the same reason (a
 * segment realistically has a handful of conditions, not hundreds).
 *
 * Extracted from Controller\Adminhtml\Segment\Save so the persistence logic can be unit tested
 * and reused without a controller in the loop; the controller still owns the
 * HTTP/session/redirect concerns.
 */
class SegmentSaveProcessor
{
    /**
     * Which dedicated fields are actually relevant to each condition type - keyed by type, not a
     * flat list, so normalizeRowParams() only merges a field into a row's params when that row's
     * own type actually reads it. A flat list used to merge ANY non-empty dedicated field into
     * EVERY row regardless of type, because Magento_Ui's dynamicRows always posts every field's
     * current value even when switcherConfig hides it for that row's type (e.g. a
     * monetary_total_at_least condition's saved params would pick up a useless tier: "bronze" key
     * just because some other row on the same form happened to be a loyalty_tier_at_least
     * condition). Mirrors Model\Campaign\CampaignSaveProcessor::CONDITION_FIELDS_BY_TYPE exactly -
     * a segment condition uses the same ConditionPool types a campaign condition does, 'amount'/
     * 'rule_id' and similar action-only/campaign-only fields just never appear here since segments
     * have no actions. Verified against every Model\Campaign\Condition\*.php class's own real
     * params reads, not just UI config.
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
     * Dedicated fields whose posted value is itself JSON (an array), not a plain scalar - mirrors
     * Model\Campaign\CampaignSaveProcessor's own JSON_DEDICATED_PARAM_FIELDS (currently just its
     * 'variants'; this form has no variant_list field, so 'tags' is the first entry here).
     */
    private const array JSON_DEDICATED_PARAM_FIELDS = ['tags'];

    /**
     * Applies to both the top-level conditions list and a single group's own nested list - the
     * admin UI has no client-side equivalent cap (Magento_Ui's dynamic-rows.js in this version
     * has no built-in "max records" option to hook into), so this is the actual enforcement
     * point: extra rows beyond the 10th are silently dropped rather than saved, matching
     * ordo_segment_form.xml's own "up to 10" notice text.
     */
    private const int MAX_CONDITIONS_PER_LIST = 10;

    public function __construct(
        private readonly SegmentFactory $segmentFactory,
        private readonly SegmentResource $segmentResource,
        private readonly SegmentConditionFactory $segmentConditionFactory,
        private readonly SegmentConditionResource $segmentConditionResource,
        private readonly SegmentConditionCollectionFactory $segmentConditionCollectionFactory,
        private readonly SegmentWebsiteFactory $segmentWebsiteFactory,
        private readonly SegmentWebsiteResource $segmentWebsiteResource,
        private readonly SegmentWebsiteCollectionFactory $segmentWebsiteCollectionFactory
    ) {
    }

    /**
     * Loads the segment (when an entity_id was posted), applies the posted fields, saves it,
     * and rebuilds its condition child rows. Returns the saved segment so the caller can read
     * back the entity_id for redirects/messages.
     *
     * @param array<string, mixed> $data
     */
    public function process(array $data): Segment
    {
        $entityId = (int) ($data['entity_id'] ?? 0);
        $segment = $this->segmentFactory->create();

        if ($entityId) {
            $this->segmentResource->load($segment, $entityId);
        }

        $segment->setName((string) ($data['name'] ?? ''));
        $segment->setEnabled(!empty($data['enabled']));
        $segment->setConditionLogic((string) ($data['condition_logic'] ?? 'all'));

        /** @var array<int, array<string, mixed>> $conditionRows */
        $conditionRows = (array) ($data['conditions']['conditions'] ?? []);
        $websiteIds = (array) ($data['website_ids'] ?? []);

        $this->segmentResource->save($segment);
        $this->saveConditions((int) $segment->getEntityId(), $conditionRows);
        $this->saveWebsites((int) $segment->getEntityId(), $websiteIds);

        return $segment;
    }

    /**
     * No rows at all means unscoped (matches customers on every website) - see docs/adr/0014 -
     * so an empty posted selection (the common case; most installs are single-website) correctly
     * leaves this segment with zero website rows rather than writing anything. Mirrors
     * Model\Campaign\CampaignSaveProcessor::saveWebsites() exactly.
     *
     * @param array<int, string|int> $websiteIds
     */
    private function saveWebsites(int $segmentId, array $websiteIds): void
    {
        $existingWebsites = $this->segmentWebsiteCollectionFactory->create();
        $existingWebsites->addSegmentFilter($segmentId);
        foreach ($existingWebsites as $existing) {
            $this->segmentWebsiteResource->delete($existing);
        }

        $seen = [];
        foreach ($websiteIds as $websiteId) {
            $websiteId = (int) $websiteId;
            if ($websiteId <= 0 || isset($seen[$websiteId])) {
                continue;
            }
            $seen[$websiteId] = true;

            $website = $this->segmentWebsiteFactory->create();
            $website->setData([
                'segment_id' => $segmentId,
                'website_id' => $websiteId,
            ]);
            $this->segmentWebsiteResource->save($website);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $conditionRows
     */
    private function saveConditions(int $segmentId, array $conditionRows): void
    {
        $existing = $this->segmentConditionCollectionFactory->create();
        $existing->addSegmentFilter($segmentId);
        foreach ($existing as $row) {
            $this->segmentConditionResource->delete($row);
        }

        $sortOrder = 0;
        foreach (array_slice($conditionRows, 0, self::MAX_CONDITIONS_PER_LIST) as $row) {
            if (empty($row['type'])) {
                continue;
            }

            $type = (string) $row['type'];
            $condition = $this->segmentConditionFactory->create();
            $condition->setData([
                'segment_id' => $segmentId,
                'type' => $type,
                'params' => $type === 'group'
                    ? $this->normalizeGroupRow($row)
                    : $this->normalizeRowParams($row, self::CONDITION_FIELDS_BY_TYPE[$type] ?? []),
                'sort_order' => $sortOrder++,
            ]);
            $this->segmentConditionResource->save($condition);
        }
    }

    /**
     * A 'group' row's own conditions come from group_conditions_json - a plain JSON array of
     * {type, params} built client-side by Ordo_Automation/js/segment-group-modal.js's modal (see
     * ordo_segment_form.xml's own comment on group_conditions_json). Nested dynamicRows-inside-
     * dynamicRows was tried first and is NOT a supported Magento_Ui pattern in this version
     * (confirmed against every core module: no dynamicRows anywhere nests another dynamicRows in
     * its own record) - its "Add Condition to Group" button silently did nothing, a real
     * functional bug rather than a styling one. The modal reuses the exact
     * "field-per-type-with-a-single-value-slot" model the dedicated fields already established,
     * just built in vanilla JS instead of another declarative dynamicRows. Re-validated and
     * re-serialized here (not trusted verbatim) so a hand-crafted/tampered POST can't smuggle
     * more than MAX_CONDITIONS_PER_LIST rows or a non-array shape into a saved segment. One level
     * of nesting only: a nested condition can't itself be type 'group' (the modal's own Type
     * dropdown never offers it - see ordo_segment_form.xml's own comment on
     * Model\Config\Source\ConditionTypeWithGroup), so this never needs to recurse further.
     *
     * @param array<string, mixed> $row
     */
    private function normalizeGroupRow(array $row): string
    {
        $logic = ($row['group_logic'] ?? 'all') === 'any' ? 'any' : 'all';

        $decoded = json_decode((string) ($row['group_conditions_json'] ?? ''), true);
        $nestedRows = is_array($decoded) ? $decoded : [];

        $conditions = [];
        foreach (array_slice($nestedRows, 0, self::MAX_CONDITIONS_PER_LIST) as $nestedRow) {
            if (!is_array($nestedRow) || empty($nestedRow['type']) || !is_string($nestedRow['type'])) {
                continue;
            }

            $params = $nestedRow['params'] ?? [];
            $conditions[] = [
                'type' => $nestedRow['type'],
                'params' => is_array($params) && $params !== [] ? $params : new \stdClass(),
            ];
        }

        return json_encode(['logic' => $logic, 'conditions' => $conditions]) ?: '{}';
    }

    /**
     * Starts from whatever was typed in the JSON textarea (if valid), then overlays whichever of
     * $relevantFields are present on the row — same merge Model\Campaign\CampaignSaveProcessor
     * already uses for its own conditions/actions. $relevantFields is the row's own type's entry
     * from CONDITION_FIELDS_BY_TYPE (or [] for an unmapped type) - never the full field set, so a
     * field some other type's row happens to have posted a value for never leaks into this row's
     * params.
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
        // condition's params conceptually are (a key-value map, not a list) - forced explicitly
        // rather than relying on json_encode's own empty-array behavior, which flips to "[]"
        // the moment $params has zero keys.
        return $params === [] ? '{}' : (json_encode($params) ?: '{}');
    }

    /**
     * Falls back to an empty object rather than rejecting the save outright — a condition with
     * unparsable params simply won't find the keys it expects when matched, fails closed rather
     * than crashing.
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
