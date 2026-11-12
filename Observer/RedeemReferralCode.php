<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Controller\Referral\Track;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Model\ReferralManager;

/**
 * Fires on registration: reads the referral code Controller\Referral\Track stashed on this same
 * session (if the customer arrived via a referral link), resolves it to a referrer, and records
 * the signup - Model\ReferralManager::recordSignup() itself guards against self-referral and a
 * customer being referred twice. Always clears the session key afterwards, whether or not a
 * referral was actually recorded, so a stale code never leaks into a later, unrelated
 * registration on the same browser session.
 *
 * A genuinely new signup also publishes `referral_signed_up`, targeting the REFERRED customer
 * (the opposite direction of `referral_converted`, which targets the referrer once that same
 * customer's first order completes) - the "double-sided" half of the referral program a campaign
 * can build an immediate welcome incentive on (e.g. a first-order discount), independent of
 * whether that customer ever actually orders. See docs/adr/0048.
 */
class RedeemReferralCode implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerSession $customerSession,
        private readonly ReferralManager $referralManager,
        private readonly CampaignDispatchPublisher $campaignDispatchPublisher
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        $code = $this->customerSession->getData(Track::SESSION_KEY);
        $this->customerSession->unsetData(Track::SESSION_KEY);

        if (!$this->config->isReferralEnabled() || !is_string($code) || $code === '') {
            return;
        }

        $customer = $observer->getEvent()->getCustomer();
        if (!$customer || !$customer->getId()) {
            return;
        }

        $referrerCustomerId = $this->referralManager->resolveReferrerCustomerId($code);
        if ($referrerCustomerId === null) {
            return;
        }

        $referredCustomerId = (int) $customer->getId();
        if (!$this->referralManager->recordSignup($referrerCustomerId, $referredCustomerId)) {
            return;
        }

        $this->campaignDispatchPublisher->publish(CampaignTriggerInterface::TRIGGER_REFERRAL_SIGNED_UP, [
            'customer_id' => $referredCustomerId,
            'referrer_customer_id' => $referrerCustomerId,
        ]);
    }
}
