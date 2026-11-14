<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\LoyaltyWalletPass;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Controller\AbstractCustomerAccountAction;
use Ordo\Automation\Model\Wallet\GoogleWalletPassLinkBuilder;

/**
 * The "Add to Google Wallet" link on the storefront "My Offers" account page points here rather
 * than at a save URL built client-side - keeps the service account private key and JWT signing
 * entirely server-side, the same reasoning
 * Model\OrderApprovalManagement::buildDecisionLinks() gives for building its own approve/reject
 * links in one place instead of a template assembling a URL itself. A plain HTTP GET redirect
 * (302) to Google's own https://pay.google.com/gp/v/save/<jwt> URL, per Google's documented flow.
 */
class Google extends AbstractCustomerAccountAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        CustomerUrl $customerUrl,
        private readonly GoogleWalletPassLinkBuilder $googleWalletPassLinkBuilder
    ) {
        parent::__construct($context, $customerSession, $customerUrl);
    }

    public function execute()
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        try {
            $saveUrl = $this->googleWalletPassLinkBuilder->buildSaveUrl((int) $this->customerSession->getCustomerId());

            return $resultRedirect->setUrl($saveUrl);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $resultRedirect->setPath('ordo/offer/index');
        }
    }
}
