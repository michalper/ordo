<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\ReferralCode;

class ReferralCodeTest extends AbstractDbTestCase
{
    public function testInitializesWithReferralCodeTableAndEntityIdField(): void
    {
        $resource = new ReferralCode($this->makeDbContext());

        self::assertSame('ordo_referral_code', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
