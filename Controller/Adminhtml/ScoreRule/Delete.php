<?php
declare(strict_types=1);

namespace Ordo\Automation\Controller\Adminhtml\ScoreRule;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ScoreRuleFactory;

/**
 * Invoked via a plain GET link (see Ui\Component\Listing\Column\ScoreRuleActions).
 */
class Delete extends AbstractScoreRuleAction implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly ScoreRuleFactory $scoreRuleFactory,
        private readonly ScoreRuleResource $scoreRuleResource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Missing score rule id.'));
            return $resultRedirect->setPath('*/*/');
        }

        try {
            $scoreRule = $this->scoreRuleFactory->create();
            $this->scoreRuleResource->load($scoreRule, $entityId);
            $this->scoreRuleResource->delete($scoreRule);

            $this->messageManager->addSuccessMessage(__('The score rule has been deleted.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not delete the score rule: %1', $e->getMessage()));
        }

        return $resultRedirect->setPath('*/*/');
    }
}
