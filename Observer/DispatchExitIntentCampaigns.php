<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;

/**
 * Listens for the "ordo_visitor_exit_intent" event Controller\Track\Event fires (see docs/adr/0037
 * for why a controller fires a Magento event here rather than publishing directly - there's no
 * DI-cycle reason the way VisitorTagManager/CustomerTagManager have one, just the same decoupling
 * shape kept for consistency), and dispatches "exit_intent" campaigns. Mirrors
 * DispatchVisitorTagAddedCampaigns exactly, including the anonymous-only context shape (no
 * customer_id, even for a logged-in visitor) - see that trigger constant's own docblock.
 */
class DispatchExitIntentCampaigns extends AbstractDispatchCampaignsOnEvent
{
    protected function triggerEventCode(): string
    {
        return 'exit_intent';
    }

    protected function resolveContext(EventObserver $observer): ?array
    {
        $visitorId = $observer->getEvent()->getData('visitor_id');
        $visitorId = is_string($visitorId) ? $visitorId : '';
        if ($visitorId === '') {
            return null;
        }

        return ['visitor_id' => $visitorId];
    }
}
