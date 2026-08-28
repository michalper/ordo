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
}
