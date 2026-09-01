<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\FreeGiftOfferTier;

use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testAddOfferFilterIsFluent(): void
    {
        $collection = $this->makeCollection();
        self::assertSame($collection, $collection->addOfferFilter(5));
    }

    public function testAddOffersFilterIsFluent(): void
    {
        $collection = $this->makeCollection();
        self::assertSame($collection, $collection->addOffersFilter([5, 6]));
    }
}
