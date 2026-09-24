<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\CollectionFactory as LeadRoutingRuleCollectionFactory;

class MassDelete extends AbstractLeadRoutingRuleAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly LeadRoutingRuleCollectionFactory $leadRoutingRuleCollectionFactory,
        private readonly LeadRoutingRuleResource $leadRoutingRuleResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $collection = $this->filter->getCollection($this->leadRoutingRuleCollectionFactory->create());

        $count = 0;
        foreach ($collection as $leadRoutingRule) {
            /** @var \Ordo\Automation\Model\LeadRoutingRule $leadRoutingRule */
            $this->leadRoutingRuleResource->delete($leadRoutingRule);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('A total of %1 lead routing rule(s) have been deleted.', $count));

        return $resultRedirect->setPath('*/*/');
    }
}
