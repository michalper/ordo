<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\CustomerTag;

class CustomerTagTest extends AbstractDbTestCase
{
    public function testInitializesWithCustomerTagTableAndEntityIdField(): void
    {
        $resource = new CustomerTag($this->makeDbContext());

        self::assertSame('ordo_customer_tag', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
