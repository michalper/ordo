<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\LeadRoutingRule;

use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\Collection;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractCollectionTestCase;

class CollectionTest extends AbstractCollectionTestCase
{
    /**
     * No custom filter methods here — this only wires _construct() to the right model/resource
     * pair, same as ScoreRule\CollectionTest.
     */
    public function testConstructsWithLeadRoutingRuleResourceModel(): void
    {
        [$entityFactory, $logger, $fetchStrategy, $eventManager] = $this->makeCollectionDeps();
        $resource = $this->makeResource();
        $collection = new Collection($entityFactory, $logger, $fetchStrategy, $eventManager, null, $resource);

        self::assertSame(LeadRoutingRuleResource::class, $collection->getResourceModelName());
        self::assertSame($resource, $collection->getResource());
    }
}
