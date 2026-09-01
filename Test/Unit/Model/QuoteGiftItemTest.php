<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\QuoteGiftItem;

class QuoteGiftItemTest extends AbstractModelTestCase
{
    public function testGettersAndSettersRoundTripViaMagicCall(): void
    {
        $model = new QuoteGiftItem($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());

        $model->setQuoteId(42);
        $model->setQuoteItemId(101);
        $model->setOfferId(1);
        $model->setSku('SKU-A');

        self::assertSame(42, $model->getQuoteId());
        self::assertSame(101, $model->getQuoteItemId());
        self::assertSame(1, $model->getOfferId());
        self::assertSame('SKU-A', $model->getSku());
    }
}
