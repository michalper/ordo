<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Campaign\Website;

use Ordo\Automation\Model\ResourceModel\Campaign\Website\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $fetchStrategy->method('fetchAll')->willReturn([]);
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testAddCampaignFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addCampaignFilter(42);

        self::assertSame($collection, $result);
    }

    public function testAddCampaignIdsFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addCampaignIdsFilter([42, 43]);

        self::assertSame($collection, $result);
    }
}
