<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface OfferSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\OfferInterface[]
     */
    public function getItems();

    /**
     * @param \Ordo\Automation\Api\Data\OfferInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
