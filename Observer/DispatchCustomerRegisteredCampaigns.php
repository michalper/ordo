<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;

class DispatchCustomerRegisteredCampaigns implements ObserverInterface
{
    public function __construct(
        private readonly CampaignDispatchPublisher $campaignDispatchPublisher
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        $customer = $observer->getEvent()->getCustomer();
        if (!$customer || !$customer->getId()) {
            return;
        }

        $this->campaignDispatchPublisher->publish('customer_registered', [
            'customer_id' => (int) $customer->getId(),
        ]);
    }
}
