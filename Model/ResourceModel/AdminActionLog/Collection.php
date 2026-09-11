<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\AdminActionLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\AdminActionLog as AdminActionLogModel;
use Ordo\Automation\Model\ResourceModel\AdminActionLog as AdminActionLogResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(AdminActionLogModel::class, AdminActionLogResource::class);
    }
}
