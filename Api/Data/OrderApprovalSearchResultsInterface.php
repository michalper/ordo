<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface OrderApprovalSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\OrderApprovalInterface[]
     */
    public function getItems();

    /**
     * @param \Ordo\Automation\Api\Data\OrderApprovalInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
