<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Segment\Website;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ResourceModel\Segment\Website as SegmentWebsiteResource;
use Ordo\Automation\Model\SegmentWebsite as SegmentWebsiteModel;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(SegmentWebsiteModel::class, SegmentWebsiteResource::class);
    }

    public function addSegmentFilter(int $segmentId): self
    {
        $this->addFieldToFilter('segment_id', $segmentId);
        return $this;
    }
}
