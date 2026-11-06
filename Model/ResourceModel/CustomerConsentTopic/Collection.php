<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\CustomerConsentTopic;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\CustomerConsentTopic as CustomerConsentTopicModel;
use Ordo\Automation\Model\ResourceModel\CustomerConsentTopic as CustomerConsentTopicResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(CustomerConsentTopicModel::class, CustomerConsentTopicResource::class);
    }

    public function addCustomerAndChannelFilter(int $customerId, string $channel): self
    {
        $this->addFieldToFilter('customer_id', ['eq' => $customerId]);
        $this->addFieldToFilter('channel', ['eq' => $channel]);
        return $this;
    }

    public function addCustomerChannelAndTopicFilter(int $customerId, string $channel, string $topic): self
    {
        $this->addFieldToFilter('customer_id', ['eq' => $customerId]);
        $this->addFieldToFilter('channel', ['eq' => $channel]);
        $this->addFieldToFilter('topic', ['eq' => $topic]);
        $this->setPageSize(1);
        return $this;
    }

    /**
     * @param int[] $customerIds
     */
    public function addCustomerIdsChannelAndTopicFilter(array $customerIds, string $channel, string $topic): self
    {
        $this->addFieldToFilter('customer_id', ['in' => $customerIds]);
        $this->addFieldToFilter('channel', ['eq' => $channel]);
        $this->addFieldToFilter('topic', ['eq' => $topic]);
        return $this;
    }
}
