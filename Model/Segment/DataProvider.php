<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Segment;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Segment\Condition\CollectionFactory as SegmentConditionCollectionFactory;

/**
 * Feeds the segment edit form, including its one dynamicRows section (conditions) — same
 * approach as Model\Campaign\DataProvider.
 */
class DataProvider extends AbstractDataProvider
{
    protected ?array $loadedData = null;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        SegmentCollectionFactory $collectionFactory,
        private readonly SegmentConditionCollectionFactory $segmentConditionCollectionFactory,
        private readonly DataPersistorInterface $dataPersistor,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $this->loadedData = [];

        foreach ($this->collection->getItems() as $segment) {
            $segmentData = $segment->getData();
            $segmentId = (int) $segment->getEntityId();

            $segmentData['conditions'] = $this->loadConditionRows($segmentId);

            $this->loadedData[$segmentId] = $segmentData;
        }

        $persisted = $this->dataPersistor->get('ordo_segment');
        if ($persisted) {
            $segmentId = $persisted['entity_id'] ?? null;
            if ($segmentId) {
                $this->loadedData[$segmentId] = $persisted;
            }
            $this->dataPersistor->clear('ordo_segment');
        }

        return $this->loadedData;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadConditionRows(int $segmentId): array
    {
        $collection = $this->segmentConditionCollectionFactory->create();
        $collection->addSegmentFilter($segmentId);

        $rows = [];
        foreach ($collection as $row) {
            $rows[] = [
                'type' => $row->getData('type'),
                'params_json' => (string) $row->getData('params'),
            ];
        }

        return $rows;
    }
}
