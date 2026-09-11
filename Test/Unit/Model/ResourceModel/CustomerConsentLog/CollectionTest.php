<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\CustomerConsentLog;

use Ordo\Automation\Model\ResourceModel\CustomerConsentLog\Collection;
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
}
