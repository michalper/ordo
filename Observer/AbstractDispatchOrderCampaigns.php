<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;

/**
 * Shared by DispatchOrderPlacedCampaigns and DispatchOrderCanceledCampaigns - see
 * DispatchOrderPlacedCampaigns's own docblock for why this is on sales_order_save_after, not
 * sales_order_place_after.
 */
abstract class AbstractDispatchOrderCampaigns implements ObserverInterface
{
    public function __construct(
        private readonly CampaignDispatchPublisher $campaignDispatchPublisher
    ) {
    }

    abstract protected function triggerEventCode(): string;

    /**
     * True by default - overridden only by a subclass wired to an event that can genuinely fire
     * more than once for the exact same real-world occurrence (see
     * DispatchOrderPlacedCampaigns's own override and docblock for why it needs one and
     * DispatchOrderCanceledCampaigns doesn't).
     */
    protected function shouldDispatch(Order $order): bool
    {
        return true;
    }

    public function execute(EventObserver $observer): void
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getCustomerId() || !$this->shouldDispatch($order)) {
            return;
        }

        $this->campaignDispatchPublisher->publish($this->triggerEventCode(), [
            'customer_id' => (int) $order->getCustomerId(),
            'order_total' => (float) $order->getGrandTotal(),
            'order_increment_id' => $order->getIncrementId(),
            // The real order this exact send's own dispatch is for - Model\CampaignOutcomeLogger
            // ::logSent() excludes it when checking whether the customer already has a LATER
            // order to credit as the conversion, so this order can never spuriously count as its
            // own conversion.
            'order_id' => (int) $order->getEntityId(),
        ]);
    }
}
