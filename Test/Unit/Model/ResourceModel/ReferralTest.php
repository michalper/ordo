<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\Referral;

class ReferralTest extends AbstractDbTestCase
{
    public function testInitializesWithReferralTableAndEntityIdField(): void
    {
        $resource = new Referral($this->makeDbContext());

        self::assertSame('ordo_referral', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
