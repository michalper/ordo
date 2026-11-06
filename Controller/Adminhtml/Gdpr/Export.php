<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\Gdpr;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Gdpr\CustomerDataExporter;

/**
 * The data-subject-access-request half of this module's GDPR tooling — a JSON download of
 * everything this module holds about one customer (CustomerDataExporter::export()) plus their
 * current per-channel consent state (ConsentManager::getConsentStates()) and per-topic state for
 * each message-delivery channel (ConsentManager::getTopicConsentStates()), which both live in
 * their own tables but are more directly useful summarized than buried in the raw row dump.
 */
class Export extends AbstractGdprAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly RawFactory $resultRawFactory,
        private readonly CustomerDataExporter $customerDataExporter,
        private readonly ConsentManager $consentManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $customerId = (int) $this->getRequest()->getParam('customer_id');

        if ($customerId <= 0) {
            $this->messageManager->addErrorMessage(__('Invalid customer.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $topicConsentStates = [];
        foreach ([ConsentChannel::Email, ConsentChannel::Sms, ConsentChannel::Push, ConsentChannel::WhatsApp] as $channel) {
            $topicConsentStates[$channel->value] = $this->consentManager->getTopicConsentStates($customerId, $channel);
        }

        $payload = [
            'consent_states' => $this->consentManager->getConsentStates($customerId),
            'topic_consent_states' => $topicConsentStates,
            'data' => $this->customerDataExporter->export($customerId),
        ];

        $result = $this->resultRawFactory->create();
        $result->setHeader('Content-Type', 'application/json');
        $result->setHeader(
            'Content-Disposition',
            sprintf('attachment; filename="ordo-gdpr-export-customer-%d.json"', $customerId)
        );
        $result->setContents((string) json_encode($payload, JSON_PRETTY_PRINT));

        return $result;
    }
}
