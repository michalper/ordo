<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Cron;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CronRunLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_cron_run_log', 'entity_id');
    }
}
