<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\FreeGiftOfferProduct;

class FreeGiftOfferProductTest extends AbstractDbTestCase
{
    public function testInitializesWithFreeGiftOfferProductTableAndEntityIdField(): void
    {
        $resource = new FreeGiftOfferProduct($this->makeDbContext());

        self::assertSame('ordo_free_gift_offer_product', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
