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
}
