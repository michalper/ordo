<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\MessageSendRetry;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\MessageSendRetry as MessageSendRetryModel;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry as MessageSendRetryResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(MessageSendRetryModel::class, MessageSendRetryResource::class);
    }

    /**
     * Every row due to be retried right now and not yet exhausted - Cron\RetryFailedMessageSends'
     * own selection criteria, same shape as CampaignActionRetry\Collection::addDueFilter(). A row
     * whose attempts has already reached the configured max is excluded here rather than deleted,
     * so it stays visible as a dead letter without ever being picked up again.
     */
    public function addDueFilter(string $now, int $maxAttempts): self
    {
        $this->addFieldToFilter('next_retry_at', ['lteq' => $now]);
        $this->addFieldToFilter('attempts', ['lt' => $maxAttempts]);
        $this->setOrder('next_retry_at', self::SORT_ORDER_ASC);
        return $this;
    }
}
