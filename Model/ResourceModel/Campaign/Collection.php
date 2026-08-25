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

    public function addEnabledForTriggerFilter(string $triggerEvent): self
    {
        $this->addFieldToFilter('trigger_event', $triggerEvent);
        $this->addFieldToFilter('enabled', 1);
        return $this;
    }
}
