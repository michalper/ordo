<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;

/**
 * Listens for the "ordo_visitor_search_no_results" event Controller\Track\Event fires (same
 * decoupling shape Observer\DispatchExitIntentCampaigns already established - see docs/adr/0076),
 * and dispatches "search_no_results" campaigns. Unlike that sibling observer's deliberately
 * anonymous-only context, a search is meaningful for a logged-in customer too, so customer_id
 * rides along in the published context whenever the originating request was authenticated.
 */
class DispatchSearchNoResultsCampaigns extends AbstractDispatchCampaignsOnEvent
{
    protected function triggerEventCode(): string
    {
        return 'search_no_results';
    }

    protected function resolveContext(EventObserver $observer): ?array
    {
        $visitorId = $observer->getEvent()->getData('visitor_id');
        $visitorId = is_string($visitorId) ? $visitorId : '';
        if ($visitorId === '') {
            return null;
        }

        $context = ['visitor_id' => $visitorId];

        $rawCustomerId = $observer->getEvent()->getData('customer_id');
        if (is_numeric($rawCustomerId)) {
            $context['customer_id'] = (int) $rawCustomerId;
        }

        return $context;
    }
}
