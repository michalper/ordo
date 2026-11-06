<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\CustomerConsentTopic;

use Ordo\Automation\Model\ResourceModel\CustomerConsentTopic\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $fetchStrategy->method('fetchAll')->willReturn([]);
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testAddCustomerAndChannelFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addCustomerAndChannelFilter(42, 'email');

        self::assertSame($collection, $result);
    }

    public function testAddCustomerChannelAndTopicFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addCustomerChannelAndTopicFilter(42, 'email', 'promotional');

        self::assertSame($collection, $result);
    }

    public function testAddCustomerIdsChannelAndTopicFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addCustomerIdsChannelAndTopicFilter([42, 43], 'email', 'promotional');

        self::assertSame($collection, $result);
    }
}
