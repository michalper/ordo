<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Cron;

use Ordo\Automation\Model\ResourceModel\Cron\CronRunLog;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class CronRunLogTest extends AbstractDbTestCase
{
    public function testInitializesWithCronRunLogTableAndEntityIdField(): void
    {
        $resource = new CronRunLog($this->makeDbContext());

        self::assertSame('ordo_cron_run_log', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
