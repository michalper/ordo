<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\ProductFeedRunLog;

use Ordo\Automation\Model\ResourceModel\ProductFeedRunLog as ProductFeedRunLogResource;
use Ordo\Automation\Model\ResourceModel\ProductFeedRunLog\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    public function testConstructsWithProductFeedRunLogResourceModel(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $resource = $this->makeResource();
        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $resource);

        self::assertSame(ProductFeedRunLogResource::class, $collection->getResourceModelName());
        self::assertSame($resource, $collection->getResource());
    }
}
