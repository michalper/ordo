<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface CampaignTriggerSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\CampaignTriggerInterface[]
     */
    public function getItems();

    /**
     * @param \Ordo\Automation\Api\Data\CampaignTriggerInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
