<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Anomaly;

use Ordo\Automation\Model\ResourceModel\Anomaly\AnomalyAlert;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class AnomalyAlertTest extends AbstractDbTestCase
{
    public function testInitializesWithAnomalyAlertTableAndEntityIdField(): void
    {
        $resource = new AnomalyAlert($this->makeDbContext());

        self::assertSame('ordo_anomaly_alert', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
