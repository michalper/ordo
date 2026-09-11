<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\AdminActionLog;

use Ordo\Automation\Model\ResourceModel\AdminActionLog as AdminActionLogResource;
use Ordo\Automation\Model\ResourceModel\AdminActionLog\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    public function testConstructsWithAdminActionLogResourceModel(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $resource = $this->makeResource();
        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $resource);

        self::assertSame(AdminActionLogResource::class, $collection->getResourceModelName());
        self::assertSame($resource, $collection->getResource());
    }
}
