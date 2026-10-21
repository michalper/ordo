<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\PendingPopup;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\PendingPopup as PendingPopupModel;
use Ordo\Automation\Model\ResourceModel\PendingPopup as PendingPopupResource;
use Ordo\Automation\Model\ResourceModel\VisitorTargetFilterTrait;

class Collection extends AbstractCollection
{
    use VisitorTargetFilterTrait;

    protected function _construct(): void
    {
        $this->_init(PendingPopupModel::class, PendingPopupResource::class);
    }

    private function claimColumn(): string
    {
        return 'delivered_at';
    }

    /**
     * Frequency capping: has this customer/visitor already been handed a popup since $since?
     * `delivered_at >= $since` naturally excludes not-yet-delivered rows too, since comparing
     * NULL with `>=` in SQL is never true — no separate "delivered_at is not null" needed.
     */
    public function targetHasRecentlyReceivedPopup(?int $customerId, ?string $visitorId, string $since): bool
    {
        $this->addVisitorIdentityFilter($customerId, $visitorId);
        $this->addFieldToFilter('delivered_at', ['gteq' => $since]);

        return $this->getSize() > 0;
    }
}
