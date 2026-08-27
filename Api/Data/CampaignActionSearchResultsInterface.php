<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface CampaignActionSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Ordo\Automation\Api\Data\CampaignActionInterface[]
     */
    public function getItems();

    /**
     * @param \Ordo\Automation\Api\Data\CampaignActionInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
