<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\TriggerOutcomeLogger;

/**
 * Fires on the same sales_order_place_after event as HoldOrderForApproval/
 * DispatchOrderPlacedCampaigns, but is unrelated to either — this one just closes the loop on
 * Model\TriggerOutcomeLogger's sent rows: if this customer was recently sent one of the 5
 * cron-driven triggers and hasn't responded yet, this placed order counts as their response.
 * First-plausible-match, not exact attribution — see TriggerOutcomeLogger::markActed().
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
