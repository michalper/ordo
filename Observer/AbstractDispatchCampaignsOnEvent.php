<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;

/**
 * Shared by the 6 "pull some fields off a Magento event, guard, publish" observers
 * (DispatchCustomerRegisteredCampaigns, DispatchScoreThresholdCampaigns, DispatchTagAddedCampaigns,
 * DispatchVisitorTagAddedCampaigns, DispatchExitIntentCampaigns, DispatchSearchNoResultsCampaigns).
 * Each subclass only implements its own field extraction/validation - genuinely different per
 * trigger (which event data keys, which are required vs optional) - not forced into one generic
 * key-mapping config.
 */
abstract class AbstractDispatchCampaignsOnEvent implements ObserverInterface
{
    public function __construct(
        private readonly CampaignDispatchPublisher $campaignDispatchPublisher
    ) {
    }

    abstract protected function triggerEventCode(): string;

    /**
     * @return array<string, mixed>|null null skips dispatch entirely - the event didn't carry
     *     what this trigger needs.
     */
    abstract protected function resolveContext(EventObserver $observer): ?array;

    public function execute(EventObserver $observer): void
    {
        $context = $this->resolveContext($observer);
        if ($context === null) {
            return;
        }

        $this->campaignDispatchPublisher->publish($this->triggerEventCode(), $context);
    }
}
