<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\FreeGiftOffer;

class FreeGiftOfferTest extends AbstractModelTestCase
{
    private function makeModel(): FreeGiftOffer
    {
        return new FreeGiftOffer($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testGettersAndSetters(): void
    {
        $offer = $this->makeModel();
        $offer->setEntityId(3);
        $offer->setName('Summer gifts');
        $offer->setEnabled(true);

        self::assertSame(3, $offer->getEntityId());
        self::assertSame('Summer gifts', $offer->getName());
        self::assertTrue($offer->isEnabled());
    }

    public function testEntityIdIsNullWhenUnset(): void
    {
        $offer = $this->makeModel();
        self::assertNull($offer->getEntityId());
    }

    public function testCreatedAndUpdatedAtReadOnly(): void
    {
        $offer = $this->makeModel();
        $offer->setData('created_at', '2026-01-01 00:00:00');
        $offer->setData('updated_at', '2026-01-02 00:00:00');

        self::assertSame('2026-01-01 00:00:00', $offer->getCreatedAt());
        self::assertSame('2026-01-02 00:00:00', $offer->getUpdatedAt());
    }
}
