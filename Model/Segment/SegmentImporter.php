<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Segment;

use Ordo\Automation\Api\Campaign\ConditionInterface;
use Ordo\Automation\Model\Campaign\ConditionPool;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\SegmentConditionFactory;
use Ordo\Automation\Model\SegmentFactory;

/**
 * The other half of Controller\Adminhtml\Segment\Export - turns that same JSON payload back into
 * a real (always NEW, never overwritten - see Export's own docblock on why re-import assigns
 * fresh ids) segment row plus its condition child rows. Deliberately its own class rather than
 * reusing Model\Segment\SegmentSaveProcessor: that processor's `process()` expects the admin
 * FORM's own posted shape (params_json/dedicated-fields-to-merge, group_conditions_json, ...),
 * not the already-clean {type, params, sort_order} shape Export produces - forcing the export
 * shape through the form-shape processor would mean re-encoding params back into a fake
 * params_json string just to have the processor immediately decode it again, for no benefit.
 *
 * A malformed/unknown-type condition row is dropped rather than failing the whole import - same
 * fail-soft philosophy SegmentSaveProcessor itself already applies to a hand-crafted/tampered
 * form POST. A fundamentally wrong file (missing name, wrong export_type) is rejected outright,
 * since there's nothing reasonable to import at all in that case.
 */
class SegmentImporter
{
    private const string EXPECTED_EXPORT_TYPE = 'ordo_segment';

    /**
     * Same cap Model\Segment\SegmentSaveProcessor::MAX_CONDITIONS_PER_LIST enforces for a normal
     * form save - an imported file gets no special exemption from it.
     */
    private const int MAX_CONDITIONS = 10;

    public function __construct(
        private readonly SegmentFactory $segmentFactory,
        private readonly SegmentResource $segmentResource,
        private readonly SegmentConditionFactory $segmentConditionFactory,
        private readonly SegmentConditionResource $segmentConditionResource,
        private readonly ConditionPool $conditionPool
    ) {
    }

    /**
     * @param array<string, mixed> $payload decoded JSON, same shape Export produces
     * @throws \InvalidArgumentException the payload isn't a segment export at all (wrong
     *   export_type, missing/blank name) - nothing reasonable to import.
     */
    public function import(array $payload): Segment
    {
        if (($payload['export_type'] ?? null) !== self::EXPECTED_EXPORT_TYPE) {
            throw new \InvalidArgumentException(
                'This file is not a segment export (missing or unexpected "export_type").'
            );
        }

        $rawName = $payload['name'] ?? '';
        $name = trim(is_string($rawName) ? $rawName : '');
        if ($name === '') {
            throw new \InvalidArgumentException('The segment export is missing a name.');
        }

        $segment = $this->segmentFactory->create();
        $segment->setName($name);
        $segment->setEnabled(!empty($payload['enabled']));
        $conditionLogic = ($payload['condition_logic'] ?? 'all') === 'any' ? 'any' : 'all';
        $segment->setConditionLogic($conditionLogic);

        $this->segmentResource->save($segment);

        $conditions = $payload['conditions'] ?? [];
        $this->saveConditions((int) $segment->getEntityId(), is_array($conditions) ? $conditions : []);

        return $segment;
    }

    /**
     * @param array<mixed, mixed> $conditionRows
     */
    private function saveConditions(int $segmentId, array $conditionRows): void
    {
        $sortOrder = 0;
        foreach (array_slice(array_values($conditionRows), 0, self::MAX_CONDITIONS) as $row) {
            if (!is_array($row) || !isset($row['type']) || !is_string($row['type']) || $row['type'] === '') {
                continue;
            }

            $type = $row['type'];
            if ($type !== 'group' && !$this->conditionPool->get($type) instanceof ConditionInterface) {
                continue;
            }

            $params = $row['params'] ?? [];
            $condition = $this->segmentConditionFactory->create();
            $condition->setSegmentId($segmentId);
            $condition->setType($type);
            $condition->setParamsJson(is_array($params) ? (json_encode($params) ?: '{}') : '{}');
            $condition->setSortOrder($sortOrder++);
            $this->segmentConditionResource->save($condition);
        }
    }
}
