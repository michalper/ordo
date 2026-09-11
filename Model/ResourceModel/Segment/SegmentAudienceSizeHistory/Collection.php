<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Segment\SegmentAudienceSizeHistory;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ResourceModel\Segment\SegmentAudienceSizeHistory as SegmentAudienceSizeHistoryResource;
use Ordo\Automation\Model\Segment\SegmentAudienceSizeHistory as SegmentAudienceSizeHistoryModel;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(SegmentAudienceSizeHistoryModel::class, SegmentAudienceSizeHistoryResource::class);
    }

    public function addSegmentFilter(int $segmentId): self
    {
        $this->addFieldToFilter('segment_id', ['eq' => $segmentId]);
        $this->setOrder('computed_at', self::SORT_ORDER_ASC);
        return $this;
    }
}
