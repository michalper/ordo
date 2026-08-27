<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface FreeGiftOfferTierSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\FreeGiftOfferTierInterface[]
     */
    public function getItems();

    /**
     * @param \Ordo\Automation\Api\Data\FreeGiftOfferTierInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
