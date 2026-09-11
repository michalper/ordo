<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Segment;

use Ordo\Automation\Model\ResourceModel\Segment\SegmentAudienceSizeHistory;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class SegmentAudienceSizeHistoryTest extends AbstractDbTestCase
{
    public function testInitializesWithSegmentAudienceSizeHistoryTableAndEntityIdField(): void
    {
        $resource = new SegmentAudienceSizeHistory($this->makeDbContext());

        self::assertSame('ordo_segment_audience_size_history', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
