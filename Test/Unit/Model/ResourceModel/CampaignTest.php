<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\Campaign;

class CampaignTest extends AbstractDbTestCase
{
    public function testInitializesWithCampaignTableAndEntityIdField(): void
    {
        $resource = new Campaign($this->makeDbContext());

        self::assertSame('ordo_campaign', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
