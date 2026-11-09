<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Segment;

use Ordo\Automation\Model\ResourceModel\Segment\Website;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class WebsiteTest extends AbstractDbTestCase
{
    public function testInitializesWithSegmentWebsiteTableAndEntityIdField(): void
    {
        $resource = new Website($this->makeDbContext());

        self::assertSame('ordo_segment_website', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
