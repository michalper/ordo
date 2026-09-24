<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Notification;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\Notification as NotificationModel;
use Ordo\Automation\Model\ResourceModel\Notification as NotificationResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(NotificationModel::class, NotificationResource::class);
    }

    /**
     * Every unread, unexpired notification queued for either this customer or this visitor_id —
     * same both-identities check as PendingPopup\Collection::addTargetFilter(), and the same
     * "NULL/gt $now" expiry shape, but WITHOUT the delivered_at claim: unlike a popup, a
     * notification stays in this result set across every poll until read_at is explicitly set
     * (Controller\Track\DismissNotification), so the frontend can safely re-render the same set
     * on every page load without losing it.
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

        if ($conditions !== []) {
            $this->addFieldToFilter(
                array_column($conditions, 'field'),
                array_column($conditions, 'condition')
            );
        }

        $this->addFieldToFilter('read_at', ['null' => true]);
        $this->addFieldToFilter(
            ['expires_at', 'expires_at'],
            [['null' => true], ['gt' => $now]]
        );
        $this->setOrder('entity_id', self::SORT_ORDER_ASC);

        return $this;
    }
}
