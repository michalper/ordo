<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\QuoteGiftItem;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\QuoteGiftItem as QuoteGiftItemModel;
use Ordo\Automation\Model\ResourceModel\QuoteGiftItem as QuoteGiftItemResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(QuoteGiftItemModel::class, QuoteGiftItemResource::class);
    }

    public function addQuoteFilter(int $quoteId): self
    {
        $this->addFieldToFilter('quote_id', $quoteId);
        return $this;
    }
}
