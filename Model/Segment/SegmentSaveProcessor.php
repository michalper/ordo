<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Segment;

use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\SegmentConditionFactory;
use Ordo\Automation\Model\SegmentFactory;

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
    public function __construct(
        private readonly SegmentFactory $segmentFactory,
        private readonly SegmentResource $segmentResource,
        private readonly SegmentConditionFactory $segmentConditionFactory,
        private readonly SegmentConditionResource $segmentConditionResource,
        private readonly SegmentConditionCollectionFactory $segmentConditionCollectionFactory
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

        /** @var array<int, array<string, mixed>> $conditionRows */
        $conditionRows = (array) ($data['conditions']['conditions'] ?? []);

        $this->segmentResource->save($segment);
        $this->saveConditions((int) $segment->getEntityId(), $conditionRows);

        return $segment;
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
        foreach ($conditionRows as $row) {
            if (empty($row['type'])) {
                continue;
            }

            $condition = $this->segmentConditionFactory->create();
            $condition->setData([
                'segment_id' => $segmentId,
                'type' => (string) $row['type'],
                'params' => (string) ($row['params_json'] ?? '{}') ?: '{}',
                'sort_order' => $sortOrder++,
            ]);
            $this->segmentConditionResource->save($condition);
        }
    }
}
