<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\Offer;

class OfferTest extends AbstractDbTestCase
{
    public function testInitializesWithOfferTableAndEntityIdField(): void
    {
        $resource = new Offer($this->makeDbContext());

        self::assertSame('ordo_offer', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
