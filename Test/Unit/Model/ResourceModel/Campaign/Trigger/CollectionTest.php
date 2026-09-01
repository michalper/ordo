<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Campaign\Trigger;

use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testAddCampaignFilterIsFluent(): void
    {
        $collection = $this->makeCollection();
        self::assertSame($collection, $collection->addCampaignFilter(5));
    }

    public function testAddTriggerEventFilterIsFluent(): void
    {
        $collection = $this->makeCollection();
        self::assertSame($collection, $collection->addTriggerEventFilter('order_placed'));
    }
}
