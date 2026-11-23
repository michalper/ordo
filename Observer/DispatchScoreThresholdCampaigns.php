<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;

/**
 * Listens for the "ordo_customer_score_threshold_crossed" event EvaluateCustomerScoreRules
 * fires (see its class doc), and dispatches "score_threshold_crossed" campaigns. Mirrors
 * DispatchTagAddedCampaigns exactly, minus the tag param.
 */
class DispatchScoreThresholdCampaigns extends AbstractDispatchCampaignsOnEvent
{
    protected function triggerEventCode(): string
    {
        return 'score_threshold_crossed';
    }

    protected function resolveContext(EventObserver $observer): ?array
    {
        $customerId = (int) $observer->getEvent()->getData('customer_id');
        if ($customerId <= 0) {
            return null;
        }

        return ['customer_id' => $customerId];
    }
}
