<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\Campaign as CampaignModel;
use Ordo\Automation\Model\ResourceModel\Campaign as CampaignResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CampaignModel::class, CampaignResource::class);
    }

    public function addEnabledFilter(): self
    {
        $this->addFieldToFilter('enabled', 1);
        return $this;
    }

    /**
     * @param int[] $campaignIds
     */
    public function addIdsFilter(array $campaignIds): self
    {
        $this->addFieldToFilter('entity_id', ['in' => $campaignIds]);
        return $this;
    }
}
