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
 * Handles the per-topic opt-in/out toggle on the storefront "Communication Preferences" page.
 * Same role as Controller\Consent\SetChannel, one level more specific - delegates to
 * ConsentPreferencesManagementInterface::setMyTopicConsent().
 */
class SetTopic extends AbstractCustomerAccountAction implements HttpPostActionInterface
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
        $topic = (string) $this->getRequest()->getParam('topic');
        $consented = (bool) $this->getRequest()->getParam('consented');

        try {
            $this->consentPreferencesManagement->setMyTopicConsent($channel, $topic, $consented);
            $this->messageManager->addSuccessMessage(__('Your preferences have been saved.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('ordo/consent/index');
    }
}
