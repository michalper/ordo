<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Campaign;

use Ordo\Automation\Model\ResourceModel\Campaign\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    public function testAddEnabledForTriggerFilterIsFluentAndFiltersByTriggerAndEnabled(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());

        $result = $collection->addEnabledForTriggerFilter('order_placed');

        self::assertSame($collection, $result);
    }
}
