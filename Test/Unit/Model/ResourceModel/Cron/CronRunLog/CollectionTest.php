<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Cron\CronRunLog;

use Ordo\Automation\Model\ResourceModel\Cron\CronRunLog as CronRunLogResource;
use Ordo\Automation\Model\ResourceModel\Cron\CronRunLog\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    public function testConstructsWithCronRunLogResourceModel(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $resource = $this->makeResource();
        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $resource);

        self::assertSame(CronRunLogResource::class, $collection->getResourceModelName());
        self::assertSame($resource, $collection->getResource());
    }
}
