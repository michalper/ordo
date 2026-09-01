<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\FreeGiftOffer;

class FreeGiftOfferTest extends AbstractDbTestCase
{
    public function testInitializesWithFreeGiftOfferTableAndEntityIdField(): void
    {
        $resource = new FreeGiftOffer($this->makeDbContext());

        self::assertSame('ordo_free_gift_offer', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
