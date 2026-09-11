<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ProductFeedRunLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_product_feed_run_log', 'entity_id');
    }
}
