<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Campaign;

use Ordo\Automation\Model\ResourceModel\Campaign\Trigger;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class TriggerTest extends AbstractDbTestCase
{
    public function testInitializesWithTriggerTableAndEntityIdField(): void
    {
        $resource = new Trigger($this->makeDbContext());

        self::assertSame('ordo_campaign_trigger', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
