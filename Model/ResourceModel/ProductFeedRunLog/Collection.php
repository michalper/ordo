<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\ProductFeedRunLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\ProductFeed\ProductFeedRunLog as ProductFeedRunLogModel;
use Ordo\Automation\Model\ResourceModel\ProductFeedRunLog as ProductFeedRunLogResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ProductFeedRunLogModel::class, ProductFeedRunLogResource::class);
    }
}
