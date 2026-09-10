<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\MessageLog;

use Ordo\Automation\Model\ResourceModel\MessageLog\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    public function testConstructWiresModelAndResource(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $resource = $this->makeResource('ordo_message_log');

        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $resource);

        self::assertSame($resource, $collection->getResource());
        self::assertSame('ordo_message_log', $collection->getResource()->getMainTable());
    }

    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $fetchStrategy->method('fetchAll')->willReturn([]);
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    /**
     * @see Model\Campaign\FrequencyCapManager::hasCapacity() - these 3 filters are its whole
     * query, chained fluently.
     */
    public function testAddCustomerFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        self::assertSame($collection, $collection->addCustomerFilter(42));
    }

    public function testAddSentSinceFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        self::assertSame($collection, $collection->addSentSinceFilter('2026-01-01 00:00:00'));
    }

    public function testAddRealSendAttemptFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        self::assertSame($collection, $collection->addRealSendAttemptFilter());
    }
}
