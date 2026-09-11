<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Cron\CronRunLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\Cron\CronRunLog as CronRunLogModel;
use Ordo\Automation\Model\ResourceModel\Cron\CronRunLog as CronRunLogResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CronRunLogModel::class, CronRunLogResource::class);
    }
}
