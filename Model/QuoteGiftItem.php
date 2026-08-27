<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem as QuoteGiftItemResource;

/**
 * Internal marker row linking a quote_item to the free-gift offer it was earned from — never
 * exposed via the WebAPI directly (no Api\Data interface), only used inside
 * FreeGiftManagement to identify and remove previously-added gift items.
 *
 * @method int getQuoteId()
 * @method $this setQuoteId(int $quoteId)
 * @method int getQuoteItemId()
 * @method $this setQuoteItemId(int $quoteItemId)
 * @method int getOfferId()
 * @method $this setOfferId(int $offerId)
 * @method string getSku()
 * @method $this setSku(string $sku)
 */
class QuoteGiftItem extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(QuoteGiftItemResource::class);
    }
}
