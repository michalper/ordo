<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Segment;

use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    /**
     * No custom filter methods here (unlike Segment\Condition\Collection) — this only wires
     * _construct() to the right model/resource pair, so the smoke test is just confirming that
     * wiring, the same thing every other Collection test in this suite implicitly proves via
     * its filter methods running without error.
     */
    public function testConstructsWithSegmentResourceModel(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $resource = $this->makeResource();
        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $resource);

        self::assertSame(SegmentResource::class, $collection->getResourceModelName());
        self::assertSame($resource, $collection->getResource());
    }
}
