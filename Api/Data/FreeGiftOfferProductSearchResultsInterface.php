<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface FreeGiftOfferProductSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\FreeGiftOfferProductInterface[]
     */
    public function getItems();

    /**
     * @param \Ordo\Automation\Api\Data\FreeGiftOfferProductInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
