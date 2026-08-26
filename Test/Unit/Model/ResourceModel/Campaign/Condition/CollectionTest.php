<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Campaign\Condition;

use Ordo\Automation\Model\ResourceModel\Campaign\Condition\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    public function testAddCampaignFilterIsFluent(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());

        self::assertSame($collection, $collection->addCampaignFilter(5));
    }
}
