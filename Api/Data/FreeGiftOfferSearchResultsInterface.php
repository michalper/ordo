<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface FreeGiftOfferSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\FreeGiftOfferInterface[]
     */
    public function getItems();

    /**
     * @param \Ordo\Automation\Api\Data\FreeGiftOfferInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
