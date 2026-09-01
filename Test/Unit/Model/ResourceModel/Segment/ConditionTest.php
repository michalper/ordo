<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Segment;

use Ordo\Automation\Model\ResourceModel\Segment\Condition;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class ConditionTest extends AbstractDbTestCase
{
    public function testInitializesWithConditionTableAndEntityIdField(): void
    {
        $resource = new Condition($this->makeDbContext());

        self::assertSame('ordo_segment_condition', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
