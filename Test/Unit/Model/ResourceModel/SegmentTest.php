<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\Segment;

class SegmentTest extends AbstractDbTestCase
{
    public function testInitializesWithSegmentTableAndEntityIdField(): void
    {
        $resource = new Segment($this->makeDbContext());

        self::assertSame('ordo_segment', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
