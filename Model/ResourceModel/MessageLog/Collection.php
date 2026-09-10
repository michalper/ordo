<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\MessageLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\MessageLog as MessageLogModel;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(MessageLogModel::class, MessageLogResource::class);
    }

    public function addCustomerFilter(int $customerId): self
    {
        $this->addFieldToFilter('customer_id', ['eq' => $customerId]);

        return $this;
    }

    public function addSentSinceFilter(string $sentSince): self
    {
        $this->addFieldToFilter('sent_at', ['gteq' => $sentSince]);

        return $this;
    }

    /**
     * Excludes rows that never actually reached (or were never sent toward) the customer -
     * Model\Campaign\FrequencyCapManager uses this so an opted-out or already-suppressed "send"
     * doesn't itself count against the very cap that suppressed it.
     */
    public function addRealSendAttemptFilter(): self
    {
        $this->addFieldToFilter(
            'status',
            ['nin' => [MessageLogModel::STATUS_OPTED_OUT, MessageLogModel::STATUS_SUPPRESSED]]
        );

        return $this;
    }
}
