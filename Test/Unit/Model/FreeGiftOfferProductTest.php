<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\FreeGiftOfferProduct;

class FreeGiftOfferProductTest extends AbstractModelTestCase
{
    private function makeModel(): FreeGiftOfferProduct
    {
        return new FreeGiftOfferProduct($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testGettersAndSetters(): void
    {
        $product = $this->makeModel();
        $product->setEntityId(9);
        $product->setOfferId(2);
        $product->setSku('SKU-1');

        self::assertSame(9, $product->getEntityId());
        self::assertSame(2, $product->getOfferId());
        self::assertSame('SKU-1', $product->getSku());
    }
}
