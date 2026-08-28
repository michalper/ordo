<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;

/**
 * Listens for the "ordo_customer_tag_added" event CustomerTagManager fires (see its class doc
 * for why this is an event instead of a direct call), and dispatches "tag_added" campaigns.
 */
class DispatchTagAddedCampaigns implements ObserverInterface
{
    public function __construct(
        private readonly CampaignDispatchPublisher $campaignDispatchPublisher
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        $customerId = (int) $observer->getEvent()->getData('customer_id');
        $tag = (string) $observer->getEvent()->getData('tag');

        if ($customerId <= 0 || $tag === '') {
            return;
        }

        $this->campaignDispatchPublisher->publish('tag_added', [
            'customer_id' => $customerId,
            'tag' => $tag,
        ]);
    }
}
