<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ScoreRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ScoreRuleFactory;

class Edit extends AbstractScoreRuleAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Registry $registry,
        private readonly ScoreRuleFactory $scoreRuleFactory,
        private readonly ScoreRuleResource $scoreRuleResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        $scoreRule = $this->scoreRuleFactory->create();

        if ($entityId) {
            $this->scoreRuleResource->load($scoreRule, $entityId);
            if (!$scoreRule->getEntityId()) {
                $this->messageManager->addErrorMessage(__('This score rule no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }

        $this->registry->register('ordo_score_rule', $scoreRule);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ordo_Automation::campaigns');
        $resultPage->getConfig()->getTitle()->prepend(
            $entityId ? __('Edit Score Rule "%1"', $scoreRule->getAttributeCode()) : __('New Score Rule')
        );

        return $resultPage;
    }
}
