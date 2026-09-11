<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\MessageLogEvent;

use Ordo\Automation\Model\ResourceModel\MessageLogEvent as MessageLogEventResource;
use Ordo\Automation\Model\ResourceModel\MessageLogEvent\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testConstructsWithMessageLogEventResourceModel(): void
    {
        $collection = $this->makeCollection();

        self::assertSame(MessageLogEventResource::class, $collection->getResourceModelName());
    }

    public function testAddMessageLogIdFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        self::assertSame($collection, $collection->addMessageLogIdFilter(7));
    }

    public function testAddEventTypeFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        self::assertSame($collection, $collection->addEventTypeFilter('opened'));
    }
}
