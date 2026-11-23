<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;

class DispatchCustomerRegisteredCampaigns extends AbstractDispatchCampaignsOnEvent
{
    protected function triggerEventCode(): string
    {
        return 'customer_registered';
    }

    protected function resolveContext(EventObserver $observer): ?array
    {
        $customer = $observer->getEvent()->getCustomer();
        if (!$customer || !$customer->getId()) {
            return null;
        }

        return ['customer_id' => (int) $customer->getId()];
    }
}
