<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\CustomerConsentLog;

class CustomerConsentLogTest extends AbstractDbTestCase
{
    public function testInitializesWithCustomerConsentLogTableAndEntityIdField(): void
    {
        $resource = new CustomerConsentLog($this->makeDbContext());

        self::assertSame('ordo_customer_consent_log', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
