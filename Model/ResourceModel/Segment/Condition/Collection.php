<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Segment\Condition;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Condition as SegmentConditionResource;
use Ordo\Automation\Model\SegmentCondition as SegmentConditionModel;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(SegmentConditionModel::class, SegmentConditionResource::class);
    }

    public function addSegmentFilter(int $segmentId): self
    {
        $this->addFieldToFilter('segment_id', $segmentId);
        $this->setOrder('sort_order', self::SORT_ORDER_ASC);
        return $this;
    }
}
