<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface CampaignConditionSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\CampaignConditionInterface[]
     */
    public function getItems();

    /**
     * @param \Ordo\Automation\Api\Data\CampaignConditionInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
