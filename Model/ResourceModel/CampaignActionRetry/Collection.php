<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\CampaignActionRetry;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\CampaignActionRetry as CampaignActionRetryModel;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry as CampaignActionRetryResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CampaignActionRetryModel::class, CampaignActionRetryResource::class);
    }

    /**
     * Every row due to be retried right now and not yet exhausted - Cron\RetryFailedCampaignActions'
     * own selection criteria. A row whose attempts has already reached the configured max is
     * excluded here rather than deleted, so it stays visible as a dead letter without ever being
     * picked up again.
     */
    public function addDueFilter(string $now, int $maxAttempts): self
    {
        $this->addFieldToFilter('next_retry_at', ['lteq' => $now]);
        $this->addFieldToFilter('attempts', ['lt' => $maxAttempts]);
        $this->setOrder('next_retry_at', self::SORT_ORDER_ASC);
        return $this;
    }
}
