<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\PriceWatch;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PriceWatchSubscription extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_price_watch_subscription', 'entity_id');
    }
}
