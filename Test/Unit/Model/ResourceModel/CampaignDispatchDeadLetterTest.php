<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\CampaignDispatchDeadLetter;

class CampaignDispatchDeadLetterTest extends AbstractDbTestCase
{
    public function testInitializesWithCampaignDispatchDeadLetterTableAndEntityIdField(): void
    {
        $resource = new CampaignDispatchDeadLetter($this->makeDbContext());

        self::assertSame('ordo_campaign_dispatch_dead_letter', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
