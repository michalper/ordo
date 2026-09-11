<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class AdminActionLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_admin_action_log', 'entity_id');
    }
}
