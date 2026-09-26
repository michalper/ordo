<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Referral;

use Ordo\Automation\Model\ResourceModel\Referral\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    public function testAddReferredCustomerFilterIsFluent(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());

        self::assertSame($collection, $collection->addReferredCustomerFilter(7));
    }

    public function testAddStatusFilterIsFluent(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());

        self::assertSame($collection, $collection->addStatusFilter('pending'));
    }
}
