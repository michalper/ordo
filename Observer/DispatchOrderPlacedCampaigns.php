<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;

/**
 * On sales_order_save_after, NOT sales_order_place_after - moved here after a real CI run showed
 * the 'order_id' published below was always 0, never the real order: a fresh order's entity_id
 * is genuinely unpopulated at sales_order_place_after time for every normal (non-reorder)
 * checkout (see Observer\RecordCampaignOutcome's own docblock, which hit the identical trap for
 * an unrelated reason first). (int) null silently became (int) 0 here instead of throwing, so
 * nothing caught it until CampaignOutcomeLogger::markActed()'s self-exclusion (keyed on this
 * exact order_id) turned out to never match anything in AdminCampaignFunnelReflectsRealSendAndConversionTest.
 */
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
            // The real order this exact send's own dispatch is for - Model\CampaignOutcomeLogger
            // ::logSent() excludes it when checking whether the customer already has a LATER
            // order to credit as the conversion, so this order can never spuriously count as its
            // own conversion.
            'order_id' => (int) $order->getEntityId(),
        ]);
    }
}
