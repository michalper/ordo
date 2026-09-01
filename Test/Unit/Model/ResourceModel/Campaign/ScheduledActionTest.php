<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Campaign;

use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class ScheduledActionTest extends AbstractDbTestCase
{
    public function testInitializesWithScheduledActionTableAndEntityIdField(): void
    {
        $resource = new ScheduledAction($this->makeDbContext());

        self::assertSame('ordo_campaign_scheduled_action', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
