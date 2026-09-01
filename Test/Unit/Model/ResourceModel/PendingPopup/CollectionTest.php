<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\PendingPopup;

use Ordo\Automation\Model\ResourceModel\PendingPopup\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $fetchStrategy->method('fetchAll')->willReturn([]);
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testAddTargetFilterIsFluentWithBothIdentifiers(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addTargetFilter(42, 'v1', '2026-01-01 12:00:00');

        self::assertSame($collection, $result);
    }

    public function testAddTargetFilterIsFluentWithCustomerIdOnly(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addTargetFilter(42, null, '2026-01-01 12:00:00');

        self::assertSame($collection, $result);
    }

    public function testAddTargetFilterIsFluentWithVisitorIdOnly(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addTargetFilter(null, 'v1', '2026-01-01 12:00:00');

        self::assertSame($collection, $result);
    }

    public function testTargetHasRecentlyReceivedPopupReturnsFalseWhenNoneFound(): void
    {
        $collection = $this->makeCollection();

        self::assertFalse($collection->targetHasRecentlyReceivedPopup(42, 'v1', '2026-01-01 12:00:00'));
    }

    public function testTargetHasRecentlyReceivedPopupWithCustomerIdOnly(): void
    {
        $collection = $this->makeCollection();

        self::assertFalse($collection->targetHasRecentlyReceivedPopup(42, null, '2026-01-01 12:00:00'));
    }

    public function testTargetHasRecentlyReceivedPopupWithVisitorIdOnly(): void
    {
        $collection = $this->makeCollection();

        self::assertFalse($collection->targetHasRecentlyReceivedPopup(null, 'v1', '2026-01-01 12:00:00'));
    }
}
