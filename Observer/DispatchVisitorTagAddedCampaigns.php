<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;

/**
 * Listens for the "ordo_visitor_tag_added" event VisitorTagManager fires (see its class doc for
 * why this is an event instead of a direct call), and dispatches "visitor_tag_added" campaigns.
 * Mirrors DispatchTagAddedCampaigns exactly, except the published context carries "visitor_id"
 * instead of "customer_id" — this is the only trigger in the module whose context has no
 * customer_id at all, since it fires for anonymous visitors who may never log in.
 */
class DispatchVisitorTagAddedCampaigns implements ObserverInterface
{
    public function __construct(
        private readonly CampaignDispatchPublisher $campaignDispatchPublisher
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        $visitorId = (string) $observer->getEvent()->getData('visitor_id');
        $tag = (string) $observer->getEvent()->getData('tag');

        if ($visitorId === '' || $tag === '') {
            return;
        }

        $this->campaignDispatchPublisher->publish('visitor_tag_added', [
            'visitor_id' => $visitorId,
            'tag' => $tag,
        ]);
    }
}
