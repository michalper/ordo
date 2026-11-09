<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Campaign;

use Ordo\Automation\Model\ResourceModel\Campaign\Website;
use Ordo\Automation\Test\Unit\Model\ResourceModel\AbstractDbTestCase;

class WebsiteTest extends AbstractDbTestCase
{
    public function testInitializesWithCampaignWebsiteTableAndEntityIdField(): void
    {
        $resource = new Website($this->makeDbContext());

        self::assertSame('ordo_campaign_website', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
