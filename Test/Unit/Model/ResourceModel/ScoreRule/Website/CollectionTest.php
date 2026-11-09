<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\ScoreRule\Website;

use Ordo\Automation\Model\ResourceModel\ScoreRule\Website\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    private function makeCollection(): Collection
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $fetchStrategy->method('fetchAll')->willReturn([]);
        return new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $this->makeResource());
    }

    public function testAddScoreRuleFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addScoreRuleFilter(42);

        self::assertSame($collection, $result);
    }

    public function testAddScoreRuleIdsFilterIsFluent(): void
    {
        $collection = $this->makeCollection();

        $result = $collection->addScoreRuleIdsFilter([42, 43]);

        self::assertSame($collection, $result);
    }
}
