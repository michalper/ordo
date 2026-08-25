<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Condition extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_campaign_condition', 'entity_id');
    }
}
