<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\PushSendRetry;

use Ordo\Automation\Model\ResourceModel\PushSendRetry as PushSendRetryResource;
use Ordo\Automation\Model\ResourceModel\PushSendRetry\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testConstructsWithPushSendRetryResourceModel(): void
    {
        $collection = $this->makeCollection();

        self::assertSame(PushSendRetryResource::class, $collection->getResourceModelName());
    }

    public function testAddDueFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        self::assertSame($collection, $collection->addDueFilter('2026-01-01 00:00:00', 5));
    }
}
