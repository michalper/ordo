<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Gdpr;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;

/**
 * The topic-level sibling of SetConsent - toggles one (channel, topic) pair's consent for one
 * customer, always recorded with source="admin". Validates both the submitted channel and topic
 * via their own enum's tryFrom() rather than a hand-maintained allow-list, same reasoning
 * SetConsent's own docblock gives for why ConsentChannel::tryFrom() replaced its old string list.
 */
class SetTopicConsent extends AbstractGdprAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly ConsentManager $consentManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $customerId = (int) $this->getRequest()->getParam('customer_id');
        $channel = ConsentChannel::tryFrom((string) $this->getRequest()->getParam('channel'));
        $topic = ConsentTopic::tryFrom((string) $this->getRequest()->getParam('topic'));
        $consented = (bool) (int) $this->getRequest()->getParam('consented');
        $email = (string) $this->getRequest()->getParam('email');

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('*/*/index', ['email' => $email]);

        if ($customerId <= 0 || $channel === null || $topic === null) {
            $this->messageManager->addErrorMessage(__('Invalid consent update request.'));
            return $resultRedirect;
        }

        $this->consentManager->setTopicConsent($customerId, $channel, $topic, $consented, 'admin');
        $this->messageManager->addSuccessMessage(__('Consent updated.'));

        return $resultRedirect;
    }
}
