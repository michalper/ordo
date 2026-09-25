<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Model\Referral\FirstOrderChecker;
use Ordo\Automation\Model\ReferralManager;

/**
 * Fires on every order placement (same event Observer\DispatchOrderPlacedCampaigns already
 * listens on): if this is the customer's first order ever (FirstOrderChecker) and they were
 * referred by someone (Model\ReferralManager still has a pending row for them), marks that
 * referral converted and publishes `referral_converted` targeting the REFERRER (context
 * customer_id = referrer, not the customer who just ordered) - so a store's campaign can reward
 * the person who made the referral, the actual "reward-on-referral" moment.
 */
class DispatchReferralConvertedCampaigns implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly FirstOrderChecker $firstOrderChecker,
        private readonly ReferralManager $referralManager,
        private readonly CampaignDispatchPublisher $campaignDispatchPublisher
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        if (!$this->config->isReferralEnabled()) {
            return;
        }

        /** @var Order|null $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getCustomerId()) {
            return;
        }

        $customerId = (int) $order->getCustomerId();
        if (!$this->firstOrderChecker->isFirstOrder($customerId)) {
            return;
        }

        $referrerCustomerId = $this->referralManager->markConvertedAndGetReferrer($customerId);
        if ($referrerCustomerId === null) {
            return;
        }

        $this->campaignDispatchPublisher->publish(CampaignTriggerInterface::TRIGGER_REFERRAL_CONVERTED, [
            'customer_id' => $referrerCustomerId,
            'referred_customer_id' => $customerId,
            'order_id' => (int) $order->getEntityId(),
        ]);
    }
}
