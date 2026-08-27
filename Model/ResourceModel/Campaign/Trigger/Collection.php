<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign\Trigger;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\CampaignTrigger as CampaignTriggerModel;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger as CampaignTriggerResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CampaignTriggerModel::class, CampaignTriggerResource::class);
    }

    public function addCampaignFilter(int $campaignId): self
    {
        $this->addFieldToFilter('campaign_id', $campaignId);
        return $this;
    }

    /**
     * Every trigger row for the given event, across all campaigns — the first half of
     * CampaignDispatcher::dispatch()'s lookup: "which campaign_ids even have this trigger at
     * all", before filtering those campaigns down to the enabled ones.
     */
    public function addTriggerEventFilter(string $triggerEvent): self
    {
        $this->addFieldToFilter('trigger_event', $triggerEvent);
        return $this;
    }
}
