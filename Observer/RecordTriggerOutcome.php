<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\TriggerOutcomeLogger;

/**
 * On sales_order_save_after, NOT sales_order_place_after like HoldOrderForApproval (this one is
 * otherwise unrelated to it) - see Observer\RecordCampaignOutcome's own docblock (the identical
 * fix, same real bug, applied to both observers at once) for the full reasoning: a fresh order's
 * entity_id is genuinely
 * unpopulated at sales_order_place_after time for every normal (non-reorder) checkout, which
 * silently no-oped this every time since a real entity_id is required here (order_id is what
 * gets credited with the response) - confirmed the hard way via this module's own
 * AdminCampaignFunnelReflectsRealSendAndConversionTest.
 *
 * Closes the loop on Model\TriggerOutcomeLogger's own sent rows: if this customer was recently
 * sent one of the 5 cron-driven triggers and hasn't responded yet, this order counts as their
 * response. First-plausible-match, not exact attribution — see TriggerOutcomeLogger::markActed().
 */
class RecordTriggerOutcome implements ObserverInterface
{
    public function __construct(
        private readonly TriggerOutcomeLogger $triggerOutcomeLogger
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        /** @var Order|null $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getCustomerId() || !$order->getEntityId()) {
            return;
        }

        $this->triggerOutcomeLogger->markActed((int) $order->getCustomerId(), (int) $order->getEntityId());
    }
}
