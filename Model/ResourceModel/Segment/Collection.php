<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Segment;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\Segment as SegmentModel;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(SegmentModel::class, SegmentResource::class);
    }
}
