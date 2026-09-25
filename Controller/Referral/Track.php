<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Referral;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ReferralManager;

/**
 * Public entry point for a referral link (e.g. https://store.example/ordo/referral/track?ref=CODE).
 * Stashes the code on the visitor's session (same session that carries through to registration,
 * unlike a cookie this module has no existing pattern for setting server-side) and redirects to
 * account creation - Observer\RedeemReferralCode reads it back at customer_register_success.
 *
 * Silently ignores an unknown code (still redirects) rather than erroring - a stale/mistyped
 * referral link should degrade to "just go to the store", not a dead end.
 */
class Track extends Action implements HttpGetActionInterface
{
    public const SESSION_KEY = 'ordo_referral_code';

    public function __construct(
        Context $context,
        private readonly RedirectFactory $redirectFactory,
        private readonly CustomerSession $customerSession,
        private readonly ReferralManager $referralManager,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();

        if (!$this->config->isReferralEnabled()) {
            return $redirect->setPath('customer/account/create');
        }

        $code = trim((string) $this->getRequest()->getParam('ref'));
        if ($code !== '' && $this->referralManager->resolveReferrerCustomerId($code) !== null) {
            $this->customerSession->setData(self::SESSION_KEY, $code);
        }

        return $redirect->setPath('customer/account/create');
    }
}
