<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Offer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;

/**
 * Base for the "My Offers" storefront account controllers — gates every action behind an
 * authenticated customer session, following the same dispatch()-guard pattern Magento core uses
 * for other customer-account-only pages (see \Magento\Downloadable\Controller\Customer\Products).
 */
abstract class AbstractOfferAction extends Action
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
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
