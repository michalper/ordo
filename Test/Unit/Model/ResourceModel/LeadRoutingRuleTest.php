<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\LeadRoutingRule;

class LeadRoutingRuleTest extends AbstractDbTestCase
{
    public function testInitializesWithLeadRoutingRuleTableAndEntityIdField(): void
    {
        $resource = new LeadRoutingRule($this->makeDbContext());

        self::assertSame('ordo_lead_routing_rule', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
