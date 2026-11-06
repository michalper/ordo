<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Anomaly\AnomalyAlert;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\Anomaly\AnomalyAlert as AnomalyAlertModel;
use Ordo\Automation\Model\ResourceModel\Anomaly\AnomalyAlert as AnomalyAlertResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(AnomalyAlertModel::class, AnomalyAlertResource::class);
    }
}
