<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\LoyaltyPointsManager;

/**
 * On sales_order_save_after, NOT sales_order_place_after - same reasoning
 * Observer\DispatchOrderPlacedCampaigns' own docblock already gives: a fresh order's entity_id is
 * genuinely unpopulated at sales_order_place_after time for every normal (non-reorder) checkout.
 * LoyaltyPointsManager::earnForOrder() is itself idempotent per order_id, since this event can
 * fire more than once across an order's lifetime (every subsequent save, not just the first).
 *
 * Points are earned on placement, not later confirmed via invoice/payment capture, and are never
 * reversed if the order is later canceled or refunded - a deliberate v1 simplification, see
 * docs/adr/0044's own Consequences.
 */
class EarnLoyaltyPointsForOrder implements ObserverInterface
{
    public function __construct(
        private readonly LoyaltyPointsManager $loyaltyPointsManager
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getCustomerId() || !$order->getEntityId()) {
            return;
        }

        $this->loyaltyPointsManager->earnForOrder(
            (int) $order->getCustomerId(),
            (int) $order->getEntityId(),
            (float) $order->getGrandTotal()
        );
    }
}
