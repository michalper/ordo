<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Ordo\Automation\Model\LeadRoutingRuleFactory;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;

class Delete extends AbstractLeadRoutingRuleAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly LeadRoutingRuleFactory $leadRoutingRuleFactory,
        private readonly LeadRoutingRuleResource $leadRoutingRuleResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Missing lead routing rule id.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $leadRoutingRule = $this->leadRoutingRuleFactory->create();
            $this->leadRoutingRuleResource->load($leadRoutingRule, $entityId);
            $this->leadRoutingRuleResource->delete($leadRoutingRule);

            $this->messageManager->addSuccessMessage(__('The lead routing rule has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not delete the lead routing rule: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
