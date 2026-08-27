<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface ReorderCycleSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\ReorderCycleInterface[]
     */
    public function getItems();

    /**
     * @param \Ordo\Automation\Api\Data\ReorderCycleInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
