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
 * Handles the self-service "how often may we contact you" field on the storefront
 * "Communication Preferences" page - same role as Controller\Consent\SetChannel/SetTopic, one
 * level more specific, delegates to ConsentPreferencesManagementInterface::
 * setMyMaxMessagesPerWindow(). An empty/non-numeric submitted value clears the preference
 * (falls back to the admin-configured cap alone), same as submitting null directly.
 */
class SetFrequency extends AbstractCustomerAccountAction implements HttpPostActionInterface
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
        $rawValue = trim((string) $this->getRequest()->getParam('max_messages_per_window'));
        $maxMessagesPerWindow = $rawValue !== '' && is_numeric($rawValue) ? (int) $rawValue : null;

        try {
            $this->consentPreferencesManagement->setMyMaxMessagesPerWindow($maxMessagesPerWindow);
            $this->messageManager->addSuccessMessage(__('Your preferences have been saved.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('ordo/consent/index');
    }
}
