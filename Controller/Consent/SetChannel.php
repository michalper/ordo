<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Consent;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Api\ConsentPreferencesManagementInterface;
use Ordo\Automation\Controller\AbstractCustomerAccountAction;

/**
 * Handles the per-channel opt-in/out toggle on the storefront "Communication Preferences" page.
 * Delegates to ConsentPreferencesManagementInterface::setMyChannelConsent() - the same service
 * REST/GraphQL use - this controller just adapts that call to a POST form and flash messages.
 */
class SetChannel extends AbstractCustomerAccountAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        CustomerUrl $customerUrl,
        private readonly ConsentPreferencesManagementInterface $consentPreferencesManagement
    ) {
        parent::__construct($context, $customerSession, $customerUrl);
    }

    public function execute()
    {
        $channel = (string) $this->getRequest()->getParam('channel');
        $consented = (bool) $this->getRequest()->getParam('consented');

        try {
            $this->consentPreferencesManagement->setMyChannelConsent($channel, $consented);
            $this->messageManager->addSuccessMessage(__('Your preferences have been saved.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('ordo/consent/index');
    }
}
