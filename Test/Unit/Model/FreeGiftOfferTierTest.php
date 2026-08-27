<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\FreeGiftOfferTier;

class FreeGiftOfferTierTest extends AbstractModelTestCase
{
    private function makeModel(): FreeGiftOfferTier
    {
        return new FreeGiftOfferTier($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testGettersAndSetters(): void
    {
        $tier = $this->makeModel();
        $tier->setEntityId(7);
        $tier->setOfferId(2);
        $tier->setMinSubtotal(500.0);
        $tier->setGiftSlots(2);

        self::assertSame(7, $tier->getEntityId());
        self::assertSame(2, $tier->getOfferId());
        self::assertSame(500.0, $tier->getMinSubtotal());
        self::assertSame(2, $tier->getGiftSlots());
    }
}
