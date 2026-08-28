<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;

class DispatchOrderPlacedCampaigns implements ObserverInterface
{
    public function __construct(
        private readonly CampaignDispatchPublisher $campaignDispatchPublisher
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getCustomerId()) {
            return;
        }

        $this->campaignDispatchPublisher->publish('order_placed', [
            'customer_id' => (int) $order->getCustomerId(),
            'order_total' => (float) $order->getGrandTotal(),
            'order_increment_id' => $order->getIncrementId(),
        ]);
    }
}
