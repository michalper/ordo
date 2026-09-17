<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\PriceWatch\PriceWatchSubscription;

use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $fetchStrategy->method('fetchAll')->willReturn([]);
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testAddCustomerFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addCustomerFilter(42);

        self::assertSame($collection, $result);
    }

    public function testAddVisitorFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addVisitorFilter('visitor-abc123');

        self::assertSame($collection, $result);
    }

    public function testAddProductFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addProductFilter(10);

        self::assertSame($collection, $result);
    }

    public function testAddWatchTypeFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addWatchTypeFilter(PriceWatchSubscription::WATCH_TYPE_PRICE_DROP);

        self::assertSame($collection, $result);
    }

    public function testAddNotNotifiedFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addNotNotifiedFilter();

        self::assertSame($collection, $result);
    }
}
