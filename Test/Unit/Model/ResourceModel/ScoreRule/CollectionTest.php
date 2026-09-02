<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\ScoreRule;

use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    /**
     * No custom filter methods here — this only wires _construct() to the right model/resource
     * pair, so the smoke test is just confirming that wiring, same as Segment\CollectionTest.
     */
    public function testConstructsWithScoreRuleResourceModel(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $resource = $this->makeResource();
        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $resource);

        self::assertSame(ScoreRuleResource::class, $collection->getResourceModelName());
        self::assertSame($resource, $collection->getResource());
    }
}
