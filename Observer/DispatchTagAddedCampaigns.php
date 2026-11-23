<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;

/**
 * Listens for the "ordo_customer_tag_added" event CustomerTagManager fires (see its class doc
 * for why this is an event instead of a direct call), and dispatches "tag_added" campaigns.
 */
class DispatchTagAddedCampaigns extends AbstractDispatchCampaignsOnEvent
{
    protected function triggerEventCode(): string
    {
        return 'tag_added';
    }

    protected function resolveContext(EventObserver $observer): ?array
    {
        $customerId = (int) $observer->getEvent()->getData('customer_id');
        $tag = (string) $observer->getEvent()->getData('tag');
        if ($customerId <= 0 || $tag === '') {
            return null;
        }

        return ['customer_id' => $customerId, 'tag' => $tag];
    }
}
