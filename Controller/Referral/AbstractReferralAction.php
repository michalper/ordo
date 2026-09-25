<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Referral;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;

/**
 * Base for the authenticated-customer referral controllers (currently just MyCode) - same
 * dispatch()-guard pattern as Controller\Offer\AbstractOfferAction.
 */
abstract class AbstractReferralAction extends Action
{
    public function __construct(
        Context $context,
        protected readonly CustomerSession $customerSession,
        private readonly CustomerUrl $customerUrl
    ) {
        parent::__construct($context);
    }

    public function dispatch(RequestInterface $request)
    {
        if (!$this->customerSession->authenticate($this->customerUrl->getLoginUrl())) {
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
        }

        return parent::dispatch($request);
    }
}
