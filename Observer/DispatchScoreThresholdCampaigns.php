<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;

/**
 * Listens for the "ordo_customer_score_threshold_crossed" event EvaluateCustomerScoreRules
 * fires (see its class doc), and dispatches "score_threshold_crossed" campaigns. Mirrors
 * DispatchTagAddedCampaigns exactly, minus the tag param.
 */
class DispatchScoreThresholdCampaigns implements ObserverInterface
{
    public function __construct(
        private readonly CampaignDispatchPublisher $campaignDispatchPublisher
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        $customerId = (int) $observer->getEvent()->getData('customer_id');

        if ($customerId <= 0) {
            return;
        }

        $this->campaignDispatchPublisher->publish('score_threshold_crossed', [
            'customer_id' => $customerId,
        ]);
    }
}
