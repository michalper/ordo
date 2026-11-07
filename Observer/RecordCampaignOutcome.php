<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\CampaignOutcomeLogger;

/**
 * On sales_order_save_after, NOT sales_order_place_after like HoldOrderForApproval (this one is
 * otherwise unrelated to it) - deliberately fires later. \Magento\Sales\Model\Order::place()
 * (what sales_order_place_after fires from)
 * always runs BEFORE the order resource model's own save() persists it, for every normal
 * (non-reorder) checkout - see Magento\Quote\Model\QuoteManagement::submitQuote()'s own real
 * code, which only ever sets a fresh order's entity_id early for a reorder (reusing the quote's
 * origOrderId). A real entity_id is required here (order_id is what gets credited with the
 * conversion), so sales_order_place_after silently no-ops this for every real order, every
 * time - confirmed the hard way via this module's own AdminCampaignFunnelReflectsRealSendAndConversionTest,
 * a real dispatch trace showing entity_id genuinely null while customer_id was already correctly
 * populated. sales_order_save_after fires for every save (not just the first), which is fine
 * here: CampaignOutcomeLogger::markActed()'s own WHERE clause already excludes an already-acted
 * row, so a later re-save of the same order is a harmless no-op, not a double-credit risk.
 *
 * Closes the loop on Model\CampaignOutcomeLogger's own sent rows: if this customer was recently
 * sent a campaign message and hasn't converted yet, this order counts as their conversion.
 * First-plausible-match, not exact attribution — see CampaignOutcomeLogger::markActed().
 */
class RecordCampaignOutcome implements ObserverInterface
{
    public function __construct(
        private readonly CampaignOutcomeLogger $campaignOutcomeLogger
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getCustomerId() || !$order->getEntityId()) {
            return;
        }

        $this->campaignOutcomeLogger->markActed((int) $order->getCustomerId(), (int) $order->getEntityId());
    }
}
