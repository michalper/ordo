<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\CampaignOutcomeLogger;

/**
 * Fires on the same sales_order_place_after event as HoldOrderForApproval/
 * DispatchOrderPlacedCampaigns/RecordTriggerOutcome, but is unrelated to any of them — this one
 * closes the loop on Model\CampaignOutcomeLogger's sent rows: if this customer was recently sent
 * a campaign message and hasn't converted yet, this placed order counts as their conversion.
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
