<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Segment\SegmentAudienceSizeHistory;

use Ordo\Automation\Model\ResourceModel\Segment\SegmentAudienceSizeHistory\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testAddSegmentFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addSegmentFilter(7);

        self::assertSame($collection, $result);
    }
}
