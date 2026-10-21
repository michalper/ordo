<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Notification;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Ordo\Automation\Model\Notification as NotificationModel;
use Ordo\Automation\Model\ResourceModel\Notification as NotificationResource;
use Ordo\Automation\Model\ResourceModel\VisitorTargetFilterTrait;

class Collection extends AbstractCollection
{
    use VisitorTargetFilterTrait;

    protected function _construct(): void
    {
        $this->_init(NotificationModel::class, NotificationResource::class);
    }

    // read_at, not a delivered_at claim: a notification stays in addTargetFilter()'s result set
    // across every poll until explicitly dismissed, so the frontend can safely re-render the
    // same set on every page load without losing it.
    private function claimColumn(): string
    {
        return 'read_at';
    }
}
