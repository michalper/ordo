<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\MessageSendRetry;

use Ordo\Automation\Model\ResourceModel\MessageSendRetry as MessageSendRetryResource;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testConstructsWithMessageSendRetryResourceModel(): void
    {
        $collection = $this->makeCollection();

        self::assertSame(MessageSendRetryResource::class, $collection->getResourceModelName());
    }

    public function testAddDueFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        self::assertSame($collection, $collection->addDueFilter('2026-01-01 00:00:00', 5));
    }
}
