<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\CustomerConsentTopic;

class CustomerConsentTopicTest extends AbstractDbTestCase
{
    public function testInitializesWithCustomerConsentTopicTableAndEntityIdField(): void
    {
        $resource = new CustomerConsentTopic($this->makeDbContext());

        self::assertSame('ordo_customer_consent_topic', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
