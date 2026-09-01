<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\PendingPopup;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\PendingPopup as PendingPopupModel;
use Ordo\Automation\Model\ResourceModel\PendingPopup as PendingPopupResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(PendingPopupModel::class, PendingPopupResource::class);
    }

    /**
     * Every not-yet-delivered, not-yet-expired popup queued for either this customer or this
     * visitor_id — a logged-in customer whose browser also carries the visitor_id cookie from
     * before they logged in can still be targeted either way, so this checks both rather than
     * just customer_id once logged in.
     */
    public function addTargetFilter(?int $customerId, ?string $visitorId, string $now): self
    {
        $conditions = [];
        if ($customerId !== null) {
            $conditions[] = ['field' => 'customer_id', 'condition' => ['eq' => $customerId]];
        }
        if ($visitorId !== null && $visitorId !== '') {
            $conditions[] = ['field' => 'visitor_id', 'condition' => ['eq' => $visitorId]];
        }

        if ($conditions) {
            $this->addFieldToFilter(
                array_column($conditions, 'field'),
                array_column($conditions, 'condition')
            );
        }

        $this->addFieldToFilter('delivered_at', ['null' => true]);
        $this->addFieldToFilter(
            ['expires_at', 'expires_at'],
            [['null' => true], ['gt' => $now]]
        );
        $this->setOrder('entity_id', self::SORT_ORDER_ASC);

        return $this;
    }
}
