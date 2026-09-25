<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Controller\Referral\Track;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ReferralManager;

/**
 * Fires on registration: reads the referral code Controller\Referral\Track stashed on this same
 * session (if the customer arrived via a referral link), resolves it to a referrer, and records
 * the signup - Model\ReferralManager::recordSignup() itself guards against self-referral and a
 * customer being referred twice. Always clears the session key afterwards, whether or not a
 * referral was actually recorded, so a stale code never leaks into a later, unrelated
 * registration on the same browser session.
 */
class RedeemReferralCode implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerSession $customerSession,
        private readonly ReferralManager $referralManager
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

        $this->referralManager->recordSignup($referrerCustomerId, (int) $customer->getId());
    }
}
