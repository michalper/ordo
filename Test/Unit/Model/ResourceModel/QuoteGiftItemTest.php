<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Ordo\Automation\Model\ResourceModel\QuoteGiftItem;

class QuoteGiftItemTest extends AbstractDbTestCase
{
    public function testInitializesWithQuoteGiftItemTableAndEntityIdField(): void
    {
        $resource = new QuoteGiftItem($this->makeDbContext());

        self::assertSame('ordo_quote_gift_item', $resource->getMainTable());
        self::assertSame('entity_id', $resource->getIdFieldName());
    }
}
