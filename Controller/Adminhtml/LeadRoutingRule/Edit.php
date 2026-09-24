<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Model\LeadRoutingRuleFactory;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;

class Edit extends AbstractLeadRoutingRuleAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly LeadRoutingRuleFactory $leadRoutingRuleFactory,
        private readonly LeadRoutingRuleResource $leadRoutingRuleResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        $leadRoutingRule = $this->leadRoutingRuleFactory->create();

        if ($entityId) {
            $this->leadRoutingRuleResource->load($leadRoutingRule, $entityId);
            if (!$leadRoutingRule->getEntityId()) {
                $this->messageManager->addErrorMessage(__('This lead routing rule no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        $this->registry->register('ordo_lead_routing_rule', $leadRoutingRule);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::campaigns');
        $resultPage->getConfig()->getTitle()->prepend(
            $entityId ? __('Edit Lead Routing Rule "%1"', $leadRoutingRule->getName()) : __('New Lead Routing Rule')
        );

        return $resultPage;
    }
}
