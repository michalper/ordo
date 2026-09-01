<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\FreeGiftOfferTier;

class FreeGiftOfferTierTest extends AbstractDbTestCase
{
    public function testInitializesWithFreeGiftOfferTierTableAndEntityIdField(): void
    {
        $resource = new FreeGiftOfferTier($this->makeDbContext());

        self::assertSame('ordo_free_gift_offer_tier', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
