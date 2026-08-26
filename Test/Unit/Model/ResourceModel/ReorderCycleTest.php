<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\ReorderCycle;

class ReorderCycleTest extends AbstractDbTestCase
{
    public function testInitializesWithReorderCycleTableAndEntityIdField(): void
    {
        $resource = new ReorderCycle($this->makeDbContext());

        self::assertSame('ordo_reorder_cycle', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
