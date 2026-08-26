<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\OrderApproval;

use Ordo\Automation\Model\ResourceModel\OrderApproval\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    public function testAddStalePendingFilterIsFluent(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());

        self::assertSame($collection, $collection->addStalePendingFilter('2026-01-01 00:00:00'));
    }
}
