<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;

/**
 * Shared by DispatchOrderShippedCampaigns/DispatchOrderRefundedCampaigns - same shape as
 * AbstractDispatchOrderCampaigns (shared by DispatchOrderPlacedCampaigns/
 * DispatchOrderCanceledCampaigns), but for an event whose own event object is a shipment/credit
 * memo, not the order itself - the customer/order-level fields every dispatch context carries
 * come from $subEntity->getOrder(), which both Shipment and Creditmemo expose.
 *
 * isGenuinelyNew() exists for the exact same reason DispatchOrderPlacedCampaigns's own
 * shouldDispatch() override does: sales_order_shipment_save_after/sales_order_creditmemo_save_after
 * fire on EVERY save of the same entity, not just its creation - an admin adding a tracking
 * number to an existing shipment (Order\Shipment\Track\Save) or a comment to an existing credit
 * memo (Order\Creditmemo\AddComment) resaves it, which would otherwise re-dispatch the trigger for
 * a shipment/refund that already fired. Both subclasses check the same way
 * DispatchOrderPlacedCampaigns does: getOrigData(ENTITY_ID) is still unset at this point in
 * AbstractModel::afterSave() for a genuinely new entity, never for one being resaved.
 */
abstract class AbstractDispatchOrderSubEntityCampaigns implements ObserverInterface
{
    public function __construct(
        private readonly CampaignDispatchPublisher $campaignDispatchPublisher
    ) {
    }

    abstract protected function triggerEventCode(): string;

    abstract protected function eventObjectKey(): string;

    abstract protected function isGenuinelyNew(Shipment|Creditmemo $subEntity): bool;

    /**
     * @return array<string, mixed>
     */
    abstract protected function extraContext(Shipment|Creditmemo $subEntity): array;

    public function execute(EventObserver $observer): void
    {
        /** @var Shipment|Creditmemo|null $subEntity */
        $subEntity = $observer->getEvent()->getData($this->eventObjectKey());
        if (!$subEntity || !$this->isGenuinelyNew($subEntity)) {
            return;
        }

        /** @var Order|null $order */
        $order = $subEntity->getOrder();
        if (!$order || !$order->getCustomerId()) {
            return;
        }

        $this->campaignDispatchPublisher->publish($this->triggerEventCode(), array_merge([
            'customer_id' => (int) $order->getCustomerId(),
            // Same reasoning as AbstractDispatchOrderCampaigns's own order_total: the order's
            // base_grand_total, never the shipment/refund's own total - see each subclass's
            // extraContext() for the latter under its own, separate key.
            'order_total' => (float) $order->getBaseGrandTotal(),
            'order_increment_id' => $order->getIncrementId(),
            'order_id' => (int) $order->getEntityId(),
        ], $this->extraContext($subEntity)));
    }
}
