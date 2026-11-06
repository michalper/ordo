<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Anomaly;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class AnomalyAlert extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_anomaly_alert', 'entity_id');
    }
}
