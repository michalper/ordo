<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription as PriceWatchSubscriptionModel;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription as PriceWatchSubscriptionResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(PriceWatchSubscriptionModel::class, PriceWatchSubscriptionResource::class);
    }

    public function addCustomerFilter(int $customerId): self
    {
        $this->addFieldToFilter('customer_id', ['eq' => $customerId]);
        return $this;
    }

    public function addVisitorFilter(string $visitorId): self
    {
        $this->addFieldToFilter('visitor_id', $visitorId);
        return $this;
    }

    public function addProductFilter(int $productId): self
    {
        $this->addFieldToFilter('product_id', ['eq' => $productId]);
        return $this;
    }

    public function addWatchTypeFilter(string $watchType): self
    {
        $this->addFieldToFilter('watch_type', ['eq' => $watchType]);
        return $this;
    }

    /**
     * Only rows not yet claimed by a scan cron - see PriceWatchSubscription::NOTIFIED_AT.
     */
    public function addNotNotifiedFilter(): self
    {
        $this->addFieldToFilter('notified_at', ['null' => true]);
        return $this;
    }
}
