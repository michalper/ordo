<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\CampaignScheduledAction as CampaignScheduledActionModel;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction as CampaignScheduledActionResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CampaignScheduledActionModel::class, CampaignScheduledActionResource::class);
    }

    /**
     * Every row due to resume right now — the cron's own selection criteria: unclaimed
     * (executed_at IS NULL) and past its run_at.
     */
    public function addDueFilter(string $now): self
    {
        $this->addFieldToFilter('executed_at', ['null' => true]);
        $this->addFieldToFilter('run_at', ['lteq' => $now]);
        return $this;
    }

    /**
     * Every still-pending (unclaimed) row for a given campaign+customer — Campaign\
     * CampaignEntryGuard's existence check. Scoped to executed_at IS NULL, not just campaign_id +
     * customer_id, because a customer who has already completed a past run through this campaign
     * legitimately has old rows here with executed_at set; those must never count as "still
     * in-flight".
     */
    public function addPendingForCampaignAndCustomerFilter(int $campaignId, int $customerId): self
    {
        $this->addFieldToFilter('campaign_id', ['eq' => $campaignId]);
        $this->addFieldToFilter('customer_id', ['eq' => $customerId]);
        $this->addFieldToFilter('executed_at', ['null' => true]);
        return $this;
    }
}
